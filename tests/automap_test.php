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
 * Tests for mapping cohorts from their own category context.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use context_coursecat;
use context_system;
use local_cohortcategoryrole\local\sync;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for mapping cohorts from their own category context.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync::class)]
final class automap_test extends \advanced_testcase {
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
     * Turns the feature on with a usable role.
     *
     * @return int The configured role id.
     */
    protected function enable_automap(): int {
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'autoviewer']);
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);

        set_config('automap', 1, 'local_cohortcategoryrole');
        set_config('automaprole', $roleid, 'local_cohortcategoryrole');

        return $roleid;
    }

    /**
     * Creates a cohort in the given context and returns its id.
     *
     * @param int $contextid
     * @return int
     */
    protected function create_cohort_in(int $contextid): int {
        return cohort_add_cohort((object) [
            'name' => 'Audience',
            'idnumber' => '',
            'contextid' => $contextid,
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
        ]);
    }

    /**
     * A cohort's category is read from its own context, and is null at system level.
     *
     * @return void
     */
    public function test_category_for_cohort(): void {
        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $incategory = $this->create_cohort_in(context_coursecat::instance($category->id)->id);
        $atsystem = $this->create_cohort_in(context_system::instance()->id);

        $this->assertSame((int) $category->id, sync::category_for_cohort($incategory));
        $this->assertNull(sync::category_for_cohort($atsystem));
    }

    /**
     * With the feature off, creating a cohort in a category maps nothing.
     *
     * @return void
     */
    public function test_disabled_by_default(): void {
        global $DB;

        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $this->create_cohort_in(context_coursecat::instance($category->id)->id);

        $this->assertSame(0, $DB->count_records(sync::TABLE));
    }

    /**
     * With the feature on, creating a cohort in a category maps it to that category.
     *
     * @return void
     */
    public function test_cohort_created_in_a_category_is_mapped(): void {
        global $DB;

        $this->resetAfterTest();
        $roleid = $this->enable_automap();

        $category = $this->getDataGenerator()->create_category();
        $cohortid = $this->create_cohort_in(context_coursecat::instance($category->id)->id);

        $mappings = sync::get_mappings();
        $this->assertCount(1, $mappings);

        $mapping = reset($mappings);
        $this->assertSame((int) $cohortid, (int) $mapping->cohortid);
        $this->assertSame((int) $category->id, (int) $mapping->categoryid);
        $this->assertSame($roleid, (int) $mapping->roleid);

        // And the grant is real: a member gets the role at that category.
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($cohortid, $user->id);
        sync::synchronise($mapping);

        $this->assertTrue(user_has_role_assignment(
            $user->id,
            $roleid,
            context_coursecat::instance($category->id)->id
        ));
        $this->assertSame(1, $DB->count_records(sync::TABLE));
    }

    /**
     * A system level cohort is never mapped automatically - there is no category to infer.
     *
     * @return void
     */
    public function test_system_cohort_is_not_mapped(): void {
        global $DB;

        $this->resetAfterTest();
        $this->enable_automap();

        $this->create_cohort_in(context_system::instance()->id);

        $this->assertSame(0, $DB->count_records(sync::TABLE));
    }

    /**
     * Without a configured role there is nothing to assign, so nothing happens.
     *
     * @return void
     */
    public function test_no_role_configured_maps_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('automap', 1, 'local_cohortcategoryrole');
        set_config('automaprole', 0, 'local_cohortcategoryrole');

        $category = $this->getDataGenerator()->create_category();
        $this->create_cohort_in(context_coursecat::instance($category->id)->id);

        $this->assertSame(0, $DB->count_records(sync::TABLE));
    }

    /**
     * Enabling the feature does not reach back and map cohorts that already exist.
     *
     * @return void
     */
    public function test_existing_cohorts_are_not_mapped_retroactively(): void {
        global $DB;

        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $this->create_cohort_in(context_coursecat::instance($category->id)->id);

        $this->enable_automap();

        $this->assertSame(0, $DB->count_records(sync::TABLE));
    }

    /**
     * An automatic mapping never triggers the delegation warning, by construction.
     *
     * The category comes from the cohort's own context, so the cohort can only ever grant the
     * area it already belongs to.
     *
     * @return void
     */
    public function test_automatic_mappings_are_always_in_scope(): void {
        $this->resetAfterTest();
        $this->enable_automap();

        $category = $this->getDataGenerator()->create_category(['name' => 'Owned Area']);
        $this->create_cohort_in(context_coursecat::instance($category->id)->id);

        foreach (local\diagnostics::run() as $row) {
            if ($row->id === 'cohortscope') {
                $this->assertSame(local\diagnostics::OK, $row->state);
                return;
            }
        }

        $this->fail('The cohort scope check did not report.');
    }
}
