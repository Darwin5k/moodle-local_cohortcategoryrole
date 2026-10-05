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
$show = optional_param('show', 'mapped', PARAM_ALPHA);
$includesystem = optional_param('includesystem', 0, PARAM_BOOL);

if (!in_array($show, ['mapped', 'unmapped', 'both'], true)) {
    $show = 'mapped';
}

// Arriving with a list filter means the mappings tab, even if the link did not say so.
if (optional_param('show', '', PARAM_ALPHA) !== '') {
    $tab = 'mappings';
}

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
    new tabobject(
        'settings',
        new moodle_url($baseurl, ['tab' => 'settings']),
        get_string('tabsettings', 'local_cohortcategoryrole')
    ),
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
    echo $OUTPUT->footer();
    die();
}

if ($tab === 'settings') {
    // Everything here lives elsewhere in Site administration. Saying where, rather than presenting
    // the links as if they were part of this plugin, keeps the way back obvious.
    echo html_writer::tag('p', get_string('settingsintro', 'local_cohortcategoryrole'));

    // Identifiers are spelled out rather than built by concatenation, so strings_test can see them.
    $links = [
        [
            new moodle_url('/admin/settings.php', ['section' => 'local_cohortcategoryrole_settings']),
            'linkpluginsettings',
            'linkpluginsettingspurpose',
            'linkpluginsettingslocation',
        ],
        [new moodle_url('/cohort/index.php'), 'linkcohorts', 'linkcohortspurpose', 'linkcohortslocation'],
        [
            new moodle_url('/admin/roles/manage.php'),
            'linkdefineroles',
            'linkdefinerolespurpose',
            'linkdefineroleslocation',
        ],
    ];

    $settingstable = new html_table();
    $settingstable->head = [
        get_string('columnpage', 'local_cohortcategoryrole'),
        get_string('columnpurpose', 'local_cohortcategoryrole'),
        get_string('columnlocation', 'local_cohortcategoryrole'),
    ];
    $settingstable->attributes['class'] = 'generaltable';

    foreach ($links as [$url, $name, $purpose, $location]) {
        $settingstable->data[] = [
            html_writer::link($url, get_string($name, 'local_cohortcategoryrole')),
            get_string($purpose, 'local_cohortcategoryrole'),
            html_writer::tag('span', get_string($location, 'local_cohortcategoryrole'), ['class' => 'text-muted']),
        ];
    }

    echo html_writer::table($settingstable);
    echo $OUTPUT->footer();
    die();
}

// Mappings tab: one row per mapping, plus a row for each cohort with no mapping when asked for.
$roles = role_fix_names(get_all_roles(), null, ROLENAME_ORIGINAL);

// Filter options come from what exists - mapped categories, and the categories cohorts live in -
// rather than every category on the site, so every choice can return a result.
$usedcategoryids = [];
$usedroles = [];
foreach ($DB->get_records(sync::TABLE, null, '', 'id, categoryid, roleid') as $row) {
    $usedcategoryids[(int) $row->categoryid] = true;
    $usedroles[(int) $row->roleid] = true;
}
$homecategoryids = $DB->get_fieldset_sql(
    "SELECT DISTINCT ctx.instanceid
       FROM {cohort} c
       JOIN {context} ctx ON ctx.id = c.contextid
      WHERE ctx.contextlevel = :level",
    ['level' => CONTEXT_COURSECAT]
);
foreach ($homecategoryids as $homecategoryid) {
    $usedcategoryids[(int) $homecategoryid] = true;
}

$categoryoptions = [0 => get_string('filterany', 'local_cohortcategoryrole')];
foreach (core_course_category::get_many(array_keys($usedcategoryids)) as $usedcategory) {
    $categoryoptions[$usedcategory->id] = $usedcategory->get_formatted_name();
}

$roleoptions = [0 => get_string('filterany', 'local_cohortcategoryrole')];
foreach (array_keys($usedroles) as $usedroleid) {
    if (isset($roles[$usedroleid])) {
        $roleoptions[$usedroleid] = $roles[$usedroleid]->localname;
    }
}

$showoptions = [
    'mapped' => get_string('showmapped', 'local_cohortcategoryrole'),
    'unmapped' => get_string('showunmapped', 'local_cohortcategoryrole'),
    'both' => get_string('showboth', 'local_cohortcategoryrole'),
];

// The list starts from cohorts. A cohort with mappings yields one row per mapping; a cohort with
// none yields a single row with no mapping columns, which is what "unmapped" means here.
$params = ['catlevel' => CONTEXT_COURSECAT];
$where = [];

if ($show === 'mapped') {
    $where[] = 'm.id IS NOT NULL';
} else if ($show === 'unmapped') {
    $where[] = 'm.id IS NULL';
}

// System cohorts imply no category, and many exist for reasons unrelated to this plugin, so
// unmapped ones only appear when asked for. A mapped system cohort is a real grant and always shows.
if (!$includesystem) {
    $where[] = '(m.id IS NOT NULL OR ctx.contextlevel = :catlevelfilter)';
    $params['catlevelfilter'] = CONTEXT_COURSECAT;
}

if ($search !== '') {
    $cohortlike = $DB->sql_like('c.name', ':searchcohort', false);
    $categorylike = $DB->sql_like('COALESCE(cc.name, hc.name)', ':searchcategory', false);
    $where[] = "($cohortlike OR $categorylike)";
    $params['searchcohort'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['searchcategory'] = '%' . $DB->sql_like_escape($search) . '%';
}

if ($filtercategory) {
    // The mapped category for a mapping; the context category for an unmapped cohort.
    $where[] = 'COALESCE(m.categoryid, hc.id) = :filtercategory';
    $params['filtercategory'] = $filtercategory;
}

if ($filterrole) {
    $where[] = 'm.roleid = :filterrole';
    $params['filterrole'] = $filterrole;
}

$wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$from = "FROM {cohort} c
         JOIN {context} ctx ON ctx.id = c.contextid
    LEFT JOIN {course_categories} hc ON hc.id = ctx.instanceid AND ctx.contextlevel = :catlevel
    LEFT JOIN {" . sync::TABLE . "} m ON m.cohortid = c.id
    LEFT JOIN {course_categories} cc ON cc.id = m.categoryid";

$totalrows = $DB->count_records_sql("SELECT COUNT(1) $from $wheresql", $params);

// The first column must be unique for get_records_sql(). Mapping ids are positive and an unmapped
// cohort contributes exactly one row, so a negated cohort id cannot collide with one.
$rows = $DB->get_records_sql(
    "SELECT COALESCE(m.id, -c.id) AS rowkey, c.id AS cohortid, c.name AS cohortname, c.visible,
            ctx.contextlevel, hc.id AS homecategoryid, m.id AS mappingid, m.categoryid, m.roleid
     $from $wheresql
     ORDER BY c.name ASC, cc.name ASC, m.id ASC",
    $params,
    $page * $perpage,
    $perpage
);

$filtersactive = $search !== '' || $filtercategory || $filterrole || $show !== 'mapped' || $includesystem;

// Keep the filters on paging links.
$filterparams = ['tab' => 'mappings', 'show' => $show];
if ($search !== '') {
    $filterparams['search'] = $search;
}
if ($filtercategory) {
    $filterparams['filtercategory'] = $filtercategory;
}
if ($filterrole) {
    $filterparams['filterrole'] = $filterrole;
}
if ($includesystem) {
    $filterparams['includesystem'] = 1;
}
$pageurl = new moodle_url($baseurl, $filterparams);

// Counts come from two grouped queries rather than two per row: a site using this plugin is
// expected to have many cohorts, and per-row counting would put hundreds of queries on the page.
// Both are grouped by a unique column, so get_records_sql() cannot collapse rows here.
$membercounts = $DB->get_records_sql(
    'SELECT cohortid, COUNT(1) AS total FROM {cohort_members} GROUP BY cohortid'
);
$assignmentcounts = $DB->get_records_sql(
    'SELECT itemid, COUNT(1) AS total FROM {role_assignments} WHERE component = :component GROUP BY itemid',
    ['component' => sync::COMPONENT]
);

$displaycategoryids = [];
foreach ($rows as $row) {
    if ($row->categoryid) {
        $displaycategoryids[(int) $row->categoryid] = true;
    }
    if ($row->homecategoryid) {
        $displaycategoryids[(int) $row->homecategoryid] = true;
    }
}
$categories = core_course_category::get_many(array_keys($displaycategoryids));

// The filter form.
$filterform = html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out(false),
    'class' => 'row row-cols-lg-auto g-2 align-items-end mb-3']);
$filterform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'mappings']);

$filterform .= html_writer::div(
    html_writer::label(
        get_string('filtershow', 'local_cohortcategoryrole'),
        'ccr_show',
        true,
        ['class' => 'form-label']
    )
    . html_writer::select($showoptions, 'show', $show, false, ['id' => 'ccr_show', 'class' => 'form-select']),
    'col'
);

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

$systemcheckbox = ['type' => 'checkbox', 'id' => 'ccr_system', 'name' => 'includesystem', 'value' => 1,
    'class' => 'form-check-input'];
if ($includesystem) {
    $systemcheckbox['checked'] = 'checked';
}
$filterform .= html_writer::div(
    html_writer::div(
        html_writer::empty_tag('input', $systemcheckbox)
        . html_writer::label(
            get_string('filterincludesystem', 'local_cohortcategoryrole'),
            'ccr_system',
            false,
            ['class' => 'form-check-label']
        ),
        'form-check'
    ),
    'col'
);

$filterform .= html_writer::div(
    html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary',
        'value' => get_string('filterapply', 'local_cohortcategoryrole')]),
    'col'
);

if ($filtersactive) {
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

foreach ($rows as $row) {
    $homecategory = $row->homecategoryid ? ($categories[$row->homecategoryid] ?? null) : null;

    $cohortcell = html_writer::link(
        new moodle_url('/cohort/edit.php', ['id' => $row->cohortid]),
        format_string($row->cohortname)
    );

    // The cohort's context: System, or the category it lives in.
    $contextcell = get_string('systemcohort', 'local_cohortcategoryrole');
    if ($homecategory) {
        $contextcell = $homecategory->get_formatted_name();
        if ($row->mappingid && (int) $row->homecategoryid !== (int) $row->categoryid) {
            $contextcell .= ' ' . html_writer::tag(
                'span',
                get_string('scopeoutside', 'local_cohortcategoryrole'),
                ['class' => 'badge bg-warning text-dark']
            );
        }
    }

    $visiblecell = $row->visible ? get_string('yes') : get_string('no');
    $members = isset($membercounts[$row->cohortid]) ? $membercounts[$row->cohortid]->total : 0;

    if (!$row->mappingid) {
        // An unmapped cohort grants nothing, so it must not look like a mapping.
        $prefill = ['cohortid' => $row->cohortid];
        $actionlabel = get_string('unmappedmapcohort', 'local_cohortcategoryrole');
        if ($row->homecategoryid) {
            $prefill['categoryid'] = $row->homecategoryid;
            $actionlabel = get_string('unmappedmapthis', 'local_cohortcategoryrole');
        }

        $table->data[] = [
            $cohortcell,
            $contextcell,
            $visiblecell,
            '-',
            html_writer::tag(
                'span',
                get_string('unmappedbadge', 'local_cohortcategoryrole'),
                ['class' => 'badge bg-secondary']
            ),
            $members,
            '-',
            html_writer::link(new moodle_url('/local/cohortcategoryrole/edit.php', $prefill), $actionlabel),
        ];
        continue;
    }

    $category = $categories[$row->categoryid] ?? null;
    $categorycell = '-';
    if ($category) {
        $categorycell = html_writer::link(
            new moodle_url('/course/index.php', ['categoryid' => $category->id]),
            $category->get_formatted_name()
        );
    }

    $rolecell = '-';
    if (isset($roles[$row->roleid])) {
        $rolecell = html_writer::link(
            new moodle_url('/admin/roles/define.php', ['action' => 'view', 'roleid' => $row->roleid]),
            $roles[$row->roleid]->localname
        );
    }

    $deleteicon = $OUTPUT->action_icon(
        new moodle_url($baseurl, ['delete' => $row->mappingid]),
        new pix_icon('t/delete', get_string('delete')),
        null,
        ['aria-label' => get_string('deletemapping', 'local_cohortcategoryrole')]
    );

    $table->data[] = [
        $cohortcell,
        $contextcell,
        $visiblecell,
        $categorycell,
        $rolecell,
        $members,
        isset($assignmentcounts[$row->mappingid]) ? $assignmentcounts[$row->mappingid]->total : 0,
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

if ($DB->record_exists('cohort', [])) {
    echo $filterform;
}

if ($table->data) {
    echo $OUTPUT->paging_bar($totalrows, $page, $perpage, $pageurl);
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalrows, $page, $perpage, $pageurl);
} else if ($filtersactive) {
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
