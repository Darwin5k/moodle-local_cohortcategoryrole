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
$page = optional_param('page', 0, PARAM_INT);

$perpage = 50;

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

$totalmappings = $DB->count_records(sync::TABLE);
$mappings = $DB->get_records(sync::TABLE, null, 'id ASC', '*', $page * $perpage, $perpage);

// Counts come from two grouped queries rather than two per row: a site using this plugin is
// expected to have many mappings, and per-row counting would put hundreds of queries on the page.
// Both are grouped by a unique column, so get_records_sql() cannot collapse rows here.
$membercounts = $DB->get_records_sql(
    'SELECT cohortid, COUNT(1) AS total FROM {cohort_members} GROUP BY cohortid'
);
$assignmentcounts = $DB->get_records_sql(
    'SELECT itemid, COUNT(1) AS total FROM {role_assignments} WHERE component = :component GROUP BY itemid',
    ['component' => sync::COMPONENT]
);

$cohortnames = $DB->get_records_menu('cohort', null, '', 'id, name');
$categories = core_course_category::get_many(array_column($mappings, 'categoryid'));

$cohortrecords = $DB->get_records('cohort', null, '', 'id, name, contextid, visible');

$table = new html_table();
$table->head = [
    get_string('cohort', 'local_cohortcategoryrole'),
    get_string('cohortcontext', 'local_cohortcategoryrole'),
    get_string('cohortvisible', 'local_cohortcategoryrole'),
    get_string('category', 'local_cohortcategoryrole'),
    get_string('role', 'local_cohortcategoryrole'),
    get_string('membercount', 'local_cohortcategoryrole'),
    get_string('assignmentcount', 'local_cohortcategoryrole'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable';

foreach ($mappings as $mapping) {
    $cohortname = $cohortnames[$mapping->cohortid] ?? null;
    $category = $categories[$mapping->categoryid] ?? null;
    $cohort = $cohortrecords[$mapping->cohortid] ?? null;

    // Where the cohort itself lives, and whether that agrees with what it is mapped to. A cohort
    // administered in one category but granting another is legitimate, but it means whoever can
    // edit that cohort can hand out access to an area they do not administer - worth seeing here.
    $cohortcontextcell = get_string('systemcohort', 'local_cohortcategoryrole');
    if ($cohort) {
        $cohortcategoryid = sync::category_for_cohort((int) $cohort->id);
        if ($cohortcategoryid) {
            $cohortcategory = core_course_category::get($cohortcategoryid, IGNORE_MISSING, true);
            $cohortcontextcell = $cohortcategory ? $cohortcategory->get_formatted_name() : '?';
            if ($cohortcategoryid !== (int) $mapping->categoryid) {
                $cohortcontextcell .= ' ' . html_writer::tag(
                    'span',
                    get_string('scopeoutside', 'local_cohortcategoryrole'),
                    ['class' => 'badge bg-warning text-dark']
                );
            }
        }
    }

    $cohortcell = $cohortname ? format_string($cohortname) : '-';
    if ($cohort) {
        $cohortcell = html_writer::link(
            new moodle_url('/cohort/edit.php', ['id' => $cohort->id]),
            format_string($cohortname)
        );
    }

    $categorycell = '-';
    if ($category) {
        $categorycell = html_writer::link(
            new moodle_url('/course/index.php', ['categoryid' => $category->id]),
            $category->get_formatted_name()
        );
    }

    $rolecell = '-';
    if (isset($roles[$mapping->roleid])) {
        $rolecell = html_writer::link(
            new moodle_url('/admin/roles/define.php', ['action' => 'view', 'roleid' => $mapping->roleid]),
            $roles[$mapping->roleid]->localname
        );
    }

    $deleteurl = new moodle_url($baseurl, ['delete' => $mapping->id]);
    $deleteicon = $OUTPUT->action_icon(
        $deleteurl,
        new pix_icon('t/delete', get_string('delete')),
        null,
        ['aria-label' => get_string('deletemapping', 'local_cohortcategoryrole')]
    );

    $table->data[] = [
        $cohortcell,
        $cohortcontextcell,
        $cohort && $cohort->visible ? get_string('yes') : get_string('no'),
        $categorycell,
        $rolecell,
        isset($membercounts[$mapping->cohortid]) ? $membercounts[$mapping->cohortid]->total : 0,
        isset($assignmentcounts[$mapping->id]) ? $assignmentcounts[$mapping->id]->total : 0,
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
    echo $OUTPUT->paging_bar($totalmappings, $page, $perpage, $baseurl);
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalmappings, $page, $perpage, $baseurl);
} else {
    echo $OUTPUT->notification(get_string('nomappings', 'local_cohortcategoryrole'), notification::NOTIFY_INFO);
}

// Category cohorts that grant nothing. Cohort membership alone has no effect on what a user can
// see, so a cohort sitting in a category looks configured while doing nothing at all.
$categorycohorts = $DB->get_records_sql(
    "SELECT c.id, c.name, ctx.instanceid AS categoryid
       FROM {cohort} c
       JOIN {context} ctx ON ctx.id = c.contextid AND ctx.contextlevel = :level
   ORDER BY c.name ASC",
    ['level' => CONTEXT_COURSECAT]
);

$mappedpairs = [];
foreach ($DB->get_records(sync::TABLE, null, '', 'id, cohortid, categoryid') as $row) {
    $mappedpairs[$row->cohortid . ':' . $row->categoryid] = true;
}

$unmapped = [];
foreach ($categorycohorts as $cohort) {
    if (!isset($mappedpairs[$cohort->id . ':' . $cohort->categoryid])) {
        $unmapped[] = $cohort;
    }
}

if ($unmapped) {
    echo $OUTPUT->heading(get_string('unmappedheading', 'local_cohortcategoryrole'), 3);
    echo html_writer::tag('p', get_string('unmappedintro', 'local_cohortcategoryrole'));

    $items = [];
    foreach ($unmapped as $cohort) {
        $unmappedcategory = core_course_category::get($cohort->categoryid, IGNORE_MISSING, true);
        $items[] = format_string($cohort->name)
            . ' &rarr; ' . ($unmappedcategory ? $unmappedcategory->get_formatted_name() : '?')
            . ' ' . html_writer::link(
                new moodle_url('/local/cohortcategoryrole/edit.php', [
                    'cohortid' => $cohort->id,
                    'categoryid' => $cohort->categoryid,
                ]),
                get_string('unmappedmapthis', 'local_cohortcategoryrole')
            );
    }
    echo html_writer::alist($items);
}

echo $OUTPUT->heading(get_string('manage'), 3);
echo html_writer::alist([
    html_writer::link(new moodle_url('/cohort/index.php'), get_string('cohorts', 'cohort')),
    html_writer::link(new moodle_url('/admin/roles/define.php'), get_string('defineroles', 'role')),
    html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'local_cohortcategoryrole_settings']),
        get_string('settings')
    ),
]);

echo $OUTPUT->single_button(
    new moodle_url('/local/cohortcategoryrole/edit.php'),
    get_string('addmapping', 'local_cohortcategoryrole'),
    'get'
);

echo $OUTPUT->footer();
