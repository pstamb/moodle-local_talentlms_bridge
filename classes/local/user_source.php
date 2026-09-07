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
 * Reads the Moodle users eligible for export. Deliberately has no
 * dependency on talentlms_client: both the REST path (user_exporter) and
 * the CSV/file-drop path (users_csv_exporter, via cli/export_users_csv.php)
 * need this same data, but only the REST path needs the client.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_source {

    /**
     * Fetch the next batch of real, non-deleted Moodle users to consider for export.
     *
     * @param int $limit
     * @param int $afterid Only users with id > $afterid (for paging through in id order).
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return \stdClass[]
     */
    public static function get_export_batch(int $limit, int $afterid = 0, ?\moodle_database $db = null): array {
        global $DB, $CFG;
        $db ??= $DB;

        // Guest exclusion must happen in SQL, not as a PHP-side filter after
        // LIMIT: a page that happens to contain the guest would then come
        // back shorter than $limit, which iterate_all() reads as "last page"
        // and stops on — silently dropping every user after it. (id > 0 is
        // not checked separately: is_real_user()'s only other exclusion is
        // synthetic negative/zero ids, which never occur in real {user} rows.)
        $sql = "SELECT id, username, email, firstname, lastname, auth, suspended, deleted, confirmed
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.confirmed = 1
                   AND u.id > :afterid
                   AND NOT EXISTS (
                       SELECT 1 FROM {user} g
                        WHERE g.id = u.id AND g.username = 'guest' AND g.mnethostid = :mnetlocal
                   )
              ORDER BY u.id ASC";
        $params = ['afterid' => $afterid, 'mnetlocal' => $CFG->mnet_localhost_id];

        return array_values($db->get_records_sql($sql, $params, 0, $limit));
    }

    /**
     * Count how many users get_export_batch() would eventually return, without
     * paging through them in PHP. Used for an upfront "N users will be
     * exported" summary before generating a file.
     *
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return int
     */
    public static function count_export_users(?\moodle_database $db = null): int {
        global $DB, $CFG;
        $db ??= $DB;

        $sql = "SELECT COUNT(*)
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.confirmed = 1
                   AND u.id > 0
                   AND NOT EXISTS (
                       SELECT 1 FROM {user} g
                        WHERE g.id = u.id AND g.username = 'guest' AND g.mnethostid = :mnetlocal
                   )";
        return (int) $db->count_records_sql($sql, ['mnetlocal' => $CFG->mnet_localhost_id]);
    }

    /**
     * Page through every eligible user without holding them all in memory at
     * once. Shared by cli/export_users_csv.php and export.php, which both
     * need the full set rather than a single bounded batch.
     *
     * @param int $batchsize
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return \Generator<\stdClass>
     */
    public static function iterate_all(int $batchsize = 500, ?\moodle_database $db = null): \Generator {
        $afterid = 0;
        do {
            $batch = self::get_export_batch($batchsize, $afterid, $db);
            foreach ($batch as $user) {
                $afterid = $user->id;
                yield $user;
            }
        } while (count($batch) === $batchsize);
    }
}
