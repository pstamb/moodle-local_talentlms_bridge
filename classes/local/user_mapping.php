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
 * Reads and writes {local_talentlms_bridge_user} — one row per Moodle user
 * that either the REST path or the login-override admin page has touched.
 *
 * Two independent things live in that row, both keyed by userid: the REST
 * path's idempotency state (talentlmsuserid/status/lasterror, written by
 * user_exporter::sync_user()), and loginoverride — the real TalentLMS login
 * for a user whose Moodle username doesn't match it, used only by the
 * Excel/CSV bulk path (users_csv_exporter). The REST path doesn't need
 * loginoverride: it already resolves the same mismatch itself via
 * create-or-adopt-on-duplicate-email.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_mapping {
    /**
     * Load this user's mapping row, creating a blank one if none exists yet.
     *
     * @param int $userid
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return \stdClass
     */
    public static function get_or_create(int $userid, ?\moodle_database $db = null): \stdClass {
        global $DB;
        $db ??= $DB;

        $existing = $db->get_record('local_talentlms_bridge_user', ['userid' => $userid]);
        if ($existing) {
            return $existing;
        }

        $record = (object) [
            'userid' => $userid,
            'talentlmsuserid' => null,
            'status' => 'pending',
            'lasterror' => null,
            'loginoverride' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $record->id = $db->insert_record('local_talentlms_bridge_user', $record);

        return $record;
    }

    /**
     * Set (or clear, with an empty string) this user's TalentLMS login override.
     *
     * @param int $userid
     * @param string $login Empty string clears the override.
     * @param \moodle_database|null $db Defaults to the global $DB.
     */
    public static function set_login_override(int $userid, string $login, ?\moodle_database $db = null): void {
        global $DB;
        $db ??= $DB;

        $mapping = self::get_or_create($userid, $db);
        $mapping->loginoverride = $login !== '' ? $login : null;
        $mapping->timemodified = time();
        $db->update_record('local_talentlms_bridge_user', $mapping);
    }

    /**
     * All known login overrides, for a single batch lookup rather than one
     * query per exported row.
     *
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return array<int, string> Moodle userid => TalentLMS login.
     */
    public static function get_all_login_overrides(?\moodle_database $db = null): array {
        global $DB;
        $db ??= $DB;

        $records = $db->get_records_select(
            'local_talentlms_bridge_user',
            'loginoverride IS NOT NULL',
            [],
            '',
            'userid, loginoverride'
        );

        $overrides = [];
        foreach ($records as $record) {
            $overrides[(int) $record->userid] = $record->loginoverride;
        }
        return $overrides;
    }

    /**
     * Parse the login-override admin page's textarea: one "username = login"
     * pair per line. Pure string parsing, no DB access, so it's testable on
     * its own — the page itself only has to do the username -> userid
     * lookups and call set_login_override() per resolved pair.
     *
     * @param string $text
     * @return array{pairs: array<string, string>, malformed: string[]} pairs keyed by
     *         username; malformed holds the raw lines that had no "=" or a blank username.
     */
    public static function parse_override_lines(string $text): array {
        $pairs = [];
        $malformed = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, '=')) {
                $malformed[] = $line;
                continue;
            }
            [$username, $login] = explode('=', $line, 2);
            $username = trim($username);
            $login = trim($login);
            if ($username === '') {
                $malformed[] = $line;
                continue;
            }
            $pairs[$username] = $login;
        }

        return ['pairs' => $pairs, 'malformed' => $malformed];
    }

    /**
     * Render current overrides back as "username = login" lines, for
     * pre-filling the admin page's textarea so existing overrides are
     * visible and editable rather than write-only.
     *
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return string
     */
    public static function format_overrides_as_text(?\moodle_database $db = null): string {
        global $DB;
        $db ??= $DB;

        $overrides = self::get_all_login_overrides($db);
        if (!$overrides) {
            return '';
        }

        $usernames = $db->get_records_list('user', 'id', array_keys($overrides), '', 'id, username');

        $lines = [];
        foreach ($overrides as $userid => $login) {
            if (isset($usernames[$userid])) {
                $lines[] = $usernames[$userid]->username . ' = ' . $login;
            }
        }
        sort($lines);

        return implode("\n", $lines);
    }
}
