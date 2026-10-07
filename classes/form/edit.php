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

        // Core's cohort selector searches over AJAX, so the page never carries the site's whole cohort
        // list - which on a site with thousands of cohorts would be a very large page. It also only
        // offers cohorts the current user may see.
        $mform->addElement(
            'cohort',
            'cohortid',
            get_string('cohort', 'local_cohortcategoryrole'),
            ['includes' => 'all', 'contextid' => \context_system::instance()->id]
        );
        $mform->addRule('cohortid', null, 'required', null, 'client');

        $mform->addElement(
            'autocomplete',
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
            sync::get_category_role_options()
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
        global $DB;

        $errors = parent::validation($data, $files);

        // The form only offers real choices, but a crafted post need not use the form.
        if (empty($data['cohortid']) || !$DB->record_exists('cohort', ['id' => $data['cohortid']])) {
            $errors['cohortid'] = get_string('required');
        }
        if (empty($data['categoryid']) || !$DB->record_exists('course_categories', ['id' => $data['categoryid']])) {
            $errors['categoryid'] = get_string('required');
        }
        if (empty($data['roleid']) || !$DB->record_exists('role', ['id' => $data['roleid']])) {
            $errors['roleid'] = get_string('required');
        }
        if ($errors) {
            return $errors;
        }

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
}
