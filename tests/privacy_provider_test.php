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

namespace local_talentlms_bridge;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_talentlms_bridge\local\user_mapping;
use local_talentlms_bridge\privacy\provider;

/**
 * Tests for local_talentlms_bridge's privacy provider — {local_talentlms_bridge_user}
 * rows are system-context, one per Moodle user, created via user_mapping (see that
 * class's own docblock for what the row represents).
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class privacy_provider_test extends provider_testcase {

    public function test_get_contexts_for_userid_empty_when_no_mapping_row(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $contextlist = provider::get_contexts_for_userid($user->id);

        $this->assertCount(0, $contextlist->get_contextids());
    }

    public function test_get_contexts_for_userid_returns_system_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        user_mapping::set_login_override($user->id, 'real.login');

        $contextlist = provider::get_contexts_for_userid($user->id);

        $this->assertEquals([\context_system::instance()->id], $contextlist->get_contextids());
    }

    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        user_mapping::set_login_override($user->id, 'real.login');
        $systemcontext = \context_system::instance();

        $writer = writer::with_context($systemcontext);
        $this->assertFalse($writer->has_any_data());

        $this->export_context_data_for_user($user->id, $systemcontext, 'local_talentlms_bridge');

        $data = $writer->get_data([get_string('pluginname', 'local_talentlms_bridge')]);
        $this->assertSame('real.login', $data->loginoverride);
    }

    public function test_export_user_data_skips_user_with_no_mapping_row(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();

        $this->export_context_data_for_user($user->id, $systemcontext, 'local_talentlms_bridge');

        $writer = writer::with_context($systemcontext);
        $this->assertFalse($writer->has_any_data());
    }

    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        user_mapping::set_login_override($user1->id, 'user1.login');

        $userlist = new userlist(\context_system::instance(), 'local_talentlms_bridge');
        provider::get_users_in_context($userlist);

        $this->assertEquals([$user1->id], $userlist->get_userids());
    }

    public function test_get_users_in_context_ignores_non_system_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        user_mapping::set_login_override($user->id, 'user.login');
        $coursecontext = \context_course::instance($this->getDataGenerator()->create_course()->id);

        $userlist = new userlist($coursecontext, 'local_talentlms_bridge');
        provider::get_users_in_context($userlist);

        $this->assertCount(0, $userlist);
    }

    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        user_mapping::set_login_override($user1->id, 'user1.login');
        user_mapping::set_login_override($user2->id, 'user2.login');

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertEquals(0, $DB->count_records('local_talentlms_bridge_user'));
    }

    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        user_mapping::set_login_override($user1->id, 'user1.login');
        user_mapping::set_login_override($user2->id, 'user2.login');
        $systemcontext = \context_system::instance();

        $approvedlist = new approved_contextlist($user1, 'local_talentlms_bridge', [$systemcontext->id]);
        provider::delete_data_for_user($approvedlist);

        $this->assertEquals(0, $DB->count_records('local_talentlms_bridge_user', ['userid' => $user1->id]));
        $this->assertEquals(1, $DB->count_records('local_talentlms_bridge_user', ['userid' => $user2->id]));
    }

    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();
        user_mapping::set_login_override($user1->id, 'user1.login');
        user_mapping::set_login_override($user2->id, 'user2.login');
        user_mapping::set_login_override($user3->id, 'user3.login');
        $systemcontext = \context_system::instance();

        $approvedlist = new approved_userlist($systemcontext, 'local_talentlms_bridge', [$user1->id, $user2->id]);
        provider::delete_data_for_users($approvedlist);

        $this->assertEquals(0, $DB->count_records('local_talentlms_bridge_user', ['userid' => $user1->id]));
        $this->assertEquals(0, $DB->count_records('local_talentlms_bridge_user', ['userid' => $user2->id]));
        $this->assertEquals(1, $DB->count_records('local_talentlms_bridge_user', ['userid' => $user3->id]));
    }
}
