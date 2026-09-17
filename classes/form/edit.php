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
 * Mapping form for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use context;
use context_coursecat;
use core_course_category;
use local_cohortcategoryrole\local\sync;
use moodleform;

/**
 * Lets an administrator map a cohort to a role in a course category.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit extends moodleform {
    /**
     * Builds the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'select',
            'cohortid',
            get_string('cohort', 'local_cohortcategoryrole'),
            self::get_cohort_options()
        );
        $mform->addRule('cohortid', null, 'required', null, 'client');

        $mform->addElement(
            'select',
            'categoryid',
            get_string('category', 'local_cohortcategoryrole'),
            core_course_category::make_categories_list()
        );
        $mform->addRule('categoryid', null, 'required', null, 'client');
        $mform->addHelpButton('categoryid', 'category', 'local_cohortcategoryrole');

        $mform->addElement(
            'select',
            'roleid',
            get_string('role', 'local_cohortcategoryrole'),
            self::get_role_options()
        );
        $mform->addRule('roleid', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('addmapping', 'local_cohortcategoryrole'));
    }

    /**
     * Rejects duplicate mappings and roles that cannot be assigned in a category.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $existing = sync::get_mappings([
            'cohortid'   => $data['cohortid'],
            'categoryid' => $data['categoryid'],
            'roleid'     => $data['roleid'],
        ]);
        if ($existing) {
            $errors['cohortid'] = get_string('errorduplicate', 'local_cohortcategoryrole');
        }

        $context = context_coursecat::instance($data['categoryid'], IGNORE_MISSING);
        if ($context && !array_key_exists($data['roleid'], get_assignable_roles($context))) {
            $errors['roleid'] = get_string('errorrolenotassignable', 'local_cohortcategoryrole');
        }

        return $errors;
    }

    /**
     * Returns every cohort on the site, labelled with its context when it is not site wide.
     *
     * @return array Cohort id => label.
     */
    protected static function get_cohort_options(): array {
        global $DB;

        $options = [];
        foreach ($DB->get_records('cohort', null, 'name ASC') as $cohort) {
            $label = format_string($cohort->name);
            $cohortcontext = context::instance_by_id($cohort->contextid, IGNORE_MISSING);
            if ($cohortcontext && $cohortcontext->contextlevel != CONTEXT_SYSTEM) {
                $label .= ' (' . $cohortcontext->get_context_name(false) . ')';
            }
            $options[$cohort->id] = $label;
        }

        return $options;
    }

    /**
     * Returns the roles that may be assigned in a course category context.
     *
     * The specific category is not known until the form is submitted, so this lists every role
     * whose assignable context types include Category. The chosen combination is checked again
     * in validation.
     *
     * @return array Role id => role name.
     */
    protected static function get_role_options(): array {
        $assignable = get_roles_for_contextlevels(CONTEXT_COURSECAT);

        $options = [];
        foreach (role_fix_names(get_all_roles(), null, ROLENAME_ORIGINAL) as $role) {
            if (in_array($role->id, $assignable)) {
                $options[$role->id] = $role->localname;
            }
        }

        return $options;
    }
}
