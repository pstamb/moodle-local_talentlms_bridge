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

use local_talentlms_bridge\local\user_source;

/**
 * Tests for user_source::count_export_users(), which must agree with
 * get_export_batch() on which users are "eligible" (deleted/confirmed/guest
 * exclusions), since it's used purely as an upfront count for the export
 * summary page.
 *
 * @package    local_talentlms_bridge
 * @copyright  2026 plugindev sandbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(user_source::class)]
final class user_source_test extends \advanced_testcase {
    public function test_count_matches_batch_exclusions(): void {
        global $DB;
        $this->resetAfterTest();

        $before = user_source::count_export_users();

        $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $unconfirmed = $this->getDataGenerator()->create_user(['confirmed' => 0]);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($DB->get_record('user', ['id' => $deleted->id]));

        $count = user_source::count_export_users();

        // Two confirmed, non-deleted users added; the unconfirmed and the
        // deleted one must not count, matching get_export_batch()'s filters.
        $this->assertSame($before + 2, $count);

        $batch = user_source::get_export_batch(1000);
        $this->assertNotContains((int) $unconfirmed->id, array_map(static fn($u) => (int) $u->id, $batch));
    }

    public function test_count_excludes_guest(): void {
        $this->resetAfterTest();

        $batch = user_source::get_export_batch(1000);
        $ids = array_map(static fn($u) => (int) $u->id, $batch);

        $this->assertNotContains(1, $ids);
        // The count and the batch must agree on totals with no other users present.
        $this->assertSame(count($batch), user_source::count_export_users());
    }

    public function test_iterate_all_pages_through_small_batches(): void {
        $this->resetAfterTest();

        for ($i = 0; $i < 5; $i++) {
            $this->getDataGenerator()->create_user();
        }

        // Batch size smaller than the result set, to force more than one page.
        $seen = iterator_to_array(user_source::iterate_all(2));

        $this->assertSame(user_source::count_export_users(), count($seen));
        $ids = array_map(static fn($u) => (int) $u->id, $seen);
        $this->assertSame($ids, array_unique($ids)); // No user repeated across page boundaries.
    }
}
