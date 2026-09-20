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
 * Tests that reconciliation converges and is idempotent.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use context_coursecat;
use local_cohortcategoryrole\local\sync;
use local_cohortcategoryrole\task\reconcile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;

/**
 * Tests that reconciliation converges and is idempotent.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reconcile::class)]
#[CoversMethod(sync::class, 'reconcile')]
final class reconcile_test extends \advanced_testcase {
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
     * Reconciliation repairs both missing and surplus assignments.
     *
     * @return void
     */
    public function test_reconcile_converges_from_a_corrupted_state(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $member->id);
        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);
        $context = context_coursecat::instance($category->id);

        // Simulate drift: the member's assignment vanished and a stranger acquired one.
        $DB->delete_records('role_assignments', [
            'userid'    => $member->id,
            'roleid'    => $roleid,
            'contextid' => $context->id,
        ]);
        role_assign($roleid, $outsider->id, $context->id, sync::COMPONENT, $mapping->id);

        $totals = sync::reconcile();

        $this->assertSame(1, $totals['mappings']);
        $this->assertSame(1, $totals['assigned']);
        $this->assertSame(1, $totals['unassigned']);
        $this->assertTrue(user_has_role_assignment($member->id, $roleid, $context->id));
        $this->assertFalse(user_has_role_assignment($outsider->id, $roleid, $context->id));
    }

    /**
     * A second run in an already correct state changes nothing.
     *
     * @return void
     */
    public function test_reconcile_is_idempotent(): void {
        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user->id);
        sync::create_mapping($cohort->id, $category->id, $roleid);

        $first = sync::reconcile();
        $this->assertSame(0, $first['assigned']);
        $this->assertSame(0, $first['unassigned']);

        $second = sync::reconcile();
        $this->assertSame(0, $second['assigned']);
        $this->assertSame(0, $second['unassigned']);
    }

    /**
     * Reconciliation releases assignments no mapping owns, and leaves everything else alone.
     *
     * This is the safety net for a deferred deletion whose task never ran. Without it, stranded
     * assignments would keep granting access with no mapping left to explain or revoke them.
     *
     * @return void
     */
    public function test_reconcile_releases_orphaned_assignments(): void {
        global $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $member = $this->getDataGenerator()->create_user();
        $stranded = $this->getDataGenerator()->create_user();
        $manual = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $member->id);
        sync::create_mapping($cohort->id, $category->id, $roleid);
        $context = context_coursecat::instance($category->id);

        // An assignment tagged with this plugin whose mapping no longer exists.
        role_assign($roleid, $stranded->id, $context->id, sync::COMPONENT, 999999);
        // And one an administrator made by hand, which must survive regardless.
        role_assign($roleid, $manual->id, $context->id);

        $this->assertSame(1, sync::count_orphans());

        $totals = sync::reconcile();

        $this->assertSame(1, $totals['orphans']);
        $this->assertSame(0, sync::count_orphans());
        $this->assertFalse(user_has_role_assignment($stranded->id, $roleid, $context->id));
        $this->assertTrue(user_has_role_assignment($member->id, $roleid, $context->id));
        $this->assertTrue(user_has_role_assignment($manual->id, $roleid, $context->id));
    }

    /**
     * The scheduled task runs the reconciliation and reports what it did.
     *
     * @return void
     */
    public function test_scheduled_task_runs(): void {
        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $user->id);
        sync::create_mapping($cohort->id, $category->id, $roleid);

        $task = new reconcile();

        ob_start();
        $task->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('reconciled 1 mapping(s)', $output);
    }
}
