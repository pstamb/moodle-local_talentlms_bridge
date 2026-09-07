<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_talentlms_bridge\local;

/**
 * Builds a TalentLMS bulk-import "Users" sheet CSV and writes it to a
 * configured directory for TalentLMS to pull over SFTP.
 *
 * Column set is wider than TalentLMS's own Import-Samples.xls sample file
 * (Login/Firstname/Lastname/Email/Active/Branch/Group/Exclude-from-emails):
 * it also sets User-type, per TalentLMS's help centre article confirming
 * this column is accepted even though the sample file omits it, plus one
 * `custom_field: <name>` column per Moodle custom user profile field (via
 * core's own user/profile/lib.php API — profile_get_custom_fields() for the
 * definitions, profile_user_record() for each user's values), per that same
 * article's "any custom user fields you might have active" column. Column
 * order otherwise follows the order that article lists them in. Branch and
 * Group are left blank for phase 1 — no Moodle cohort/category mapping has
 * been designed yet. By default every defined custom field is included;
 * export.php lets an admin uncheck specific ones, applied here via
 * set_included_custom_field_ids() (call it before header()/to_row()).
 *
 * Custom field format, confirmed live 2026-07-27 against a real "Department"
 * text field: the column name must be `custom_field: <name>` **with a space
 * after the colon** — `custom_field:Department` (no space) imports without
 * any error but silently leaves the field blank; `custom_field: Department`
 * (with space) sets it correctly. The name must also already exist as a
 * custom field on the TalentLMS side — it is not auto-created. Checkbox
 * fields are mapped to TalentLMS's documented on/off convention.
 *
 * Role mapping (which Moodle role -> which User-type string) is done via
 * role_mapper::map_bulk_user_type() — see that class for the exact mapping,
 * including a real live-testing gotcha (wrong casing imports without error
 * but silently no-ops instead of applying the type).
 *
 * TalentLMS matches existing accounts by Login, confirmed against a live
 * account 2026-07-27: a Moodle user whose username doesn't match their
 * existing TalentLMS login (e.g. Moodle's "admin" vs. a differently-named
 * pre-provisioned TalentLMS account for the same person) is treated as a
 * new user and rejected on a duplicate-email conflict rather than updated
 * — Moodle usernames and TalentLMS logins are independent namespaces that
 * won't generally line up for a pre-existing TalentLMS account. An admin
 * can record the real login for such a user via login_overrides.php; the
 * Login column here uses that override when one is set
 * (user_mapping::get_all_login_overrides(), batch-loaded once per export)
 * and falls back to the Moodle username otherwise. Re-running this export
 * is still safe for users whose Login *does* already match (upserted
 * cleanly, confirmed live), and always re-derives the full current user
 * list rather than tracking deltas itself.
 *
 * Always writes to a single fixed filename, overwriting the previous
 * export. This avoids files queueing up on the SFTP side if a previous
 * export wasn't picked up yet — TalentLMS only ever sees the latest snapshot.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class users_csv_exporter {

    /** @var string Fixed export filename, overwritten on every run. */
    const FILENAME = 'moodle_users_export.csv';

    /**
     * @var int TalentLMS's own recommended max rows per bulk-import file
     *      (help centre article, see users_csv_exporter's class docblock).
     *      Used by write_batches() and export.php's "split into files"
     *      option — write() itself always writes a single file regardless
     *      of size.
     */
    const MAX_ROWS_PER_FILE = 250;

    /** @var string[] Fixed columns, before any custom_field:<name> columns. */
    const BASE_HEADER = ['Login', 'Firstname', 'Lastname', 'Email', 'User-type', 'Active', 'Branch', 'Group', 'Exclude-from-emails'];

    /** @var \stdClass[]|null Every custom field defined on this site, lazily loaded once. */
    private ?array $allcustomfields = null;

    /** @var int[]|null Field ids to actually include; null means "all of them". */
    private ?array $includedfieldids = null;

    /** @var array<int, string>|null Moodle userid => TalentLMS login override, lazily loaded once. */
    private ?array $loginoverrides = null;

    /**
     * Restrict which custom fields header()/to_row() include, e.g. from a
     * set of checkboxes an admin picked on export.php. Call before header()
     * or to_row() — both re-derive their filtered set from this each time,
     * so it's safe to call more than once too.
     *
     * @param int[] $fieldids
     */
    public function set_included_custom_field_ids(array $fieldids): void {
        $this->includedfieldids = array_map('intval', $fieldids);
    }

    /**
     * Every custom user profile field defined on this site, regardless of
     * set_included_custom_field_ids() — for building an admin-facing "which
     * fields to include" checkbox list before an export is requested.
     *
     * @return \stdClass[]
     */
    public static function available_custom_fields(): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        return array_values(profile_get_custom_fields());
    }

    /**
     * Full header row: BASE_HEADER plus one custom_field:<name> column per
     * included Moodle custom user profile field.
     *
     * @return string[]
     */
    public function header(): array {
        $header = self::BASE_HEADER;
        foreach ($this->custom_fields() as $field) {
            // The space after the colon is not cosmetic: confirmed live
            // 2026-07-27 that 'custom_field:Department' (no space) imports
            // without error but silently fails to set the field, while
            // 'custom_field: Department' (with space) works.
            $header[] = 'custom_field: ' . $field->name;
        }
        return $header;
    }

    /**
     * Map one Moodle user row to a TalentLMS Users-sheet CSV row.
     *
     * @param \stdClass $user As returned by user_exporter::get_export_batch().
     * @return array
     */
    public function to_row(\stdClass $user): array {
        $row = [
            $this->login_overrides()[$user->id] ?? $user->username,
            $user->firstname,
            $user->lastname,
            $user->email,
            role_mapper::map_bulk_user_type($user->id),
            empty($user->suspended) ? 'YES' : 'NO',
            '', // Branch: not modelled yet.
            '', // Group: not modelled yet.
            'NO', // Exclude-from-emails.
        ];

        if ($this->custom_fields()) {
            $values = profile_user_record($user->id, false);
            foreach ($this->custom_fields() as $field) {
                $raw = $values->{$field->shortname} ?? '';
                $row[] = $field->datatype === 'checkbox' ? (empty($raw) ? 'off' : 'on') : (string) $raw;
            }
        }

        return $row;
    }

    /**
     * Write the full export for a set of users to $directory, atomically.
     *
     * @param string $directory Must already exist and be writable.
     * @param iterable<\stdClass> $users
     * @return string Full path of the written file.
     */
    public function write(string $directory, iterable $users): string {
        global $CFG;

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \moodle_exception('error:exportpathnotwritable', 'local_talentlms_bridge', '', $directory);
        }

        require_once($CFG->libdir . '/csvlib.class.php');
        $writer = new \csv_export_writer();
        $writer->add_data($this->header());
        foreach ($users as $user) {
            $writer->add_data($this->to_row($user));
        }
        $csv = $writer->print_csv_data(true);

        $target = rtrim($directory, '/') . '/' . self::FILENAME;
        $tmp = $target . '.tmp';
        file_put_contents($tmp, $csv);
        rename($tmp, $target);

        // Remove any part files left behind by a previous write_batches()
        // run in this directory, so a mode switch never leaves stale extra
        // files for TalentLMS to also pick up over SFTP.
        self::remove_stale_part_files(rtrim($directory, '/'), 0);

        return $target;
    }

    /**
     * Same as write(), but splits the output across multiple numbered files
     * (moodle_users_export_partN.csv) of at most $maxrowsperfile rows each,
     * per TalentLMS's own recommendation for large bulk imports (see class
     * docblock and MAX_ROWS_PER_FILE). Always writes at least one file, even
     * for zero users, so a caller can rely on the returned array never being
     * empty. Removes the single-file FILENAME and any extra stale part files
     * left over from a previous run in a different mode.
     *
     * @param string $directory Must already exist and be writable.
     * @param iterable<\stdClass> $users
     * @param int $maxrowsperfile
     * @return string[] Full paths of the written files, in order.
     */
    public function write_batches(string $directory, iterable $users, int $maxrowsperfile = self::MAX_ROWS_PER_FILE): array {
        global $CFG;

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \moodle_exception('error:exportpathnotwritable', 'local_talentlms_bridge', '', $directory);
        }

        require_once($CFG->libdir . '/csvlib.class.php');
        $header = $this->header();
        $directory = rtrim($directory, '/');

        $paths = [];
        $rows = [];
        $writechunk = function () use (&$rows, &$paths, $directory, $header) {
            $writer = new \csv_export_writer();
            $writer->add_data($header);
            foreach ($rows as $row) {
                $writer->add_data($row);
            }
            $target = $directory . '/' . self::part_filename(count($paths) + 1);
            $tmp = $target . '.tmp';
            file_put_contents($tmp, $writer->print_csv_data(true));
            rename($tmp, $target);
            $paths[] = $target;
            $rows = [];
        };

        foreach ($users as $user) {
            $rows[] = $this->to_row($user);
            if (count($rows) >= $maxrowsperfile) {
                $writechunk();
            }
        }
        if ($rows || !$paths) {
            $writechunk();
        }

        @unlink($directory . '/' . self::FILENAME);
        self::remove_stale_part_files($directory, count($paths));

        return $paths;
    }

    /**
     * @param int $index 1-based part number.
     * @return string
     */
    private static function part_filename(int $index): string {
        return 'moodle_users_export_part' . $index . '.csv';
    }

    /**
     * Delete any moodle_users_export_partN.csv beyond $keep, left over from
     * a previous write_batches() run that produced more parts than this one.
     *
     * @param string $directory Already rtrim()'d.
     * @param int $keep Number of part files this run actually wrote.
     */
    private static function remove_stale_part_files(string $directory, int $keep): void {
        $index = $keep + 1;
        while (is_file($directory . '/' . self::part_filename($index))) {
            @unlink($directory . '/' . self::part_filename($index));
            $index++;
        }
    }

    /**
     * @return array<int, string> Moodle userid => TalentLMS login override,
     *         for users whose Moodle username doesn't match their existing
     *         TalentLMS login (see user_mapping and login_overrides.php).
     */
    private function login_overrides(): array {
        if ($this->loginoverrides === null) {
            $this->loginoverrides = user_mapping::get_all_login_overrides();
        }
        return $this->loginoverrides;
    }

    /**
     * @return \stdClass[] Custom profile field definitions to actually export — all of them,
     *         unless set_included_custom_field_ids() narrowed that down.
     */
    private function custom_fields(): array {
        if ($this->allcustomfields === null) {
            $this->allcustomfields = self::available_custom_fields();
        }
        if ($this->includedfieldids === null) {
            return $this->allcustomfields;
        }
        return array_values(array_filter(
            $this->allcustomfields,
            fn($field) => in_array((int) $field->id, $this->includedfieldids, true)
        ));
    }
}
