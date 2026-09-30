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
 * Tests for the configuration report.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use context_system;
use local_cohortcategoryrole\local\diagnostics;
use local_cohortcategoryrole\local\sync;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the configuration report.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(diagnostics::class)]
final class diagnostics_test extends \advanced_testcase {
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
     * Returns the state reported for one check.
     *
     * @param string $id
     * @return string
     */
    protected function state(string $id): string {
        foreach (diagnostics::run() as $row) {
            if ($row->id === $id) {
                return $row->state;
            }
        }
        $this->fail('No check reported with id ' . $id);
    }

    /**
     * Returns the summary reported for one check.
     *
     * @param string $id
     * @return string
     */
    protected function summary(string $id): string {
        foreach (diagnostics::run() as $row) {
            if ($row->id === $id) {
                return $row->summary;
            }
        }
        $this->fail('No check reported with id ' . $id);
    }

    /**
     * Removes the default allow, which is the precondition for a healthy site.
     *
     * @return void
     */
    protected function prevent_baseline(): void {
        global $DB;

        foreach (['user', 'guest'] as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname], '*', MUST_EXIST);
            assign_capability(diagnostics::CAPABILITY, CAP_PREVENT, $role->id, context_system::instance()->id, true);
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Gives category visibility back to the roles that manage categories.
     *
     * @return void
     */
    protected function grant_visibility_to_managers(): void {
        global $DB;

        foreach (['manager', 'coursecreator', 'editingteacher'] as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname], '*', MUST_EXIST);
            assign_capability(diagnostics::CAPABILITY, CAP_ALLOW, $role->id, context_system::instance()->id, true);
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The report always returns a row per check, whatever the state.
     *
     * A report that only lists failures cannot distinguish "all clear" from "did not run".
     *
     * @return void
     */
    public function test_every_check_always_reports(): void {
        $this->resetAfterTest();

        $rows = diagnostics::run();
        $ids = array_column($rows, 'id');

        $this->assertContains('baseline', $ids);
        $this->assertContains('management', $ids);
        $this->assertContains('prohibit', $ids);
        $this->assertContains('task', $ids);
        $this->assertContains('mappedroles', $ids);
        $this->assertContains('cohortvisibility', $ids);
        $this->assertContains('cohortscope', $ids);
        $this->assertContains('orphans', $ids);
        $this->assertContains('reachability', $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    /**
     * Every check has a name and an explanation to show behind its help icon.
     *
     * These identifiers are built by concatenation at render time, so nothing else would notice
     * one going missing until the page filled with "invalid string identifier".
     *
     * @return void
     */
    public function test_every_check_has_a_name_and_help_string(): void {
        $this->resetAfterTest();

        $manager = get_string_manager();
        foreach (diagnostics::run() as $row) {
            $this->assertTrue(
                $manager->string_exists('check' . $row->id, 'local_cohortcategoryrole'),
                'Missing name string for check ' . $row->id
            );
            $this->assertTrue(
                $manager->string_exists('check' . $row->id . '_help', 'local_cohortcategoryrole'),
                'Missing help string for check ' . $row->id
            );
            $this->assertTrue(
                $manager->string_exists('state' . $row->state, 'local_cohortcategoryrole'),
                'Missing state string for ' . $row->state
            );
        }
    }

    /**
     * An untouched site reports that every category is still public.
     *
     * @return void
     */
    public function test_missing_baseline_is_reported(): void {
        $this->resetAfterTest();

        $this->assertSame(diagnostics::WARNING, $this->state('baseline'));
        $this->assertStringContainsString('Authenticated user', $this->summary('baseline'));
        $this->assertStringContainsString('Guest', $this->summary('baseline'));

        // While the default allow stands, the management roles are not affected yet.
        $this->assertSame(diagnostics::OK, $this->state('management'));
    }

    /**
     * Preventing the default on both roles clears the baseline warning.
     *
     * @return void
     */
    public function test_prevented_baseline_passes(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $this->assertSame(diagnostics::OK, $this->state('baseline'));
    }

    /**
     * A role that manages categories but cannot see them is reported.
     *
     * @return void
     */
    public function test_management_roles_losing_visibility_are_reported(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $this->assertSame(diagnostics::WARNING, $this->state('management'));
        $this->assertStringContainsString('Manager', $this->summary('management'));

        $this->grant_visibility_to_managers();

        $this->assertSame(diagnostics::OK, $this->state('management'));
    }

    /**
     * A Prohibit anywhere is an error, because it cancels every grant.
     *
     * @return void
     */
    public function test_prohibit_is_an_error(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $this->assertSame(diagnostics::OK, $this->state('prohibit'));

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'brokenrole', 'name' => 'Broken Role']);
        assign_capability(diagnostics::CAPABILITY, CAP_PROHIBIT, $roleid, context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame(diagnostics::ERROR, $this->state('prohibit'));
        $this->assertStringContainsString('Broken Role', $this->summary('prohibit'));
    }

    /**
     * A mapped role that mass-enrols its holders is an error.
     *
     * @return void
     */
    public function test_mass_enrolment_role_is_an_error(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $cohort = $this->getDataGenerator()->create_cohort(['visible' => 0]);
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role(['name' => 'Dangerous viewer']);

        assign_capability('enrol/category:synchronised', CAP_ALLOW, $roleid, context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        sync::create_mapping($cohort->id, $category->id, $roleid);

        $this->assertSame(diagnostics::ERROR, $this->state('mappedroles'));
        $this->assertStringContainsString('Dangerous viewer', $this->summary('mappedroles'));
    }

    /**
     * A visible mapped cohort is reported, because any teacher beneath it can enrol from it.
     *
     * @return void
     */
    public function test_visible_mapped_cohort_is_reported(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Loud Audience', 'visible' => 1]);

        sync::create_mapping($cohort->id, $category->id, $roleid);

        $this->assertSame(diagnostics::WARNING, $this->state('cohortvisibility'));
        $this->assertStringContainsString('Loud Audience', $this->summary('cohortvisibility'));
    }

    /**
     * A cohort granting a category outside its own area is reported as a delegation leak.
     *
     * @return void
     */
    public function test_cohort_outside_its_mapped_category_is_reported(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $owned = $this->getDataGenerator()->create_category(['name' => 'Owned Area']);
        $elsewhere = $this->getDataGenerator()->create_category(['name' => 'Someone Elses Area']);

        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Local Audience',
            'visible' => 0,
            'contextid' => \context_coursecat::instance($owned->id)->id,
        ]);

        $roleid = $this->getDataGenerator()->create_role();
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);

        sync::create_mapping($cohort->id, $elsewhere->id, $roleid);

        $this->assertSame(diagnostics::WARNING, $this->state('cohortscope'));
        $this->assertStringContainsString('Local Audience', $this->summary('cohortscope'));

        // Mapped within its own area, it is fine.
        sync::delete_mappings(['cohortid' => $cohort->id]);
        sync::create_mapping($cohort->id, $owned->id, $roleid);

        $this->assertSame(diagnostics::OK, $this->state('cohortscope'));
    }

    /**
     * A mapped subcategory under a hidden parent is reported as unbrowsable.
     *
     * @return void
     */
    public function test_unreachable_category_is_reported(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $parent = $this->getDataGenerator()->create_category(['name' => 'Hidden Parent']);
        $child = $this->getDataGenerator()->create_category([
            'name' => 'Granted Child',
            'parent' => $parent->id,
        ]);

        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort(['visible' => 0]);
        cohort_add_member($cohort->id, $user->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(diagnostics::CAPABILITY, CAP_ALLOW, $roleid, context_system::instance()->id, true);
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        accesslib_clear_all_caches_for_unit_testing();

        sync::create_mapping($cohort->id, $child->id, $roleid);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame(diagnostics::WARNING, $this->state('reachability'));
        $this->assertStringContainsString('Granted Child', $this->summary('reachability'));
    }

    /**
     * A correctly configured site reports nothing worse than a note.
     *
     * @return void
     */
    public function test_healthy_configuration_is_clean(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();
        $this->grant_visibility_to_managers();

        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort(['visible' => 0]);
        cohort_add_member($cohort->id, $user->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(diagnostics::CAPABILITY, CAP_ALLOW, $roleid, context_system::instance()->id, true);
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        accesslib_clear_all_caches_for_unit_testing();

        sync::create_mapping($cohort->id, $category->id, $roleid);

        $states = array_column(diagnostics::run(), 'state');

        $this->assertNotContains(diagnostics::ERROR, $states);
        $this->assertNotContains(diagnostics::WARNING, $states);
    }
}
