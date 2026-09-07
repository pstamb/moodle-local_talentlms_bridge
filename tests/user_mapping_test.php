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

use local_talentlms_bridge\local\user_mapping;

/**
 * Tests for user_mapping — the shared {local_talentlms_bridge_user} reader/
 * writer used by both user_exporter (REST idempotency) and the login
 * override admin page (users_csv_exporter's Login column override).
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_mapping_test extends \advanced_testcase {

    public function test_get_or_create_creates_a_blank_row_once(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $first = user_mapping::get_or_create($user->id);
        $this->assertSame((int) $user->id, (int) $first->userid);
        $this->assertSame('pending', $first->status);
        $this->assertNull($first->loginoverride);
        $this->assertSame(1, $DB->count_records('local_talentlms_bridge_user', ['userid' => $user->id]));

        $second = user_mapping::get_or_create($user->id);
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, $DB->count_records('local_talentlms_bridge_user', ['userid' => $user->id]));
    }

    public function test_set_login_override_creates_row_if_needed_and_is_readable_back(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        user_mapping::set_login_override($user->id, 'real.talentlms.login');

        $mapping = user_mapping::get_or_create($user->id);
        $this->assertSame('real.talentlms.login', $mapping->loginoverride);
    }

    public function test_set_login_override_empty_string_clears_it(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        user_mapping::set_login_override($user->id, 'some.login');
        user_mapping::set_login_override($user->id, '');

        $mapping = user_mapping::get_or_create($user->id);
        $this->assertNull($mapping->loginoverride);
    }

    public function test_set_login_override_does_not_disturb_existing_rest_state(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $mapping = user_mapping::get_or_create($user->id);
        $mapping->talentlmsuserid = 555;
        $mapping->status = 'synced';
        $DB->update_record('local_talentlms_bridge_user', $mapping);

        user_mapping::set_login_override($user->id, 'real.login');

        $reloaded = $DB->get_record('local_talentlms_bridge_user', ['userid' => $user->id]);
        $this->assertSame(555, (int) $reloaded->talentlmsuserid);
        $this->assertSame('synced', $reloaded->status);
        $this->assertSame('real.login', $reloaded->loginoverride);
    }

    public function test_get_all_login_overrides_only_returns_set_ones(): void {
        $this->resetAfterTest();

        $withoverride = $this->getDataGenerator()->create_user();
        $withoutoverride = $this->getDataGenerator()->create_user();

        user_mapping::set_login_override($withoverride->id, 'their.real.login');
        user_mapping::get_or_create($withoutoverride->id); // Row exists, but no override.

        $overrides = user_mapping::get_all_login_overrides();

        $this->assertSame('their.real.login', $overrides[(int) $withoverride->id]);
        $this->assertArrayNotHasKey((int) $withoutoverride->id, $overrides);
    }
}
