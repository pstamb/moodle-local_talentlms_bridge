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
 * Maps a Moodle user's role to TalentLMS's notion of role — which comes in
 * two different vocabularies depending on which TalentLMS mechanism is used:
 *
 * - The REST API's POST/PUT /users `role` field: learner / instructor /
 *   learner-admin (per third-party docs; see talentlms_client).
 * - The bulk-import `User-type` column: SuperAdmin / Admin-Type /
 *   Trainer-Type / Learner-Type. Not in the bulk-import template TalentLMS
 *   gives you by default, but genuinely accepted — confirmed both from
 *   TalentLMS's own help centre article and live (exact casing matters,
 *   see map_bulk_user_type()).
 *
 * Moodle roles are assigned per-context (a user can be a teacher in one
 * course and nothing special in another), so there's no single "the user's
 * role" the way TalentLMS models it. This picks the highest-priority role
 * held *anywhere* on the site: site admin beats the manager role, which
 * beats teacher roles, which beat the learner default. Good enough for a
 * single site-wide export; not a substitute for per-course role handling if
 * that's ever needed.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class role_mapper {
    /** @var string[] Role shortnames one tier below site admin. */
    const ADMIN_ROLE_SHORTNAMES = ['manager'];

    /** @var string[] Role shortnames that count as an instructor. */
    const INSTRUCTOR_ROLE_SHORTNAMES = ['editingteacher', 'teacher'];

    /** @var string[] All possible map_bulk_user_type() results, in most-to-least-privileged order. */
    const BULK_USER_TYPES = ['SuperAdmin', 'Admin-Type', 'Trainer-Type', 'Learner-Type'];

    /**
     * REST API vocabulary (POST/PUT /users `role` field).
     *
     * @param int $userid
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return string One of 'learner-admin', 'instructor', 'learner'.
     */
    public static function map(int $userid, ?\moodle_database $db = null): string {
        return match (self::classify($userid, $db)) {
            'siteadmin', 'admin' => 'learner-admin',
            'instructor' => 'instructor',
            default => 'learner',
        };
    }

    /**
     * Bulk-import `User-type` column vocabulary.
     *
     * Case sensitive, confirmed live 2026-07-27: an initial attempt using
     * 'Admin-type'/'Trainer-type'/'Learner-type' (lowercase "t") imported
     * without error but silently fell back to Learner-Type for every row —
     * TalentLMS didn't reject the bad value, it just ignored it. The real
     * names, confirmed against this account's own Account & Settings >
     * User types page, capitalise "Type": SuperAdmin, Admin-Type,
     * Trainer-Type, Learner-Type.
     *
     * @param int $userid
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return string One of 'SuperAdmin', 'Admin-Type', 'Trainer-Type', 'Learner-Type'.
     */
    public static function map_bulk_user_type(int $userid, ?\moodle_database $db = null): string {
        return match (self::classify($userid, $db)) {
            'siteadmin' => 'SuperAdmin',
            'admin' => 'Admin-Type',
            'instructor' => 'Trainer-Type',
            default => 'Learner-Type',
        };
    }

    /**
     * Count how many of $users fall into each map_bulk_user_type() result —
     * for a "what's in this export" summary table, not for the export
     * itself (which maps each row independently via to_row()).
     *
     * @param iterable $users Rows with an ->id, e.g. from user_source::iterate_all().
     * @param \moodle_database|null $db Defaults to the global $DB.
     * @return array<string, int> Keyed by BULK_USER_TYPES, all four keys always present.
     */
    public static function tally_bulk_user_types(iterable $users, ?\moodle_database $db = null): array {
        $counts = array_fill_keys(self::BULK_USER_TYPES, 0);
        foreach ($users as $user) {
            $counts[self::map_bulk_user_type($user->id, $db)]++;
        }
        return $counts;
    }

    /**
     * Classifies a user into the internal tier shared by map() and map_bulk_user_type().
     *
     * @param int $userid
     * @param \moodle_database|null $db
     * @return string One of 'siteadmin', 'admin', 'instructor', 'learner'.
     */
    private static function classify(int $userid, ?\moodle_database $db): string {
        global $DB;
        $db ??= $DB;

        if (is_siteadmin($userid)) {
            return 'siteadmin';
        }

        $shortnames = self::role_shortnames_held_anywhere($userid, $db);

        if (array_intersect(self::ADMIN_ROLE_SHORTNAMES, $shortnames)) {
            return 'admin';
        }
        if (array_intersect(self::INSTRUCTOR_ROLE_SHORTNAMES, $shortnames)) {
            return 'instructor';
        }
        return 'learner';
    }

    /**
     * Looks up every role shortname $userid holds, in any context.
     *
     * @param int $userid
     * @param \moodle_database $db
     * @return string[] Distinct role shortnames assigned to this user in any context.
     */
    private static function role_shortnames_held_anywhere(int $userid, \moodle_database $db): array {
        $sql = "SELECT DISTINCT r.shortname
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = :userid";
        return array_keys($db->get_records_sql($sql, ['userid' => $userid]));
    }
}
