<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for the synchronisation logic.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use context_coursecat;
use local_cohortcategoryrole\local\sync;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the synchronisation logic.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync::class)]
final class sync_test extends \advanced_testcase {
    /**
     * Loads the cohort API used throughout these tests.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
    }

    /**
     * A new mapping assigns the role to everyone already in the cohort.
     *
     * @return void
     */
    public function test_create_mapping_assigns_existing_members(): void {
        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user1->id);
        cohort_add_member($cohort->id, $user2->id);

        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);
        $context = context_coursecat::instance($category->id);

        $this->assertTrue(user_has_role_assignment($user1->id, $roleid, $context->id));
        $this->assertTrue(user_has_role_assignment($user2->id, $roleid, $context->id));
        $this->assertFalse(user_has_role_assignment($outsider->id, $roleid, $context->id));
        $this->assertCount(2, sync::get_assigned_userids($mapping));
    }

    /**
     * Membership changes are picked up in both directions.
     *
     * @return void
     */
    public function test_synchronise_follows_membership(): void {
        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);
        $context = context_coursecat::instance($category->id);

        cohort_add_member($cohort->id, $user->id);
        $result = sync::synchronise($mapping);

        $this->assertSame(1, $result['assigned']);
        $this->assertTrue(user_has_role_assignment($user->id, $roleid, $context->id));

        cohort_remove_member($cohort->id, $user->id);
        $result = sync::synchronise($mapping);

        $this->assertSame(1, $result['unassigned']);
        $this->assertFalse(user_has_role_assignment($user->id, $roleid, $context->id));
    }

    /**
     * Two mappings covering the same user act independently of one another.
     *
     * @return void
     */
    public function test_mappings_are_independent(): void {
        $this->resetAfterTest();

        $cohort1 = $this->getDataGenerator()->create_cohort();
        $cohort2 = $this->getDataGenerator()->create_cohort();
        $category1 = $this->getDataGenerator()->create_category();
        $category2 = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort1->id, $user->id);
        cohort_add_member($cohort2->id, $user->id);

        $mapping1 = sync::create_mapping($cohort1->id, $category1->id, $roleid);
        $mapping2 = sync::create_mapping($cohort2->id, $category2->id, $roleid);

        $context1 = context_coursecat::instance($category1->id);
        $context2 = context_coursecat::instance($category2->id);

        $this->assertTrue(user_has_role_assignment($user->id, $roleid, $context1->id));
        $this->assertTrue(user_has_role_assignment($user->id, $roleid, $context2->id));

        sync::delete_mapping((int) $mapping1->id);

        $this->assertFalse(user_has_role_assignment($user->id, $roleid, $context1->id));
        $this->assertTrue(user_has_role_assignment($user->id, $roleid, $context2->id));
        $this->assertCount(1, sync::get_assigned_userids($mapping2));
    }

    /**
     * Role assignments made by hand in the same context are never touched.
     *
     * @return void
     */
    public function test_manual_assignments_are_preserved(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $member = $this->getDataGenerator()->create_user();
        $manual = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $member->id);

        $context = context_coursecat::instance($category->id);
        role_assign($roleid, $manual->id, $context->id);

        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);

        // Reconciling must not see the manual assignment as drift.
        $result = sync::synchronise($mapping);
        $this->assertSame(0, $result['assigned']);
        $this->assertSame(0, $result['unassigned']);
        $this->assertTrue(user_has_role_assignment($manual->id, $roleid, $context->id));

        // Nor may deleting the mapping remove it.
        sync::delete_mapping((int) $mapping->id);

        $this->assertTrue(user_has_role_assignment($manual->id, $roleid, $context->id));
        $this->assertFalse(user_has_role_assignment($member->id, $roleid, $context->id));
        $this->assertSame(1, $DB->count_records('role_assignments', ['contextid' => $context->id, 'roleid' => $roleid]));
    }

    /**
     * Deleting a mapping releases the assignments it owns and the mapping row itself.
     *
     * @return void
     */
    public function test_delete_mapping_removes_everything_it_owns(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user->id);
        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);

        sync::delete_mapping((int) $mapping->id);

        $this->assertFalse($DB->record_exists(sync::TABLE, ['id' => $mapping->id]));
        $this->assertSame(0, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
    }
}
