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
 * withholds the wrong access. So this is written as a report rather than a list of complaints:
 * every check returns a row whatever its state, because knowing a thing was checked and passed is
 * as useful as being told it failed.
 *
 * Each row carries a short result only. The reasoning lives in the matching {id}_help language
 * string, shown behind a help icon, so the screen stays readable without losing the detail.
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

    /**
     * Runs every check and returns one row each, in reading order.
     *
     * @return stdClass[] Each with ->id, ->state, ->summary and optionally ->actionurl/->actionlabel.
     */
    public static function run(): array {
        return [
            self::check_baseline(),
            self::check_management_roles(),
            self::check_prohibit(),
            self::check_task(),
            self::check_mapped_roles(),
            self::check_cohort_visibility(),
            self::check_cohort_scope(),
            self::check_unmapped(),
            self::check_orphans(),
            self::check_reachability(),
        ];
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
     * Builds one report row.
     *
     * @param string $id Check identifier, also the language string prefix.
     * @param string $state One of the class constants.
     * @param string $summary Short result, already translated.
     * @param moodle_url|null $actionurl Where to go to deal with it.
     * @param string|null $actionlabel Already translated link text.
     * @return stdClass
     */
    protected static function row(
        string $id,
        string $state,
        string $summary,
        ?moodle_url $actionurl = null,
        ?string $actionlabel = null
    ): stdClass {
        return (object) [
            'id' => $id,
            'state' => $state,
            'summary' => $summary,
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
     * Is the site-wide default allow still in place for either archetype role?
     *
     * @return stdClass
     */
    protected static function check_baseline(): stdClass {
        global $DB;

        $allowed = [];
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
                $allowed[] = role_get_name($role);
            }
        }

        if (!$allowed) {
            return self::row('baseline', self::OK, self::str('resultbaselineprevented'));
        }

        return self::row(
            'baseline',
            self::WARNING,
            self::str('resultstillallowed', implode(', ', $allowed)),
            new moodle_url('/admin/roles/manage.php'),
            self::str('actiondefineroles')
        );
    }

    /**
     * Roles that administer categories but can no longer see them.
     *
     * @return stdClass
     */
    protected static function check_management_roles(): stdClass {
        global $DB;

        if (!self::baseline_is_prevented()) {
            return self::row('management', self::OK, self::str('resultnotapplicable'));
        }

        $gated = ['moodle/course:create', 'moodle/category:manage', 'moodle/restore:restorecourse'];
        [$insql, $params] = $DB->get_in_or_equal($gated, SQL_PARAMS_NAMED, 'cap');
        $params['allow'] = CAP_ALLOW;

        $sql = "SELECT DISTINCT rc.roleid
                  FROM {role_capabilities} rc
                 WHERE rc.capability $insql AND rc.permission = :allow";

        $affected = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $grants = $DB->record_exists('role_capabilities', [
                'roleid' => $record->roleid,
                'capability' => self::CAPABILITY,
                'permission' => CAP_ALLOW,
            ]);
            if ($grants) {
                continue;
            }
            if ($role = $DB->get_record('role', ['id' => $record->roleid])) {
                $affected[] = role_get_name($role);
            }
        }

        if (!$affected) {
            return self::row('management', self::OK, self::str('resultallcansee'));
        }

        return self::row(
            'management',
            self::WARNING,
            implode(', ', $affected),
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

        $found = [];
        foreach ($DB->get_records_sql($sql, ['cap' => self::CAPABILITY, 'prohibit' => CAP_PROHIBIT]) as $record) {
            $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
            // Notifications and table cells render raw, so never pass a database value straight
            // through. role_get_name() applies format_string.
            $found[] = role_get_name((object) [
                'id' => $record->roleid,
                'name' => $record->name,
                'shortname' => $record->shortname,
            ]) . ' (' . ($context ? $context->get_context_name(false) : '?') . ')';
        }

        if (!$found) {
            return self::row('prohibit', self::OK, self::str('resultnonefound'));
        }

        return self::row(
            'prohibit',
            self::ERROR,
            implode(', ', $found),
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
            return self::row('task', self::OK, self::str('resultnotapplicable'));
        }

        if ($task->get_disabled()) {
            return self::row(
                'task',
                self::WARNING,
                self::str('resulttaskdisabled'),
                $tasksurl,
                self::str('actionscheduledtasks')
            );
        }

        $lastrun = $task->get_last_run_time();
        if (!$lastrun) {
            return self::row(
                'task',
                self::INFO,
                self::str('resulttasknever'),
                $tasksurl,
                self::str('actionscheduledtasks')
            );
        }

        $ago = self::str('resulttaskran', format_time(time() - $lastrun));

        if ((time() - $lastrun) > 3 * HOURSECS) {
            return self::row('task', self::WARNING, $ago, $tasksurl, self::str('actionscheduledtasks'));
        }

        return self::row('task', self::OK, $ago);
    }

    /**
     * A mapped role that mass-enrols its holders is almost never intended.
     *
     * @return stdClass
     */
    protected static function check_mapped_roles(): stdClass {
        global $DB;

        $dangerous = [];
        foreach (self::mapped_roleids() as $roleid) {
            $mass = $DB->record_exists('role_capabilities', [
                'roleid' => $roleid,
                'capability' => 'enrol/category:synchronised',
                'permission' => CAP_ALLOW,
            ]);
            if ($mass && $role = $DB->get_record('role', ['id' => $roleid])) {
                $dangerous[] = role_get_name($role);
            }
        }

        if (!$dangerous) {
            return self::row('mappedroles', self::OK, self::str('resultnonefound'));
        }

        return self::row(
            'mappedroles',
            self::ERROR,
            implode(', ', $dangerous),
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
        global $DB;

        $visible = [];
        foreach (self::mapped_cohorts() as $cohort) {
            if ($cohort->visible) {
                $visible[] = format_string($cohort->name);
            }
        }

        if (!$visible) {
            return self::row('cohortvisibility', self::OK, self::str('resultallhidden'));
        }

        return self::row(
            'cohortvisibility',
            self::WARNING,
            implode(', ', $visible),
            new moodle_url('/cohort/index.php'),
            self::str('actioncohorts')
        );
    }

    /**
     * A cohort granting a category outside its own area is a delegation leak.
     *
     * @return stdClass
     */
    protected static function check_cohort_scope(): stdClass {
        global $DB;

        $outside = [];
        foreach (sync::get_mappings() as $mapping) {
            if (!$cohort = $DB->get_record('cohort', ['id' => $mapping->cohortid])) {
                continue;
            }

            $cohortcontext = \context::instance_by_id($cohort->contextid, IGNORE_MISSING);
            if (!$cohortcontext || $cohortcontext->contextlevel != CONTEXT_COURSECAT) {
                continue;
            }

            $categorycontext = context_coursecat::instance($mapping->categoryid, IGNORE_MISSING);
            if (!$categorycontext) {
                continue;
            }

            $ancestors = explode('/', trim($categorycontext->path, '/'));
            if (in_array((string) $cohortcontext->id, $ancestors, true)) {
                continue;
            }

            $outside[] = format_string($cohort->name);
        }

        if (!$outside) {
            return self::row('cohortscope', self::OK, self::str('resultallinscope'));
        }

        return self::row(
            'cohortscope',
            self::WARNING,
            implode(', ', array_unique($outside)),
            new moodle_url('/cohort/index.php'),
            self::str('actioncohorts')
        );
    }

    /**
     * Category cohorts with no mapping at all.
     *
     * Not a fault - a cohort may live in a category for enrolment or delegation alone - so this is
     * a note rather than a warning. But membership of such a cohort grants nothing, which is easy to
     * mistake for a working audience, so the count is worth seeing.
     *
     * @return stdClass
     */
    protected static function check_unmapped(): stdClass {
        global $DB;

        $count = $DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {cohort} c
               JOIN {context} ctx ON ctx.id = c.contextid AND ctx.contextlevel = :level
              WHERE NOT EXISTS (SELECT 1 FROM {local_cohortcategoryrole} m WHERE m.cohortid = c.id)",
            ['level' => CONTEXT_COURSECAT]
        );

        if (!$count) {
            return self::row('unmapped', self::OK, self::str('resultallmapped'));
        }

        return self::row(
            'unmapped',
            self::INFO,
            self::str('resultunmappedcount', $count),
            new moodle_url('/local/cohortcategoryrole/index.php', ['show' => 'unmapped']),
            self::str('actionviewunmapped')
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
            return self::row('orphans', self::OK, self::str('resultnonefound'));
        }

        return self::row(
            'orphans',
            self::WARNING,
            self::str('resultorphancount', $orphans),
            new moodle_url('/admin/tool/task/scheduledtasks.php'),
            self::str('actionscheduledtasks')
        );
    }

    /**
     * Mapped categories that members cannot browse to, because an ancestor is hidden.
     *
     * @return stdClass
     */
    protected static function check_reachability(): stdClass {
        global $DB;

        $unreachable = [];

        foreach (sync::get_mappings() as $mapping) {
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
                    $unreachable[] = $category->get_formatted_name();
                    break;
                }
                $parentid = $parent->parent;
            }
        }

        if (!$unreachable) {
            return self::row('reachability', self::OK, self::str('resultallreachable'));
        }

        return self::row(
            'reachability',
            self::WARNING,
            implode(', ', array_unique($unreachable)),
            new moodle_url('/local/cohortcategoryrole/index.php'),
            self::str('actionmappings')
        );
    }

    /**
     * Is the default allow removed from both archetype roles?
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

    /**
     * The distinct roles currently used by mappings.
     *
     * @return int[]
     */
    protected static function mapped_roleids(): array {
        $roleids = [];
        foreach (sync::get_mappings() as $mapping) {
            $roleids[(int) $mapping->roleid] = true;
        }

        return array_keys($roleids);
    }

    /**
     * The distinct cohorts currently used by mappings.
     *
     * @return stdClass[]
     */
    protected static function mapped_cohorts(): array {
        global $DB;

        $cohorts = [];
        foreach (sync::get_mappings() as $mapping) {
            if (isset($cohorts[$mapping->cohortid])) {
                continue;
            }
            if ($cohort = $DB->get_record('cohort', ['id' => $mapping->cohortid])) {
                $cohorts[$mapping->cohortid] = $cohort;
            }
        }

        return $cohorts;
    }
}
