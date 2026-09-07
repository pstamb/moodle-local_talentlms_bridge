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
use local_talentlms_bridge\local\users_csv_exporter;

/**
 * Tests for users_csv_exporter: the row mapping against a real Moodle user
 * row (including the User-type column, via role_mapper), and that the
 * written file matches TalentLMS's Users-sheet template (header text,
 * upsert-friendly single row per user, atomic write).
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class users_csv_exporter_test extends \advanced_testcase {

    public function test_to_row_maps_active_learner(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'username' => 'jdoe',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane.doe@example.com',
            'suspended' => 0,
        ]);

        $row = (new users_csv_exporter())->to_row($user);

        $this->assertSame(
            ['jdoe', 'Jane', 'Doe', 'jane.doe@example.com', 'Learner-Type', 'YES', '', '', 'NO'],
            $row
        );
    }

    public function test_to_row_marks_suspended_user_inactive(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'username' => 'suspended.user',
            'firstname' => 'Sus',
            'lastname' => 'Pended',
            'email' => 'sus@example.com',
            'suspended' => 1,
        ]);

        $row = (new users_csv_exporter())->to_row($user);

        $this->assertSame('NO', $row[5]);
    }

    public function test_to_row_uses_login_override_when_set(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'moodle.username']);
        user_mapping::set_login_override($user->id, 'real.talentlms.login');

        $row = (new users_csv_exporter())->to_row($user);

        $this->assertSame('real.talentlms.login', $row[0]);
    }

    public function test_to_row_falls_back_to_username_without_override(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'no.override.here']);

        $row = (new users_csv_exporter())->to_row($user);

        $this->assertSame('no.override.here', $row[0]);
    }

    public function test_to_row_sets_user_type_from_moodle_role(): void {
        global $DB, $CFG;
        $this->resetAfterTest();

        $admin = $this->getDataGenerator()->create_user(['username' => 'siteadmin1']);
        $CFG->siteadmins = (string) $admin->id;
        $this->assertSame('SuperAdmin', (new users_csv_exporter())->to_row($admin)[4]);

        $manager = $this->getDataGenerator()->create_user(['username' => 'manager1']);
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerroleid, $manager->id, \context_system::instance()->id);
        $this->assertSame('Admin-Type', (new users_csv_exporter())->to_row($manager)[4]);

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $teacherroleid);
        $this->assertSame('Trainer-Type', (new users_csv_exporter())->to_row($teacher)[4]);
    }

    public function test_header_and_row_include_custom_profile_fields(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'department',
            'name' => 'Department',
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'checkbox',
            'shortname' => 'newsletter',
            'name' => 'Newsletter',
        ]);

        $user = $this->getDataGenerator()->create_user(['username' => 'withcustomfields']);
        profile_save_custom_fields($user->id, ['department' => 'Engineering', 'newsletter' => 1]);

        $exporter = new users_csv_exporter();
        $header = $exporter->header();
        $row = $exporter->to_row($user);

        $this->assertSame(
            array_merge(users_csv_exporter::BASE_HEADER, ['custom_field: Department', 'custom_field: Newsletter']),
            $header
        );
        $this->assertSame('Engineering', $row[array_search('custom_field: Department', $header, true)]);
        $this->assertSame('on', $row[array_search('custom_field: Newsletter', $header, true)]);
    }

    public function test_row_maps_unchecked_checkbox_field_to_off(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'checkbox',
            'shortname' => 'newsletter',
            'name' => 'Newsletter',
        ]);
        $user = $this->getDataGenerator()->create_user();
        // No profile_save_custom_fields() call: field left at its unset default.

        $exporter = new users_csv_exporter();
        $header = $exporter->header();
        $row = $exporter->to_row($user);

        $this->assertSame('off', $row[array_search('custom_field: Newsletter', $header, true)]);
    }

    public function test_available_custom_fields_lists_all_regardless_of_selection(): void {
        $this->resetAfterTest();

        $department = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'department',
            'name' => 'Department',
        ]);
        $newsletter = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'checkbox',
            'shortname' => 'newsletter',
            'name' => 'Newsletter',
        ]);

        $ids = array_map(static fn($f) => (int) $f->id, users_csv_exporter::available_custom_fields());

        $this->assertContains((int) $department->id, $ids);
        $this->assertContains((int) $newsletter->id, $ids);
    }

    public function test_set_included_custom_field_ids_narrows_header_and_row(): void {
        $this->resetAfterTest();

        $department = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'department',
            'name' => 'Department',
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'checkbox',
            'shortname' => 'newsletter',
            'name' => 'Newsletter',
        ]);

        $user = $this->getDataGenerator()->create_user();
        profile_save_custom_fields($user->id, ['department' => 'Engineering', 'newsletter' => 1]);

        $exporter = new users_csv_exporter();
        $exporter->set_included_custom_field_ids([(int) $department->id]);

        $this->assertSame(
            array_merge(users_csv_exporter::BASE_HEADER, ['custom_field: Department']),
            $exporter->header()
        );
        $this->assertSame(
            array_merge(
                [$user->username, $user->firstname, $user->lastname, $user->email, 'Learner-Type', 'YES', '', '', 'NO'],
                ['Engineering']
            ),
            $exporter->to_row($user)
        );
    }

    public function test_set_included_custom_field_ids_empty_array_excludes_all(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'department',
            'name' => 'Department',
        ]);
        $user = $this->getDataGenerator()->create_user();

        $exporter = new users_csv_exporter();
        $exporter->set_included_custom_field_ids([]);

        $this->assertSame(users_csv_exporter::BASE_HEADER, $exporter->header());
    }

    public function test_write_produces_template_header_and_one_row_per_user(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();

        $users = [
            $this->getDataGenerator()->create_user(['username' => 'user1', 'email' => 'user1@example.com']),
            $this->getDataGenerator()->create_user(['username' => 'user2', 'email' => 'user2@example.com']),
        ];

        $path = (new users_csv_exporter())->write($dir, $users);

        $this->assertSame($dir . '/' . users_csv_exporter::FILENAME, $path);
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($path . '.tmp');

        $lines = array_map('str_getcsv', file($path));
        $this->assertSame(users_csv_exporter::BASE_HEADER, $lines[0]);
        $this->assertCount(3, $lines); // Header + 2 users.
        $this->assertSame('user1', $lines[1][0]);
        $this->assertSame('user2', $lines[2][0]);
    }

    public function test_write_overwrites_previous_export(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();
        $exporter = new users_csv_exporter();

        $exporter->write($dir, [
            $this->getDataGenerator()->create_user(['username' => 'first', 'email' => 'f@example.com']),
        ]);
        $path = $exporter->write($dir, [
            $this->getDataGenerator()->create_user(['username' => 'second', 'email' => 's@example.com']),
        ]);

        $lines = array_map('str_getcsv', file($path));
        $this->assertCount(2, $lines); // Header + 1 user — no leftover from the first write.
        $this->assertSame('second', $lines[1][0]);
    }

    public function test_write_rejects_unwritable_directory(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        (new users_csv_exporter())->write('/nonexistent/path/for/talentlms/export', []);
    }

    public function test_write_batches_splits_by_max_rows_per_file(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();
        $users = [
            $this->getDataGenerator()->create_user(['username' => 'user1', 'email' => 'user1@example.com']),
            $this->getDataGenerator()->create_user(['username' => 'user2', 'email' => 'user2@example.com']),
            $this->getDataGenerator()->create_user(['username' => 'user3', 'email' => 'user3@example.com']),
        ];

        $paths = (new users_csv_exporter())->write_batches($dir, $users, 2);

        $this->assertCount(2, $paths);
        $this->assertSame($dir . '/moodle_users_export_part1.csv', $paths[0]);
        $this->assertSame($dir . '/moodle_users_export_part2.csv', $paths[1]);

        $part1 = array_map('str_getcsv', file($paths[0]));
        $this->assertCount(3, $part1); // Header + 2 users.
        $this->assertSame('user1', $part1[1][0]);
        $this->assertSame('user2', $part1[2][0]);

        $part2 = array_map('str_getcsv', file($paths[1]));
        $this->assertCount(2, $part2); // Header + 1 user.
        $this->assertSame('user3', $part2[1][0]);
    }

    public function test_write_batches_writes_one_header_only_file_for_no_users(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();
        $paths = (new users_csv_exporter())->write_batches($dir, [], 2);

        $this->assertCount(1, $paths);
        $lines = array_map('str_getcsv', file($paths[0]));
        $this->assertCount(1, $lines);
        $this->assertSame(users_csv_exporter::BASE_HEADER, $lines[0]);
    }

    public function test_write_batches_removes_stale_part_files_from_previous_run(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();
        $exporter = new users_csv_exporter();

        $users = [
            $this->getDataGenerator()->create_user(['username' => 'user1', 'email' => 'user1@example.com']),
            $this->getDataGenerator()->create_user(['username' => 'user2', 'email' => 'user2@example.com']),
            $this->getDataGenerator()->create_user(['username' => 'user3', 'email' => 'user3@example.com']),
            $this->getDataGenerator()->create_user(['username' => 'user4', 'email' => 'user4@example.com']),
        ];
        $exporter->write_batches($dir, $users, 1);
        $this->assertFileExists($dir . '/moodle_users_export_part4.csv');

        $paths = $exporter->write_batches($dir, [$users[0]], 1);

        $this->assertCount(1, $paths);
        $this->assertFileDoesNotExist($dir . '/moodle_users_export_part2.csv');
        $this->assertFileDoesNotExist($dir . '/moodle_users_export_part3.csv');
        $this->assertFileDoesNotExist($dir . '/moodle_users_export_part4.csv');
    }

    public function test_write_batches_removes_single_file_mode_leftover(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();
        $exporter = new users_csv_exporter();

        $exporter->write($dir, [
            $this->getDataGenerator()->create_user(['username' => 'user1', 'email' => 'user1@example.com']),
        ]);
        $this->assertFileExists($dir . '/' . users_csv_exporter::FILENAME);

        $exporter->write_batches($dir, [], 2);

        $this->assertFileDoesNotExist($dir . '/' . users_csv_exporter::FILENAME);
    }

    public function test_write_removes_part_files_left_by_previous_batched_run(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();
        $exporter = new users_csv_exporter();

        $exporter->write_batches($dir, [
            $this->getDataGenerator()->create_user(['username' => 'user1', 'email' => 'user1@example.com']),
            $this->getDataGenerator()->create_user(['username' => 'user2', 'email' => 'user2@example.com']),
        ], 1);
        $this->assertFileExists($dir . '/moodle_users_export_part2.csv');

        $exporter->write($dir, []);

        $this->assertFileDoesNotExist($dir . '/moodle_users_export_part1.csv');
        $this->assertFileDoesNotExist($dir . '/moodle_users_export_part2.csv');
    }
}
