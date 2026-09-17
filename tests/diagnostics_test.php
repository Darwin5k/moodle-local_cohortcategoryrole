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
 * Tests for the configuration checks.
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
 * Tests for the configuration checks.
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
     * Returns the concatenated messages from a check run, for substring assertions.
     *
     * @param array $results
     * @return string
     */
    protected function messages(array $results): string {
        return implode("\n", array_column($results, 'message'));
    }

    /**
     * Returns the levels present in a check run.
     *
     * @param array $results
     * @return array
     */
    protected function levels(array $results): array {
        return array_values(array_unique(array_column($results, 'level')));
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
            assign_capability(
                diagnostics::CAPABILITY,
                CAP_PREVENT,
                $role->id,
                context_system::instance()->id,
                true
            );
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * An untouched site warns that every category is still public.
     *
     * @return void
     */
    public function test_missing_baseline_is_reported(): void {
        $this->resetAfterTest();

        $results = diagnostics::run();
        $messages = $this->messages($results);

        $this->assertContains(diagnostics::WARNING, $this->levels($results));
        $this->assertStringContainsString('Authenticated user', $messages);
        $this->assertStringContainsString('Guest', $messages);
    }

    /**
     * Once both defaults are prevented, the baseline warnings go away.
     *
     * @return void
     */
    public function test_prevented_baseline_clears_the_warning(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $messages = $this->messages(diagnostics::run());

        $this->assertStringNotContainsString('still allowed', $messages);
    }

    /**
     * A Prohibit anywhere is reported as an error, because it cancels every grant.
     *
     * @return void
     */
    public function test_prohibit_is_reported_as_an_error(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'brokenrole']);
        assign_capability(
            diagnostics::CAPABILITY,
            CAP_PROHIBIT,
            $roleid,
            context_system::instance()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $results = diagnostics::run();

        $this->assertContains(diagnostics::ERROR, $this->levels($results));
        $this->assertStringContainsString('Prohibit', $this->messages($results));
    }

    /**
     * A mapped role that mass-enrols its holders is reported as an error.
     *
     * @return void
     */
    public function test_mass_enrolment_role_is_reported(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $cohort = $this->getDataGenerator()->create_cohort();
        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role(['name' => 'Dangerous viewer']);

        assign_capability(
            'enrol/category:synchronised',
            CAP_ALLOW,
            $roleid,
            context_system::instance()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        sync::create_mapping($cohort->id, $category->id, $roleid);

        $results = diagnostics::run();

        $this->assertContains(diagnostics::ERROR, $this->levels($results));
        $this->assertStringContainsString('Dangerous viewer', $this->messages($results));
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
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $user->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            diagnostics::CAPABILITY,
            CAP_ALLOW,
            $roleid,
            context_system::instance()->id,
            true
        );
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        accesslib_clear_all_caches_for_unit_testing();

        sync::create_mapping($cohort->id, $child->id, $roleid);
        accesslib_clear_all_caches_for_unit_testing();

        $messages = $this->messages(diagnostics::run());

        $this->assertStringContainsString('Granted Child', $messages);
        $this->assertStringContainsString('Hidden Parent', $messages);
    }

    /**
     * A cohort scoped to one category but mapped to another is reported as a delegation leak.
     *
     * @return void
     */
    public function test_cohort_outside_its_mapped_category_is_reported(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $owned = $this->getDataGenerator()->create_category(['name' => 'Owned Area']);
        $elsewhere = $this->getDataGenerator()->create_category(['name' => 'Someone Elses Area']);

        // A cohort that the managers of "Owned Area" can add members to.
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Local Audience',
            'contextid' => \context_coursecat::instance($owned->id)->id,
        ]);

        $roleid = $this->getDataGenerator()->create_role();
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);

        // Mapping it to a category outside that area hands them access they do not administer.
        sync::create_mapping($cohort->id, $elsewhere->id, $roleid);

        $messages = $this->messages(diagnostics::run());

        $this->assertStringContainsString('Local Audience', $messages);
        $this->assertStringContainsString('Someone Elses Area', $messages);

        // The same cohort mapped within its own area is fine.
        sync::delete_mappings(['cohortid' => $cohort->id]);
        sync::create_mapping($cohort->id, $owned->id, $roleid);

        $this->assertStringNotContainsString('Local Audience', $this->messages(diagnostics::run()));
    }

    /**
     * A correctly configured site with a reachable mapping reports nothing alarming.
     *
     * @return void
     */
    public function test_healthy_configuration_reports_no_warnings(): void {
        $this->resetAfterTest();
        $this->prevent_baseline();

        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $user->id);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            diagnostics::CAPABILITY,
            CAP_ALLOW,
            $roleid,
            context_system::instance()->id,
            true
        );
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        accesslib_clear_all_caches_for_unit_testing();

        sync::create_mapping($cohort->id, $category->id, $roleid);

        $levels = $this->levels(diagnostics::run());

        $this->assertNotContains(diagnostics::WARNING, $levels);
        $this->assertNotContains(diagnostics::ERROR, $levels);
    }
}
