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
 * Tests for the event observers.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use context_coursecat;
use core_course_category;
use local_cohortcategoryrole\local\sync;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the event observers.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
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
     * Runs the queued synchronisation tasks, swallowing their progress output.
     *
     * @return void
     */
    protected function run_queued_syncs(): void {
        ob_start();
        $this->runAdhocTasks('\local_cohortcategoryrole\task\sync_mapping');
        ob_end_clean();
    }

    /**
     * Joining a cohort queues a task that grants the role once it runs.
     *
     * @return void
     */
    public function test_member_added_queues_a_sync(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        sync::create_mapping($cohort->id, $category->id, $roleid);
        $context = context_coursecat::instance($category->id);

        cohort_add_member($cohort->id, $user->id);

        // The observer must not do the work inline.
        $this->assertFalse(user_has_role_assignment($user->id, $roleid, $context->id));
        $this->assertSame(1, $DB->count_records('task_adhoc', ['component' => sync::COMPONENT]));

        $this->run_queued_syncs();

        $this->assertTrue(user_has_role_assignment($user->id, $roleid, $context->id));
    }

    /**
     * Leaving a cohort queues a task that revokes the role once it runs.
     *
     * @return void
     */
    public function test_member_removed_queues_a_sync(): void {
        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user->id);
        sync::create_mapping($cohort->id, $category->id, $roleid);
        $context = context_coursecat::instance($category->id);

        cohort_remove_member($cohort->id, $user->id);
        $this->run_queued_syncs();

        $this->assertFalse(user_has_role_assignment($user->id, $roleid, $context->id));
    }

    /**
     * Many membership changes collapse into a single queued task.
     *
     * @return void
     */
    public function test_bulk_membership_change_queues_one_task(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();

        sync::create_mapping($cohort->id, $category->id, $roleid);

        for ($i = 0; $i < 5; $i++) {
            $user = $this->getDataGenerator()->create_user();
            cohort_add_member($cohort->id, $user->id);
        }

        $this->assertSame(1, $DB->count_records('task_adhoc', ['component' => sync::COMPONENT]));

        $this->run_queued_syncs();

        $this->assertSame(5, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
    }

    /**
     * Deleting the cohort removes the mapping and everything it granted.
     *
     * @return void
     */
    public function test_cohort_deleted_removes_mapping(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user->id);
        sync::create_mapping($cohort->id, $category->id, $roleid);
        $context = context_coursecat::instance($category->id);

        cohort_delete_cohort($cohort);

        $this->assertSame(0, $DB->count_records(sync::TABLE));
        $this->assertFalse(user_has_role_assignment($user->id, $roleid, $context->id));
    }

    /**
     * Deleting the role removes the mapping that referenced it.
     *
     * @return void
     */
    public function test_role_deleted_removes_mapping(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user->id);
        sync::create_mapping($cohort->id, $category->id, $roleid);

        delete_role($roleid);

        $this->assertSame(0, $DB->count_records(sync::TABLE));
        $this->assertSame(0, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
    }

    /**
     * Deleting the category removes the mapping that pointed at it.
     *
     * @return void
     */
    public function test_category_deleted_removes_mapping(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user->id);
        sync::create_mapping($cohort->id, $category->id, $roleid);

        core_course_category::get($category->id)->delete_full(false);

        $this->assertSame(0, $DB->count_records(sync::TABLE));
        $this->assertSame(0, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
    }
}
