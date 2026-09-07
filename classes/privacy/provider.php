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

namespace local_talentlms_bridge\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for {local_talentlms_bridge_user} — one row per Moodle
 * user that either the REST sync path or the login-override admin page has
 * touched (see classes/local/user_mapping.php). System context only: the
 * table isn't scoped to a course or any other context.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Declares the fields local_talentlms_bridge_user stores and why.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection The collection with our items added.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_talentlms_bridge_user', [
            'userid' => 'privacy:metadata:local_talentlms_bridge_user:userid',
            'talentlmsuserid' => 'privacy:metadata:local_talentlms_bridge_user:talentlmsuserid',
            'status' => 'privacy:metadata:local_talentlms_bridge_user:status',
            'lasterror' => 'privacy:metadata:local_talentlms_bridge_user:lasterror',
            'loginoverride' => 'privacy:metadata:local_talentlms_bridge_user:loginoverride',
            'timecreated' => 'privacy:metadata:local_talentlms_bridge_user:timecreated',
            'timemodified' => 'privacy:metadata:local_talentlms_bridge_user:timemodified',
        ], 'privacy:metadata:local_talentlms_bridge_user');

        return $collection;
    }

    /**
     * Gets the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists('local_talentlms_bridge_user', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Gets the list of users within a specific context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_talentlms_bridge_user}', []);
    }

    /**
     * Exports all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $hassystemcontext = array_filter(
            $contextlist->get_contexts(),
            fn($context) => $context instanceof \context_system
        );
        if (!$hassystemcontext) {
            return;
        }

        $record = $DB->get_record('local_talentlms_bridge_user', ['userid' => $contextlist->get_user()->id]);
        if (!$record) {
            return;
        }

        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_talentlms_bridge')],
            (object) [
                'talentlmsuserid' => $record->talentlmsuserid,
                'status' => $record->status,
                'lasterror' => $record->lasterror,
                'loginoverride' => $record->loginoverride,
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => transform::datetime($record->timemodified),
            ]
        );
    }

    /**
     * Deletes all user data which matches the specified context.
     *
     * @param \context $context A context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('local_talentlms_bridge_user');
    }

    /**
     * Deletes all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $hassystemcontext = array_filter(
            $contextlist->get_contexts(),
            fn($context) => $context instanceof \context_system
        );
        if (!$hassystemcontext) {
            return;
        }
        self::delete_for_userids([$contextlist->get_user()->id]);
    }

    /**
     * Deletes multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        self::delete_for_userids($userlist->get_userids());
    }

    /**
     * Deletes the mapping rows for the given user ids.
     *
     * @param int[] $userids
     */
    private static function delete_for_userids(array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_talentlms_bridge_user', "userid $insql", $inparams);
    }
}
