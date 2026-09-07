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

use local_talentlms_bridge\local\role_mapper;

/**
 * Tests for role_mapper — Moodle role -> TalentLMS role, in both of
 * TalentLMS's vocabularies (REST `role` field, bulk-import `User-type`
 * column).
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(role_mapper::class)]
final class role_mapper_test extends \advanced_testcase {
    public function test_plain_user_maps_to_learner(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame('learner', role_mapper::map($user->id));
        $this->assertSame('Learner-Type', role_mapper::map_bulk_user_type($user->id));
    }

    public function test_site_admin_maps_to_admin_tier(): void {
        global $CFG;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $CFG->siteadmins = (string) $user->id;

        $this->assertSame('learner-admin', role_mapper::map($user->id));
        $this->assertSame('SuperAdmin', role_mapper::map_bulk_user_type($user->id));
    }

    public function test_manager_role_maps_to_admin_tier(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerroleid, $user->id, \context_system::instance()->id);

        $this->assertSame('learner-admin', role_mapper::map($user->id));
        $this->assertSame('Admin-Type', role_mapper::map_bulk_user_type($user->id));
    }

    public function test_editingteacher_role_maps_to_instructor_tier(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);

        $this->assertSame('instructor', role_mapper::map($user->id));
        $this->assertSame('Trainer-Type', role_mapper::map_bulk_user_type($user->id));
    }

    public function test_tally_bulk_user_types_counts_each_type(): void {
        global $DB, $CFG;
        $this->resetAfterTest();

        $admin = $this->getDataGenerator()->create_user();
        $CFG->siteadmins = (string) $admin->id;

        $manager = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerroleid, $manager->id, \context_system::instance()->id);

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $teacherroleid);

        $learner1 = $this->getDataGenerator()->create_user();
        $learner2 = $this->getDataGenerator()->create_user();

        $counts = role_mapper::tally_bulk_user_types([$admin, $manager, $teacher, $learner1, $learner2]);

        $this->assertSame([
            'SuperAdmin' => 1,
            'Admin-Type' => 1,
            'Trainer-Type' => 1,
            'Learner-Type' => 2,
        ], $counts);
    }

    public function test_tally_bulk_user_types_returns_all_keys_even_when_empty(): void {
        $this->assertSame([
            'SuperAdmin' => 0,
            'Admin-Type' => 0,
            'Trainer-Type' => 0,
            'Learner-Type' => 0,
        ], role_mapper::tally_bulk_user_types([]));
    }
}
