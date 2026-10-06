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
use local_cohortcategoryrole\event\mapping_created;
use local_cohortcategoryrole\event\mapping_deleted;
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

    /**
     * Mappings are classified as System, Home or Cross from the cohort's current context.
     *
     * @return void
     */
    public function test_mapping_type(): void {
        $this->resetAfterTest();

        $sales = $this->getDataGenerator()->create_category(['name' => 'Sales']);
        $advanced = $this->getDataGenerator()->create_category(['name' => 'Advanced', 'parent' => $sales->id]);
        $engineering = $this->getDataGenerator()->create_category(['name' => 'Engineering']);

        $systemcohort = $this->getDataGenerator()->create_cohort([
            'contextid' => \context_system::instance()->id,
        ]);
        $salescohort = $this->getDataGenerator()->create_cohort([
            'contextid' => context_coursecat::instance($sales->id)->id,
        ]);

        // A system cohort is centrally managed, so every mapping it has is a System mapping.
        $this->assertSame(sync::TYPE_SYSTEM, sync::mapping_type($systemcohort->id, $sales->id));
        $this->assertSame(sync::TYPE_SYSTEM, sync::mapping_type($systemcohort->id, $engineering->id));

        // Its own category, and anything beneath it, is Home: the same branch, the same managers.
        $this->assertSame(sync::TYPE_HOME, sync::mapping_type($salescohort->id, $sales->id));
        $this->assertSame(sync::TYPE_HOME, sync::mapping_type($salescohort->id, $advanced->id));

        // Anywhere outside that branch is Cross.
        $this->assertSame(sync::TYPE_CROSS, sync::mapping_type($salescohort->id, $engineering->id));
    }

    /**
     * A mapping's type follows the cohort when Moodle moves it after its category is deleted.
     *
     * Deleting a category moves its cohorts to the parent context with no event, so the type must
     * be worked out from current data rather than stored.
     *
     * @return void
     */
    public function test_mapping_type_follows_a_moved_cohort(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $parent = $this->getDataGenerator()->create_category(['name' => 'Parent']);
        $child = $this->getDataGenerator()->create_category(['name' => 'Child', 'parent' => $parent->id]);
        $sibling = $this->getDataGenerator()->create_category(['name' => 'Sibling', 'parent' => $parent->id]);

        $cohort = $this->getDataGenerator()->create_cohort([
            'contextid' => context_coursecat::instance($child->id)->id,
        ]);

        $this->assertSame(sync::TYPE_CROSS, sync::mapping_type($cohort->id, $sibling->id));

        // Core moves the cohort up to the parent's context.
        \core_course_category::get($child->id)->delete_full(false);

        // Sibling now sits beneath the cohort's context, so the same pairing is Home.
        $this->assertSame(sync::TYPE_HOME, sync::mapping_type($cohort->id, $sibling->id));
    }

    /**
     * Runs queued tasks of one class, swallowing their progress output.
     *
     * @param string $classname
     * @return void
     */
    protected function run_tasks(string $classname): void {
        ob_start();
        $this->runAdhocTasks($classname);
        ob_end_clean();
    }

    /**
     * A cohort larger than the limit is granted by the task queue, not in the request.
     *
     * Each assignment costs several milliseconds, so granting a large cohort inline would run past
     * the web server's time limit and leave the grant half applied with no feedback.
     *
     * @return void
     */
    public function test_large_mapping_is_deferred(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('deferlimit', 2, 'local_cohortcategoryrole');

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();

        for ($i = 0; $i < 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            cohort_add_member($cohort->id, $user->id);
        }

        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);

        $this->assertTrue($mapping->deferred);
        $this->assertSame(0, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));

        $this->run_tasks('\local_cohortcategoryrole\task\sync_mapping');

        $this->assertSame(3, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
    }

    /**
     * A small cohort is still granted immediately, so a site without cron keeps working.
     *
     * @return void
     */
    public function test_small_mapping_is_applied_immediately(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('deferlimit', 100, 'local_cohortcategoryrole');

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);

        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);

        $this->assertFalse($mapping->deferred);
        $this->assertSame(1, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
    }

    /**
     * Deleting a large mapping stops it granting at once and releases in the background.
     *
     * @return void
     */
    public function test_large_delete_is_deferred(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('deferlimit', 100, 'local_cohortcategoryrole');

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();

        for ($i = 0; $i < 3; $i++) {
            $user = $this->getDataGenerator()->create_user();
            cohort_add_member($cohort->id, $user->id);
        }

        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);
        $this->assertSame(3, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));

        // Now make the same mapping count as large.
        set_config('deferlimit', 2, 'local_cohortcategoryrole');
        $deferred = sync::delete_mapping((int) $mapping->id);

        $this->assertTrue($deferred);

        // The mapping is gone immediately, so nothing can re-apply it.
        $this->assertFalse($DB->record_exists(sync::TABLE, ['id' => $mapping->id]));
        $this->assertSame(3, sync::count_orphans());

        $this->run_tasks('\local_cohortcategoryrole\task\release_assignments');

        $this->assertSame(0, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
        $this->assertSame(0, sync::count_orphans());
    }

    /**
     * Creating and deleting a mapping is recorded in the site log.
     *
     * Changes to who can see what need an audit trail; without events there would be no record of
     * who opened a category to an audience, or of access that vanished through a cascade.
     *
     * @return void
     */
    public function test_mapping_changes_are_logged(): void {
        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort->id, $user->id);

        $sink = $this->redirectEvents();
        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);
        $events = $sink->get_events();
        $sink->close();

        $created = end($events);
        $this->assertInstanceOf(mapping_created::class, $created);
        $this->assertSame((int) $mapping->id, (int) $created->objectid);
        $this->assertSame((int) $cohort->id, (int) $created->other['cohortid']);
        $this->assertSame((int) $category->id, (int) $created->other['categoryid']);

        $sink = $this->redirectEvents();
        sync::delete_mapping((int) $mapping->id);
        $events = $sink->get_events();
        $sink->close();

        $deleted = end($events);
        $this->assertInstanceOf(mapping_deleted::class, $deleted);
        $this->assertSame((int) $mapping->id, (int) $deleted->objectid);
        $this->assertSame(1, (int) $deleted->other['unassigned']);
    }

    /**
     * Uninstalling releases the plugin's assignments but leaves manual ones alone.
     *
     * Core's uninstall_plugin() never touches role_assignments, so without db/uninstall.php every
     * mapped user would keep their category role permanently once the plugin was removed.
     *
     * @return void
     */
    public function test_uninstall_releases_only_its_own_assignments(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $member = $this->getDataGenerator()->create_user();
        $manual = $this->getDataGenerator()->create_user();

        cohort_add_member($cohort->id, $member->id);
        $context = context_coursecat::instance($category->id);
        role_assign($roleid, $manual->id, $context->id);

        sync::create_mapping($cohort->id, $category->id, $roleid);
        $this->assertSame(1, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));

        require_once($CFG->dirroot . '/local/cohortcategoryrole/db/uninstall.php');
        xmldb_local_cohortcategoryrole_uninstall();

        $this->assertSame(0, $DB->count_records('role_assignments', ['component' => sync::COMPONENT]));
        $this->assertTrue(user_has_role_assignment($manual->id, $roleid, $context->id));
        $this->assertFalse(user_has_role_assignment($member->id, $roleid, $context->id));
    }
}
