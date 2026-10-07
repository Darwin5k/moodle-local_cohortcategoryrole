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
 * Configuration checks for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\local;

use context_coursecat;
use context_system;
use core_course_category;
use local_cohortcategoryrole\task\reconcile;
use moodle_url;
use stdClass;

/**
 * Reports on the configuration this plugin depends on.
 *
 * Every problem these look for produces no error and no log entry - the site simply grants or
 * withholds the wrong access. So this is written as a report: every check returns a row whatever
 * its state. Each row states a requirement - what must be true, and why - which is always shown.
 *
 * When a requirement is not met the row says how many things are affected, never which: on a large
 * site a list would swamp the page. The items themselves travel with the row, for a detail view or
 * a filtered list one click away, and where it is safe the row offers a fix.
 *
 * Requirement and reason text live in check{id}_requirement and check{id}_why; the longer
 * explanation lives in check{id}_help, behind a help icon.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostics {
    /** @var string Everything is as it should be. */
    public const OK = 'ok';

    /** @var string Worth knowing, not a fault. */
    public const INFO = 'info';

    /** @var string Access is probably not behaving as intended. */
    public const WARNING = 'warning';

    /** @var string Access is definitely not behaving as intended. */
    public const ERROR = 'error';

    /** @var string The capability the whole design turns on. */
    public const CAPABILITY = 'moodle/category:viewcourselist';

    /** @var string[] Capabilities whose category operations are gated behind seeing the category. */
    public const GATED_CAPABILITIES = [
        'moodle/course:create',
        'moodle/category:manage',
        'moodle/restore:restorecourse',
    ];

    /**
     * Runs every check and returns one row each, in reading order.
     *
     * @return stdClass[] Each with ->id, ->state, ->found (a count, or null when met), ->items (the
     *                    affected things, each ->label and ->url), ->detailurl, ->fix, ->actionurl
     *                    and ->actionlabel.
     */
    public static function run(): array {
        return [
            self::check_baseline(),
            self::check_management_roles(),
            self::check_prohibit(),
            self::check_task(),
            self::check_mapped_roles(),
            self::check_cohort_visibility(),
            self::check_cross_mappings(),
            self::check_unmapped(),
            self::check_orphans(),
            self::check_reachability(),
        ];
    }

    /**
     * Returns how many checks need attention, and the worst state, from cache where possible.
     *
     * For the badge and notice on tabs other than Status. Running every check costs a query or more
     * per mapping, which on a large site is seconds per page; this costs nothing once cached.
     *
     * @return array ['problems' => int, 'worst' => string]
     */
    public static function summary(): array {
        $summary = \cache::make('local_cohortcategoryrole', 'status')->get('summary');
        if ($summary !== false) {
            return $summary;
        }

        return self::store_summary(self::run());
    }

    /**
     * Records the summary of a fresh set of results, and returns it.
     *
     * @param stdClass[] $results
     * @return array ['problems' => int, 'worst' => string]
     */
    public static function store_summary(array $results): array {
        $problems = 0;
        foreach ($results as $result) {
            if ($result->state === self::WARNING || $result->state === self::ERROR) {
                $problems++;
            }
        }

        $summary = ['problems' => $problems, 'worst' => self::worst_state($results)];
        \cache::make('local_cohortcategoryrole', 'status')->set('summary', $summary);

        return $summary;
    }

    /**
     * Discards the cached summary, so the next page works it out afresh.
     *
     * @return void
     */
    public static function invalidate_summary(): void {
        \cache::make('local_cohortcategoryrole', 'status')->delete('summary');
    }

    /**
     * Runs one check by id.
     *
     * @param string $id
     * @return stdClass|null
     */
    public static function get(string $id): ?stdClass {
        foreach (self::run() as $row) {
            if ($row->id === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Returns the worst state present in a set of results.
     *
     * @param stdClass[] $results
     * @return string
     */
    public static function worst_state(array $results): string {
        foreach ([self::ERROR, self::WARNING, self::INFO] as $state) {
            foreach ($results as $result) {
                if ($result->state === $state) {
                    return $state;
                }
            }
        }

        return self::OK;
    }

    /**
     * Returns the name core uses for a permission value.
     *
     * @param int $permission One of the CAP_ constants.
     * @return string
     */
    public static function permission_name(int $permission): string {
        $names = [
            CAP_INHERIT => 'notset',
            CAP_ALLOW => 'allow',
            CAP_PREVENT => 'prevent',
            CAP_PROHIBIT => 'prohibit',
        ];

        return get_string($names[$permission] ?? 'notset', 'role');
    }

    /**
     * The archetype roles that still allow the capability site-wide.
     *
     * @return stdClass[] Role records.
     */
    public static function baseline_roles_allowing(): array {
        global $DB;

        $roles = [];
        foreach (['user', 'guest'] as $shortname) {
            if (!$role = $DB->get_record('role', ['shortname' => $shortname])) {
                continue;
            }
            $permission = $DB->get_field('role_capabilities', 'permission', [
                'roleid' => $role->id,
                'capability' => self::CAPABILITY,
                'contextid' => context_system::instance()->id,
            ]);
            if ((int) $permission === CAP_ALLOW) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Roles that hold a category management capability without allowing category visibility.
     *
     * Deliberately independent of the baseline, so the baseline fix can warn which roles it is about
     * to affect before it does so.
     *
     * @return stdClass[] Role records.
     */
    public static function management_roles_lacking_visibility(): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::GATED_CAPABILITIES, SQL_PARAMS_NAMED, 'cap');
        $params['allow'] = CAP_ALLOW;

        $sql = "SELECT DISTINCT rc.roleid
                  FROM {role_capabilities} rc
                 WHERE rc.capability $insql AND rc.permission = :allow";

        $roles = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $grants = $DB->record_exists('role_capabilities', [
                'roleid' => $record->roleid,
                'capability' => self::CAPABILITY,
                'permission' => CAP_ALLOW,
            ]);
            if (!$grants && $role = $DB->get_record('role', ['id' => $record->roleid])) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Cohorts that are used by a mapping and are visible.
     *
     * @return stdClass[] Cohort records.
     */
    public static function visible_mapped_cohorts(): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT c.*
               FROM {cohort} c
              WHERE c.visible = 1
                AND EXISTS (SELECT 1 FROM {" . sync::TABLE . "} m WHERE m.cohortid = c.id)
           ORDER BY c.name ASC"
        );
    }

    /**
     * Builds one report row.
     *
     * @param string $id Check identifier, also the language string prefix.
     * @param string $state One of the class constants.
     * @param string|null $found How many things are affected, translated; null when the requirement is met.
     * @param array $items The affected things, each ['label' => string, 'url' => moodle_url|null].
     * @param string|null $fix A fixer action that can deal with it.
     * @param moodle_url|null $actionurl Where to deal with it by hand.
     * @param string|null $actionlabel Translated link text for $actionurl.
     * @param moodle_url|null $listurl A filtered list that already shows the detail, used instead
     *                                 of the generic detail view.
     * @return stdClass
     */
    protected static function row(
        string $id,
        string $state,
        ?string $found = null,
        array $items = [],
        ?string $fix = null,
        ?moodle_url $actionurl = null,
        ?string $actionlabel = null,
        ?moodle_url $listurl = null
    ): stdClass {
        $detailurl = $listurl;
        if (!$detailurl && $items) {
            $detailurl = new moodle_url('/local/cohortcategoryrole/index.php', ['tab' => 'status', 'check' => $id]);
        }

        return (object) [
            'id' => $id,
            'state' => $state,
            'found' => $found,
            'items' => array_map(fn($item) => (object) $item, $items),
            'detailurl' => $detailurl,
            'fix' => $fix,
            'actionurl' => $actionurl,
            'actionlabel' => $actionlabel,
        ];
    }

    /**
     * Shorthand for a plugin language string.
     *
     * @param string $identifier
     * @param mixed $a
     * @return string
     */
    protected static function str(string $identifier, $a = null): string {
        return get_string($identifier, 'local_cohortcategoryrole', $a);
    }

    /**
     * An item linking to a role's definition.
     *
     * @param stdClass $role
     * @param string|null $label Defaults to the role name.
     * @return array
     */
    protected static function role_item(stdClass $role, ?string $label = null): array {
        return [
            'label' => $label ?? role_get_name($role),
            'url' => new moodle_url('/admin/roles/define.php', ['action' => 'view', 'roleid' => $role->id]),
        ];
    }

    /**
     * Is the site-wide default allow still in place for either archetype role?
     *
     * @return stdClass
     */
    protected static function check_baseline(): stdClass {
        $roles = self::baseline_roles_allowing();
        if (!$roles) {
            return self::row('baseline', self::OK);
        }

        $items = [];
        foreach ($roles as $role) {
            $items[] = self::role_item($role, self::str('foundroleperm', (object) [
                'role' => role_get_name($role),
                'permission' => self::permission_name(CAP_ALLOW),
            ]));
        }

        return self::row(
            'baseline',
            self::WARNING,
            self::str('foundbaselinecount', count($roles)),
            $items,
            'baseline',
            new moodle_url('/admin/roles/manage.php'),
            self::str('actiondefineroles')
        );
    }

    /**
     * Roles that administer categories but can no longer see them.
     *
     * Only a problem once the baseline is prevented: until then they still inherit the capability.
     *
     * @return stdClass
     */
    protected static function check_management_roles(): stdClass {
        if (!self::baseline_is_prevented()) {
            return self::row('management', self::OK);
        }

        $roles = self::management_roles_lacking_visibility();
        if (!$roles) {
            return self::row('management', self::OK);
        }

        return self::row(
            'management',
            self::WARNING,
            self::str('foundmanagementcount', count($roles)),
            array_map(fn($role) => self::role_item($role), $roles),
            'management',
            new moodle_url('/admin/roles/manage.php'),
            self::str('actiondefineroles')
        );
    }

    /**
     * Any Prohibit on the capability cancels every grant this plugin makes.
     *
     * @return stdClass
     */
    protected static function check_prohibit(): stdClass {
        global $DB;

        $sql = "SELECT rc.id, rc.contextid, r.id AS roleid, r.name, r.shortname
                  FROM {role_capabilities} rc
                  JOIN {role} r ON r.id = rc.roleid
                 WHERE rc.capability = :cap AND rc.permission = :prohibit";

        $items = [];
        foreach ($DB->get_records_sql($sql, ['cap' => self::CAPABILITY, 'prohibit' => CAP_PROHIBIT]) as $record) {
            $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
            // Rendered raw, so never pass a database value straight through. role_get_name()
            // applies format_string.
            $rolename = role_get_name((object) [
                'id' => $record->roleid,
                'name' => $record->name,
                'shortname' => $record->shortname,
            ]);

            // A Prohibit in the role definition is changed on Define roles; one at a deeper context
            // is an override, changed on that context's permissions page.
            $url = new moodle_url('/admin/roles/define.php', ['action' => 'view', 'roleid' => $record->roleid]);
            if ($context && $context->contextlevel != CONTEXT_SYSTEM) {
                $url = new moodle_url('/admin/roles/permissions.php', ['contextid' => $context->id]);
            }

            $items[] = [
                'label' => $rolename . ' (' . ($context ? $context->get_context_name(false) : '?') . ')',
                'url' => $url,
            ];
        }

        if (!$items) {
            return self::row('prohibit', self::OK);
        }

        return self::row(
            'prohibit',
            self::ERROR,
            self::str('foundprohibitcount', count($items)),
            $items,
            null,
            new moodle_url('/admin/roles/manage.php'),
            self::str('actiondefineroles')
        );
    }

    /**
     * The scheduled task is the mechanism that guarantees correctness.
     *
     * @return stdClass
     */
    protected static function check_task(): stdClass {
        $tasksurl = new moodle_url('/admin/tool/task/scheduledtasks.php');

        $task = \core\task\manager::get_scheduled_task(reconcile::class);
        if (!$task) {
            return self::row('task', self::OK);
        }

        if ($task->get_disabled()) {
            return self::row(
                'task',
                self::WARNING,
                self::str('foundtaskdisabled'),
                [],
                'reconcile',
                $tasksurl,
                self::str('actionscheduledtasks')
            );
        }

        $lastrun = $task->get_last_run_time();
        if (!$lastrun) {
            return self::row(
                'task',
                self::INFO,
                self::str('foundtasknever'),
                [],
                'reconcile',
                $tasksurl,
                self::str('actionscheduledtasks')
            );
        }

        if ((time() - $lastrun) > 3 * HOURSECS) {
            return self::row(
                'task',
                self::WARNING,
                self::str('foundtaskstale', format_time(time() - $lastrun)),
                [],
                'reconcile',
                $tasksurl,
                self::str('actionscheduledtasks')
            );
        }

        return self::row('task', self::OK);
    }

    /**
     * A mapped role that mass-enrols its holders is almost never intended.
     *
     * @return stdClass
     */
    protected static function check_mapped_roles(): stdClass {
        global $DB;

        $roles = $DB->get_records_sql(
            "SELECT r.*
               FROM {role} r
              WHERE EXISTS (SELECT 1 FROM {" . sync::TABLE . "} m WHERE m.roleid = r.id)
                AND EXISTS (SELECT 1 FROM {role_capabilities} rc
                             WHERE rc.roleid = r.id AND rc.capability = :cap AND rc.permission = :allow)
           ORDER BY r.sortorder",
            ['cap' => 'enrol/category:synchronised', 'allow' => CAP_ALLOW]
        );

        $items = [];
        foreach ($roles as $role) {
            $items[] = self::role_item($role);
        }

        if (!$items) {
            return self::row('mappedroles', self::OK);
        }

        return self::row(
            'mappedroles',
            self::ERROR,
            self::str('foundmappedrolescount', count($items)),
            $items,
            null,
            new moodle_url('/admin/roles/manage.php'),
            self::str('actiondefineroles')
        );
    }

    /**
     * A visible audience cohort can be picked up by any teacher beneath it.
     *
     * @return stdClass
     */
    protected static function check_cohort_visibility(): stdClass {
        $items = [];
        foreach (self::visible_mapped_cohorts() as $cohort) {
            $items[] = [
                'label' => format_string($cohort->name),
                'url' => new moodle_url('/cohort/edit.php', ['id' => $cohort->id]),
            ];
        }

        if (!$items) {
            return self::row('cohortvisibility', self::OK);
        }

        return self::row(
            'cohortvisibility',
            self::WARNING,
            self::str('foundvisiblecount', count($items)),
            $items,
            'cohortvisibility',
            new moodle_url('/cohort/index.php'),
            self::str('actioncohorts')
        );
    }

    /**
     * Cross mappings: a category cohort granting a category outside its own branch.
     *
     * Legitimate and supported, so a note rather than a warning - but worth seeing, because whoever
     * manages the cohort decides who sees an area they do not run. The detail is the mappings list
     * filtered to Cross, which already shows each one with its context, category and role.
     *
     * @return stdClass
     */
    protected static function check_cross_mappings(): stdClass {
        global $DB;

        // One query rather than classifying each mapping in turn: on a site with thousands of
        // mappings the per-mapping lookups cost several queries apiece.
        $counts = $DB->get_record_sql(
            "SELECT COUNT(1) AS mappings, COUNT(DISTINCT m.cohortid) AS cohorts
               FROM {" . sync::TABLE . "} m
               JOIN {cohort} c ON c.id = m.cohortid
               JOIN {context} ctx ON ctx.id = c.contextid AND ctx.contextlevel = :catlevel
               JOIN {course_categories} hc ON hc.id = ctx.instanceid
               JOIN {course_categories} cc ON cc.id = m.categoryid
              WHERE NOT " . sync::home_sql(),
            ['catlevel' => CONTEXT_COURSECAT]
        );

        if (empty($counts->mappings)) {
            return self::row('crossmappings', self::OK);
        }

        // Counted as mappings across cohorts: one cohort can hold several cross mappings, and a bare
        // number would leave a reader wondering which it counted.
        return self::row(
            'crossmappings',
            self::INFO,
            self::str('foundcross', (object) ['mappings' => (int) $counts->mappings, 'cohorts' => (int) $counts->cohorts]),
            [],
            null,
            null,
            null,
            new moodle_url('/local/cohortcategoryrole/index.php', ['filtertype' => sync::TYPE_CROSS])
        );
    }

    /**
     * Category cohorts with no mapping at all.
     *
     * Not a fault - a cohort may live in a category for enrolment or delegation alone - so a note.
     *
     * @return stdClass
     */
    protected static function check_unmapped(): stdClass {
        global $DB;

        $count = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {cohort} c
               JOIN {context} ctx ON ctx.id = c.contextid AND ctx.contextlevel = :level
              WHERE NOT EXISTS (SELECT 1 FROM {" . sync::TABLE . "} m WHERE m.cohortid = c.id)",
            ['level' => CONTEXT_COURSECAT]
        );

        if (!$count) {
            return self::row('unmapped', self::OK);
        }

        return self::row(
            'unmapped',
            self::INFO,
            self::str('foundunmapped', $count),
            [],
            null,
            null,
            null,
            new moodle_url('/local/cohortcategoryrole/index.php', ['show' => 'unmapped'])
        );
    }

    /**
     * Assignments this plugin owns that no mapping accounts for.
     *
     * @return stdClass
     */
    protected static function check_orphans(): stdClass {
        $orphans = sync::count_orphans();

        if (!$orphans) {
            return self::row('orphans', self::OK);
        }

        return self::row('orphans', self::WARNING, self::str('foundorphans', $orphans), [], 'orphans');
    }

    /**
     * Mapped categories that members cannot browse to, because a parent category is hidden from them.
     *
     * @return stdClass
     */
    protected static function check_reachability(): stdClass {
        global $DB;

        // Only mapped categories with a parent can be unreachable. Fetch them with one sample member
        // each in a single query, rather than a category load and a member query per mapping.
        $rows = $DB->get_records_sql(
            "SELECT m.id, m.categoryid, cc.path, MIN(cm.userid) AS memberid
               FROM {" . sync::TABLE . "} m
               JOIN {course_categories} cc ON cc.id = m.categoryid AND cc.depth > 1
               JOIN {cohort_members} cm ON cm.cohortid = m.cohortid
           GROUP BY m.id, m.categoryid, cc.path"
        );
        if (!$rows) {
            return self::row('reachability', self::OK);
        }

        // Load every category and member involved in one go.
        $categoryids = [];
        $memberids = [];
        foreach ($rows as $row) {
            foreach (explode('/', trim($row->path, '/')) as $id) {
                $categoryids[(int) $id] = true;
            }
            $memberids[(int) $row->memberid] = true;
        }
        $categories = core_course_category::get_many(array_keys($categoryids));
        $members = $DB->get_records_list('user', 'id', array_keys($memberids));

        // Whether a member can see a category is the expensive part; never ask the same question twice.
        $cansee = [];
        $items = [];

        foreach ($rows as $row) {
            if (isset($items[$row->categoryid]) || !isset($categories[$row->categoryid], $members[$row->memberid])) {
                continue;
            }

            // Ancestors, nearest first, so the report names the parent that actually blocks the way.
            $ancestors = array_reverse(array_slice(explode('/', trim($row->path, '/')), 0, -1));
            foreach ($ancestors as $parentid) {
                $parentid = (int) $parentid;
                $key = $row->memberid . ':' . $parentid;
                if (!isset($cansee[$key])) {
                    $context = context_coursecat::instance($parentid, IGNORE_MISSING);
                    $cansee[$key] = !$context || has_capability(self::CAPABILITY, $context, $members[$row->memberid]);
                }
                if (!$cansee[$key] && isset($categories[$parentid])) {
                    $items[$row->categoryid] = [
                        'label' => self::str('foundunreachable', (object) [
                            'category' => $categories[$row->categoryid]->get_formatted_name(),
                            'parent' => $categories[$parentid]->get_formatted_name(),
                        ]),
                        'url' => new moodle_url(
                            '/local/cohortcategoryrole/index.php',
                            ['filtercategory' => $row->categoryid]
                        ),
                    ];
                    break;
                }
            }
        }

        if (!$items) {
            return self::row('reachability', self::OK);
        }

        return self::row(
            'reachability',
            self::WARNING,
            self::str('foundunreachablecount', count($items)),
            array_values($items),
            null,
            new moodle_url('/local/cohortcategoryrole/index.php'),
            self::str('actionmappings')
        );
    }

    /**
     * Is the default allow removed from either archetype role?
     *
     * @return bool
     */
    protected static function baseline_is_prevented(): bool {
        global $DB;

        foreach (['user', 'guest'] as $shortname) {
            if (!$role = $DB->get_record('role', ['shortname' => $shortname])) {
                continue;
            }
            $permission = $DB->get_field('role_capabilities', 'permission', [
                'roleid' => $role->id,
                'capability' => self::CAPABILITY,
                'contextid' => context_system::instance()->id,
            ]);
            if ((int) $permission === CAP_PREVENT) {
                return true;
            }
        }

        return false;
    }
}
