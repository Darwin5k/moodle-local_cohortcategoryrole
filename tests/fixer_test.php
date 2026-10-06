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
 * Tests for the one-click fixes on the status report.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use context_coursecat;
use context_system;
use local_cohortcategoryrole\local\diagnostics;
use local_cohortcategoryrole\local\fixer;
use local_cohortcategoryrole\local\sync;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the one-click fixes on the status report.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(fixer::class)]
final class fixer_test extends \advanced_testcase {
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
     * Returns the state one check reports.
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
     * The baseline fix plans exactly the change it makes, warns about the roles it affects, and
     * clears its check.
     *
     * @return void
     */
    public function test_baseline_fix(): void {
        $this->resetAfterTest();

        $plan = fixer::plan('baseline');
        $this->assertCount(2, $plan['changes']);
        $this->assertStringContainsString('Guest', implode(' ', $plan['changes']));
        // Preventing the default takes visibility from roles that inherited it; the plan must say so.
        $this->assertNotEmpty($plan['notes']);
        $this->assertStringContainsString('Manager', implode(' ', $plan['notes']));

        $this->assertSame(diagnostics::WARNING, $this->state('baseline'));

        $done = fixer::apply('baseline');
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame($plan['changes'], $done);
        $this->assertSame(diagnostics::OK, $this->state('baseline'));

        // Nothing left to do: a second pass plans and changes nothing.
        $this->assertSame([], fixer::plan('baseline')['changes']);
        $this->assertSame([], fixer::apply('baseline'));
    }

    /**
     * The management fix gives category visibility back to the roles that need it.
     *
     * @return void
     */
    public function test_management_fix(): void {
        global $DB;

        $this->resetAfterTest();

        fixer::apply('baseline');
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame(diagnostics::WARNING, $this->state('management'));

        $done = fixer::apply('management');
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertNotEmpty($done);
        $this->assertSame(diagnostics::OK, $this->state('management'));

        // And it really works: a course creator can see a category again.
        $category = $this->getDataGenerator()->create_category();
        $creator = $this->getDataGenerator()->create_user();
        $creatorrole = $DB->get_field('role', 'id', ['shortname' => 'coursecreator']);
        role_assign($creatorrole, $creator->id, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability(
            diagnostics::CAPABILITY,
            context_coursecat::instance($category->id),
            $creator
        ));
    }

    /**
     * The cohort visibility fix hides mapped cohorts, and only mapped ones.
     *
     * @return void
     */
    public function test_cohort_visibility_fix(): void {
        global $DB;

        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $mapped = $this->getDataGenerator()->create_cohort(['name' => 'Mapped Audience', 'visible' => 1]);
        $unrelated = $this->getDataGenerator()->create_cohort(['name' => 'Unrelated', 'visible' => 1]);
        sync::create_mapping($mapped->id, $category->id, $roleid);

        $plan = fixer::plan('cohortvisibility');
        $this->assertCount(1, $plan['changes']);
        $this->assertStringContainsString('Mapped Audience', $plan['changes'][0]);

        fixer::apply('cohortvisibility');

        $this->assertSame(0, (int) $DB->get_field('cohort', 'visible', ['id' => $mapped->id]));
        $this->assertSame(1, (int) $DB->get_field('cohort', 'visible', ['id' => $unrelated->id]));
        $this->assertSame(diagnostics::OK, $this->state('cohortvisibility'));
    }

    /**
     * The orphan fix releases stranded assignments and leaves manual ones alone.
     *
     * @return void
     */
    public function test_orphans_fix(): void {
        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $stranded = $this->getDataGenerator()->create_user();
        $manual = $this->getDataGenerator()->create_user();
        $context = context_coursecat::instance($category->id);

        role_assign($roleid, $stranded->id, $context->id, sync::COMPONENT, 999999);
        role_assign($roleid, $manual->id, $context->id);

        $this->assertSame(diagnostics::WARNING, $this->state('orphans'));

        fixer::apply('orphans');

        $this->assertSame(diagnostics::OK, $this->state('orphans'));
        $this->assertFalse(user_has_role_assignment($stranded->id, $roleid, $context->id));
        $this->assertTrue(user_has_role_assignment($manual->id, $roleid, $context->id));
    }

    /**
     * Running reconciliation now converges every mapping, and the plan says it does not start cron.
     *
     * @return void
     */
    public function test_reconcile_fix(): void {
        global $DB;

        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $roleid = $this->getDataGenerator()->create_role();
        $cohort = $this->getDataGenerator()->create_cohort();
        $user = $this->getDataGenerator()->create_user();
        sync::create_mapping($cohort->id, $category->id, $roleid);

        // Add a member behind the plugin's back, so only a reconcile can pick it up.
        $DB->insert_record('cohort_members', (object) ['cohortid' => $cohort->id, 'userid' => $user->id,
            'timeadded' => time()]);

        $this->assertNotEmpty(fixer::plan('reconcile')['notes']);

        fixer::apply('reconcile');

        $this->assertTrue(user_has_role_assignment($user->id, $roleid, context_coursecat::instance($category->id)->id));
    }

    /**
     * Capability fixes need the core permission, not just this plugin's.
     *
     * @return void
     */
    public function test_fixes_require_core_permissions(): void {
        $this->resetAfterTest();

        $this->assertSame('moodle/role:manage', fixer::required_capability('baseline'));
        $this->assertSame('moodle/role:manage', fixer::required_capability('management'));
        $this->assertSame('moodle/cohort:manage', fixer::required_capability('cohortvisibility'));
        $this->assertNull(fixer::required_capability('orphans'));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(fixer::can_apply('baseline'));
        $this->assertFalse(fixer::can_apply('cohortvisibility'));
        $this->assertFalse(fixer::can_apply('nonsense'));

        $this->setAdminUser();
        $this->assertTrue(fixer::can_apply('baseline'));
    }
}
