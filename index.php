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
 * Lists and deletes cohort category role mappings.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_cohortcategoryrole\local\diagnostics;
use local_cohortcategoryrole\local\sync;

admin_externalpage_setup('local_cohortcategoryrole');

$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$baseurl = new moodle_url('/local/cohortcategoryrole/index.php');

if ($delete) {
    $mapping = sync::get_mapping($delete);

    if ($confirm) {
        require_sesskey();
        $owned = count(sync::get_assigned_userids($mapping));

        if (sync::delete_mapping((int) $mapping->id)) {
            redirect($baseurl, get_string('mappingdeleteddeferred', 'local_cohortcategoryrole', $owned));
        }

        redirect($baseurl, get_string('mappingdeleted', 'local_cohortcategoryrole'));
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('deletemapping', 'local_cohortcategoryrole'));
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'local_cohortcategoryrole'),
        new moodle_url($baseurl, ['delete' => $mapping->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    die();
}

$roles = role_fix_names(get_all_roles(), null, ROLENAME_ORIGINAL);

$table = new html_table();
$table->head = [
    get_string('cohort', 'local_cohortcategoryrole'),
    get_string('category', 'local_cohortcategoryrole'),
    get_string('role', 'local_cohortcategoryrole'),
    get_string('membercount', 'local_cohortcategoryrole'),
    get_string('assignmentcount', 'local_cohortcategoryrole'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable';

foreach (sync::get_mappings() as $mapping) {
    $cohortname = $DB->get_field('cohort', 'name', ['id' => $mapping->cohortid]);
    $category = core_course_category::get($mapping->categoryid, IGNORE_MISSING, true);

    $deleteurl = new moodle_url($baseurl, ['delete' => $mapping->id]);
    $deleteicon = $OUTPUT->action_icon(
        $deleteurl,
        new pix_icon('t/delete', get_string('delete')),
        null,
        ['aria-label' => get_string('deletemapping', 'local_cohortcategoryrole')]
    );

    $table->data[] = [
        $cohortname ? format_string($cohortname) : '-',
        $category ? $category->get_formatted_name() : '-',
        isset($roles[$mapping->roleid]) ? $roles[$mapping->roleid]->localname : '-',
        $DB->count_records('cohort_members', ['cohortid' => $mapping->cohortid]),
        count(sync::get_assigned_userids($mapping)),
        $deleteicon,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mappings', 'local_cohortcategoryrole'));
echo $OUTPUT->heading(get_string('checksheading', 'local_cohortcategoryrole'), 3);

$notificationtypes = [
    diagnostics::OK => notification::NOTIFY_SUCCESS,
    diagnostics::INFO => notification::NOTIFY_INFO,
    diagnostics::WARNING => notification::NOTIFY_WARNING,
    diagnostics::ERROR => notification::NOTIFY_ERROR,
];

foreach (diagnostics::run() as $check) {
    echo $OUTPUT->notification($check->message, $notificationtypes[$check->level]);
}

if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nomappings', 'local_cohortcategoryrole'), notification::NOTIFY_INFO);
}

echo $OUTPUT->single_button(
    new moodle_url('/local/cohortcategoryrole/edit.php'),
    get_string('addmapping', 'local_cohortcategoryrole'),
    'get'
);

echo $OUTPUT->footer();
