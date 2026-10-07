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
use core_user;
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

        $roleids = [];
        foreach (sync::get_mappings() as $mapping) {
            $roleids[(int) $mapping->roleid] = true;
        }

        $items = [];
        foreach (array_keys($roleids) as $roleid) {
            $mass = $DB->record_exists('role_capabilities', [
                'roleid' => $roleid,
                'capability' => 'enrol/category:synchronised',
                'permission' => CAP_ALLOW,
            ]);
            if ($mass && $role = $DB->get_record('role', ['id' => $roleid])) {
                $items[] = self::role_item($role);
            }
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
        $mappings = 0;
        $cohorts = [];
        foreach (sync::get_mappings() as $mapping) {
            if (sync::mapping_type((int) $mapping->cohortid, (int) $mapping->categoryid) !== sync::TYPE_CROSS) {
                continue;
            }
            $mappings++;
            $cohorts[(int) $mapping->cohortid] = true;
        }

        if (!$mappings) {
            return self::row('crossmappings', self::OK);
        }

        // Counted as mappings across cohorts: one cohort can hold several cross mappings, and a bare
        // number would leave a reader wondering which it counted.
        return self::row(
            'crossmappings',
            self::INFO,
            self::str('foundcross', (object) ['mappings' => $mappings, 'cohorts' => count($cohorts)]),
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

        $items = [];

        foreach (sync::get_mappings() as $mapping) {
            if (isset($items[$mapping->categoryid])) {
                continue;
            }

            $category = core_course_category::get($mapping->categoryid, IGNORE_MISSING, true);
            if (!$category || !$category->parent) {
                continue;
            }

            $memberid = $DB->get_field_sql(
                'SELECT MIN(userid) FROM {cohort_members} WHERE cohortid = :cohortid',
                ['cohortid' => $mapping->cohortid]
            );
            if (!$memberid || !$member = core_user::get_user($memberid)) {
                continue;
            }

            $parentid = $category->parent;
            while ($parentid) {
                $parent = core_course_category::get($parentid, IGNORE_MISSING, true);
                if (!$parent) {
                    break;
                }
                $parentcontext = context_coursecat::instance($parent->id, IGNORE_MISSING);
                if ($parentcontext && !has_capability(self::CAPABILITY, $parentcontext, $member)) {
                    $items[$category->id] = [
                        'label' => self::str('foundunreachable', (object) [
                            'category' => $category->get_formatted_name(),
                            'parent' => $parent->get_formatted_name(),
                        ]),
                        'url' => new moodle_url(
                            '/local/cohortcategoryrole/index.php',
                            ['filtercategory' => $category->id]
                        ),
                    ];
                    break;
                }
                $parentid = $parent->parent;
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
