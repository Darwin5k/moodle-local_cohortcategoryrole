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
 * Tests the category visibility the plugin exists to produce.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use cache_helper;
use context_coursecat;
use context_system;
use core_course_category;
use local_cohortcategoryrole\local\sync;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the category visibility the plugin exists to produce.
 *
 * These cover the behaviour the plugin is bought for rather than its internals: with the default
 * allow removed, a mapping must make exactly one category visible and no others.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(sync::class)]
final class visibility_test extends \advanced_testcase {
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
     * Drops the caches that would otherwise hide a permission or category change.
     *
     * @return void
     */
    protected function purge_access_caches(): void {
        accesslib_clear_all_caches_for_unit_testing();
        cache_helper::purge_by_event('changesincoursecat');
    }

    /**
     * Removes the site wide allow that makes every category public by default.
     *
     * @return void
     */
    protected function prevent_default_category_visibility(): void {
        global $DB;

        $authenticateduser = $DB->get_record('role', ['shortname' => 'user'], '*', MUST_EXIST);
        assign_capability(
            'moodle/category:viewcourselist',
            CAP_PREVENT,
            $authenticateduser->id,
            context_system::instance()->id,
            true
        );
        $this->purge_access_caches();
    }

    /**
     * Creates a role that grants category visibility and can be assigned in a category.
     *
     * @return int The role id.
     */
    protected function create_viewer_role(): int {
        $roleid = $this->getDataGenerator()->create_role([
            'name'      => 'Category viewer',
            'shortname' => 'categoryviewer',
        ]);
        assign_capability(
            'moodle/category:viewcourselist',
            CAP_ALLOW,
            $roleid,
            context_system::instance()->id,
            true
        );
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $this->purge_access_caches();

        return $roleid;
    }

    /**
     * A mapping reveals its own category and leaves every other category hidden.
     *
     * This is the whole point of the plugin, and it depends on how Moodle resolves a capability:
     * the deepest override wins within a role, and the results are then combined across the roles
     * a user holds. The viewer role's allow therefore survives the prevent on the authenticated
     * user role, even though the prevent sits at the same context.
     *
     * @return void
     */
    public function test_mapping_reveals_only_the_mapped_category(): void {
        $this->resetAfterTest();

        $granted = $this->getDataGenerator()->create_category();
        $withheld = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $user->id);

        $grantedcontext = context_coursecat::instance($granted->id);
        $withheldcontext = context_coursecat::instance($withheld->id);

        // Out of the box every authenticated user can see every category.
        $this->assertTrue(has_capability('moodle/category:viewcourselist', $grantedcontext, $user));

        // Removing that default is what makes a mapping meaningful.
        $this->prevent_default_category_visibility();

        $this->assertFalse(has_capability('moodle/category:viewcourselist', $grantedcontext, $user));
        $this->assertFalse(has_capability('moodle/category:viewcourselist', $withheldcontext, $user));

        $roleid = $this->create_viewer_role();
        sync::create_mapping($cohort->id, $granted->id, $roleid);
        $this->purge_access_caches();

        $this->assertTrue(has_capability('moodle/category:viewcourselist', $grantedcontext, $user));
        $this->assertFalse(has_capability('moodle/category:viewcourselist', $withheldcontext, $user));

        $this->setUser($user);
        $this->assertTrue(core_course_category::can_view_category($granted));
        $this->assertFalse(core_course_category::can_view_category($withheld));
    }

    /**
     * Leaving the cohort takes the visibility away again.
     *
     * @return void
     */
    public function test_leaving_the_cohort_hides_the_category_again(): void {
        $this->resetAfterTest();

        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $user->id);

        $this->prevent_default_category_visibility();
        $roleid = $this->create_viewer_role();

        $mapping = sync::create_mapping($cohort->id, $category->id, $roleid);
        $this->purge_access_caches();

        $context = context_coursecat::instance($category->id);
        $this->assertTrue(has_capability('moodle/category:viewcourselist', $context, $user));

        cohort_remove_member($cohort->id, $user->id);
        sync::synchronise($mapping);
        $this->purge_access_caches();

        $this->assertFalse(has_capability('moodle/category:viewcourselist', $context, $user));
    }

    /**
     * A mapped subcategory under a hidden parent is not reachable by browsing.
     *
     * Category listings are built from the top down and only descend through categories the user
     * can see, so the grant is real but the route to it is not. This is documented behaviour
     * rather than a defect, and the reason mappings should name the category users browse from.
     *
     * @return void
     */
    public function test_hidden_parent_hides_a_granted_subcategory_from_browsing(): void {
        $this->resetAfterTest();

        $parent = $this->getDataGenerator()->create_category();
        $child = $this->getDataGenerator()->create_category(['parent' => $parent->id]);
        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $user->id);

        $this->prevent_default_category_visibility();
        $roleid = $this->create_viewer_role();

        sync::create_mapping($cohort->id, $child->id, $roleid);
        $this->purge_access_caches();

        $this->setUser($user);

        // The capability is genuinely granted on the child.
        $this->assertTrue(core_course_category::can_view_category($child));
        $this->assertFalse(core_course_category::can_view_category($parent));

        // But browsing from the top never reaches it, because the parent is invisible.
        $toplevel = core_course_category::top()->get_children();
        $this->assertArrayNotHasKey($parent->id, $toplevel);
        $this->assertArrayNotHasKey($child->id, $toplevel);
    }
}
