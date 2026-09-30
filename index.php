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
 * Manages cohort category role mappings, and reports on the configuration they depend on.
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
$tab = optional_param('tab', 'mappings', PARAM_ALPHA);
$search = trim(optional_param('search', '', PARAM_TEXT));
$filtercategory = optional_param('filtercategory', 0, PARAM_INT);
$filterrole = optional_param('filterrole', 0, PARAM_INT);

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

$checks = diagnostics::run();
$problems = 0;
foreach ($checks as $check) {
    if ($check->state === diagnostics::WARNING || $check->state === diagnostics::ERROR) {
        $problems++;
    }
}

// The status tab carries a count, so a problem is visible from the mappings tab without leaving it.
$statuslabel = get_string('tabstatus', 'local_cohortcategoryrole');
if ($problems) {
    $statuslabel .= ' ' . html_writer::tag('span', $problems, ['class' => 'badge bg-warning text-dark']);
}

$tabs = [
    new tabobject(
        'mappings',
        new moodle_url($baseurl, ['tab' => 'mappings']),
        get_string('tabmappings', 'local_cohortcategoryrole')
    ),
    new tabobject('status', new moodle_url($baseurl, ['tab' => 'status']), $statuslabel),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mappings', 'local_cohortcategoryrole'));
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'status') {
    $badges = [
        diagnostics::OK => 'bg-success',
        diagnostics::INFO => 'bg-info',
        diagnostics::WARNING => 'bg-warning text-dark',
        diagnostics::ERROR => 'bg-danger',
    ];

    $statustable = new html_table();
    $statustable->head = [
        get_string('columncheck', 'local_cohortcategoryrole'),
        get_string('columnstate', 'local_cohortcategoryrole'),
        get_string('columnresult', 'local_cohortcategoryrole'),
        get_string('actions'),
    ];
    $statustable->attributes['class'] = 'generaltable';

    foreach ($checks as $check) {
        $name = get_string('check' . $check->id, 'local_cohortcategoryrole')
            . ' ' . $OUTPUT->help_icon('check' . $check->id, 'local_cohortcategoryrole');

        $badge = html_writer::tag(
            'span',
            get_string('state' . $check->state, 'local_cohortcategoryrole'),
            ['class' => 'badge ' . $badges[$check->state]]
        );

        $action = '';
        if ($check->actionurl) {
            $action = html_writer::link($check->actionurl, $check->actionlabel);
        }

        $statustable->data[] = [$name, $badge, $check->summary, $action];
    }

    echo html_writer::table($statustable);

    // Category cohorts that grant nothing. Cohort membership alone has no effect on what a user
    // can see, so a cohort sitting in a category looks configured while doing nothing at all.
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

    echo $OUTPUT->heading(get_string('managelinks', 'local_cohortcategoryrole'), 3);
    echo html_writer::alist([
        html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'local_cohortcategoryrole_settings']),
            get_string('tabsettings', 'local_cohortcategoryrole')
        ),
        html_writer::link(new moodle_url('/cohort/index.php'), get_string('cohorts', 'cohort')),
        html_writer::link(new moodle_url('/admin/roles/define.php'), get_string('defineroles', 'role')),
    ]);

    echo $OUTPUT->footer();
    die();
}

// Mappings tab: the mappings, and nothing else.

$roles = role_fix_names(get_all_roles(), null, ROLENAME_ORIGINAL);

// Filter options are drawn from the mappings that exist, not from every category and role on the
// site: on a site with hundreds of categories, a list of the ones actually in use is far shorter
// and is the only set that can return a result.
$usedcategories = [];
$usedroles = [];
foreach ($DB->get_records(sync::TABLE, null, '', 'id, categoryid, roleid') as $row) {
    $usedcategories[(int) $row->categoryid] = true;
    $usedroles[(int) $row->roleid] = true;
}

$categoryoptions = [0 => get_string('filterany', 'local_cohortcategoryrole')];
foreach (core_course_category::get_many(array_keys($usedcategories)) as $usedcategory) {
    $categoryoptions[$usedcategory->id] = $usedcategory->get_formatted_name();
}

$roleoptions = [0 => get_string('filterany', 'local_cohortcategoryrole')];
foreach (array_keys($usedroles) as $usedroleid) {
    if (isset($roles[$usedroleid])) {
        $roleoptions[$usedroleid] = $roles[$usedroleid]->localname;
    }
}

// Build the filtered query.
$where = [];
$params = [];

if ($search !== '') {
    $cohortlike = $DB->sql_like('c.name', ':searchcohort', false);
    $categorylike = $DB->sql_like('cc.name', ':searchcategory', false);
    $where[] = "($cohortlike OR $categorylike)";
    $params['searchcohort'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['searchcategory'] = '%' . $DB->sql_like_escape($search) . '%';
}

if ($filtercategory) {
    $where[] = 'm.categoryid = :filtercategory';
    $params['filtercategory'] = $filtercategory;
}

if ($filterrole) {
    $where[] = 'm.roleid = :filterrole';
    $params['filterrole'] = $filterrole;
}

$wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$from = "FROM {" . sync::TABLE . "} m
    LEFT JOIN {cohort} c ON c.id = m.cohortid
    LEFT JOIN {course_categories} cc ON cc.id = m.categoryid";

$totalmappings = $DB->count_records_sql("SELECT COUNT(1) $from $wheresql", $params);
$mappings = $DB->get_records_sql(
    "SELECT m.id, m.cohortid, m.categoryid, m.roleid $from $wheresql ORDER BY c.name ASC, cc.name ASC",
    $params,
    $page * $perpage,
    $perpage
);

// Keep the filters on paging links.
$filterparams = ['tab' => 'mappings'];
if ($search !== '') {
    $filterparams['search'] = $search;
}
if ($filtercategory) {
    $filterparams['filtercategory'] = $filtercategory;
}
if ($filterrole) {
    $filterparams['filterrole'] = $filterrole;
}
$pageurl = new moodle_url($baseurl, $filterparams);

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

$cohortrecords = $DB->get_records('cohort', null, '', 'id, name, contextid, visible');
$categories = core_course_category::get_many(array_column($mappings, 'categoryid'));

// The filter form.
$filterform = html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out(false),
    'class' => 'row row-cols-lg-auto g-2 align-items-end mb-3']);
$filterform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'mappings']);

$filterform .= html_writer::div(
    html_writer::label(
        get_string('filtersearch', 'local_cohortcategoryrole'),
        'ccr_search',
        true,
        ['class' => 'form-label']
    )
    . html_writer::empty_tag('input', ['type' => 'text', 'id' => 'ccr_search', 'name' => 'search',
        'value' => $search, 'class' => 'form-control']),
    'col'
);

$filterform .= html_writer::div(
    html_writer::label(
        get_string('category', 'local_cohortcategoryrole'),
        'ccr_category',
        true,
        ['class' => 'form-label']
    )
    . html_writer::select(
        $categoryoptions,
        'filtercategory',
        $filtercategory,
        false,
        ['id' => 'ccr_category', 'class' => 'form-select']
    ),
    'col'
);

$filterform .= html_writer::div(
    html_writer::label(
        get_string('role', 'local_cohortcategoryrole'),
        'ccr_role',
        true,
        ['class' => 'form-label']
    )
    . html_writer::select(
        $roleoptions,
        'filterrole',
        $filterrole,
        false,
        ['id' => 'ccr_role', 'class' => 'form-select']
    ),
    'col'
);

$filterform .= html_writer::div(
    html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary',
        'value' => get_string('filterapply', 'local_cohortcategoryrole')]),
    'col'
);

if ($search !== '' || $filtercategory || $filterrole) {
    $filterform .= html_writer::div(
        html_writer::link($baseurl, get_string('filterclear', 'local_cohortcategoryrole')),
        'col'
    );
}

$filterform .= html_writer::end_tag('form');

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
    $cohort = $cohortrecords[$mapping->cohortid] ?? null;
    $category = $categories[$mapping->categoryid] ?? null;

    // Where the cohort itself lives, and whether that agrees with what it is mapped to.
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

    $cohortcell = '-';
    if ($cohort) {
        $cohortcell = html_writer::link(
            new moodle_url('/cohort/edit.php', ['id' => $cohort->id]),
            format_string($cohort->name)
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

    $deleteicon = $OUTPUT->action_icon(
        new moodle_url($baseurl, ['delete' => $mapping->id]),
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

// Surface the worst finding here too, so a problem is not hidden behind a tab nobody clicked.
$worst = diagnostics::worst_state($checks);
if ($worst === diagnostics::ERROR || $worst === diagnostics::WARNING) {
    echo $OUTPUT->notification(
        get_string('statussummary', 'local_cohortcategoryrole', $problems),
        $worst === diagnostics::ERROR ? notification::NOTIFY_ERROR : notification::NOTIFY_WARNING
    );
}

if ($DB->count_records(sync::TABLE)) {
    echo $filterform;
}

if ($table->data) {
    echo $OUTPUT->paging_bar($totalmappings, $page, $perpage, $pageurl);
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalmappings, $page, $perpage, $pageurl);
} else if ($search !== '' || $filtercategory || $filterrole) {
    echo $OUTPUT->notification(get_string('filternoresults', 'local_cohortcategoryrole'), notification::NOTIFY_INFO);
} else {
    echo $OUTPUT->notification(get_string('nomappings', 'local_cohortcategoryrole'), notification::NOTIFY_INFO);
}

echo $OUTPUT->single_button(
    new moodle_url('/local/cohortcategoryrole/edit.php'),
    get_string('addmapping', 'local_cohortcategoryrole'),
    'get'
);

echo $OUTPUT->footer();
