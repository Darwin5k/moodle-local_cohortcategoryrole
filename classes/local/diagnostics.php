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
use stdClass;

/**
 * Detects the configuration mistakes that make this plugin fail quietly.
 *
 * Every problem this looks for produces no error and no log entry - the site simply grants or
 * withholds the wrong access. The checks exist so an administrator finds out from the plugin's
 * own screen rather than from a user who saw something they should not have.
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
     * Runs every check and returns the results.
     *
     * @return stdClass[] Each with ->level and ->message.
     */
    public static function run(): array {
        $results = array_merge(
            self::check_baseline(),
            self::check_prohibit(),
            self::check_reconcile_task(),
            self::check_mapped_roles(),
            self::check_privileged_roles(),
            self::check_cohort_scope(),
            self::check_orphans(),
            self::check_reachability()
        );

        if (!$results) {
            $results[] = self::result(self::OK, get_string('checkok', 'local_cohortcategoryrole'));
        }

        return $results;
    }

    /**
     * Builds one result.
     *
     * @param string $level One of the class constants.
     * @param string $message Already translated message.
     * @return stdClass
     */
    protected static function result(string $level, string $message): stdClass {
        return (object) ['level' => $level, 'message' => $message];
    }

    /**
     * Warns when the site still grants category visibility to everybody.
     *
     * Core allows this capability for the user and guest archetypes, so until both are overridden
     * a mapping grants access that the user already had, and appears to do nothing.
     *
     * @return stdClass[]
     */
    protected static function check_baseline(): array {
        global $DB;

        $results = [];
        $systemid = context_system::instance()->id;

        foreach (['user', 'guest'] as $shortname) {
            if (!$role = $DB->get_record('role', ['shortname' => $shortname])) {
                continue;
            }

            $permission = $DB->get_field('role_capabilities', 'permission', [
                'roleid' => $role->id,
                'capability' => self::CAPABILITY,
                'contextid' => $systemid,
            ]);

            if ((int) $permission === CAP_ALLOW) {
                $results[] = self::result(
                    self::WARNING,
                    get_string('checkbaselineallowed', 'local_cohortcategoryrole', role_get_name($role))
                );
            }
        }

        return $results;
    }

    /**
     * Reports any Prohibit on the capability, which vetoes every grant this plugin makes.
     *
     * Prohibit cannot be overridden at a deeper context, so a single one anywhere above a mapped
     * category silently disables the mapping for everyone it covers.
     *
     * @return stdClass[]
     */
    protected static function check_prohibit(): array {
        global $DB;

        $results = [];

        $sql = "SELECT rc.id, rc.contextid, r.id AS roleid, r.name, r.shortname
                  FROM {role_capabilities} rc
                  JOIN {role} r ON r.id = rc.roleid
                 WHERE rc.capability = :cap AND rc.permission = :prohibit";

        $records = $DB->get_records_sql($sql, ['cap' => self::CAPABILITY, 'prohibit' => CAP_PROHIBIT]);

        foreach ($records as $record) {
            $context = \context::instance_by_id($record->contextid, IGNORE_MISSING);
            $results[] = self::result(self::ERROR, get_string(
                'checkprohibit',
                'local_cohortcategoryrole',
                (object) [
                    // Notifications render their message as raw HTML, so a role name must never
                    // reach one straight from the database. role_get_name() applies format_string.
                    'role' => role_get_name((object) [
                        'id' => $record->roleid,
                        'name' => $record->name,
                        'shortname' => $record->shortname,
                    ]),
                    'context' => $context ? $context->get_context_name(false) : '?',
                ]
            ));
        }

        return $results;
    }

    /**
     * Warns when the task that guarantees correctness is disabled or is not running.
     *
     * The observers only reduce latency. If the reconcile task stops, drift accumulates silently.
     *
     * @return stdClass[]
     */
    protected static function check_reconcile_task(): array {
        $task = \core\task\manager::get_scheduled_task(reconcile::class);
        if (!$task) {
            return [];
        }

        if ($task->get_disabled()) {
            return [self::result(self::WARNING, get_string('checkcrondisabled', 'local_cohortcategoryrole'))];
        }

        $lastrun = $task->get_last_run_time();
        if (!$lastrun) {
            return [self::result(self::INFO, get_string('checkcronnever', 'local_cohortcategoryrole'))];
        }

        // The task is scheduled hourly; allow generous slack before complaining.
        if ((time() - $lastrun) > 3 * HOURSECS) {
            return [self::result(self::WARNING, get_string(
                'checkcronstale',
                'local_cohortcategoryrole',
                format_time(time() - $lastrun)
            ))];
        }

        return [];
    }

    /**
     * Warns when a mapped role carries capabilities that make it unsuitable for this purpose.
     *
     * A role holding enrol/category:synchronised mass-enrols every holder into every course in the
     * category, which is rarely what someone wanting a catalogue viewer intended.
     *
     * @return stdClass[]
     */
    protected static function check_mapped_roles(): array {
        global $DB;

        $results = [];
        $seen = [];

        foreach (sync::get_mappings() as $mapping) {
            if (isset($seen[$mapping->roleid])) {
                continue;
            }
            $seen[$mapping->roleid] = true;

            if (!$role = $DB->get_record('role', ['id' => $mapping->roleid])) {
                continue;
            }

            $dangerous = $DB->record_exists('role_capabilities', [
                'roleid' => $role->id,
                'capability' => 'enrol/category:synchronised',
                'permission' => CAP_ALLOW,
            ]);

            if ($dangerous) {
                $results[] = self::result(
                    self::ERROR,
                    get_string('checkmassenrol', 'local_cohortcategoryrole', role_get_name($role))
                );
            }
        }

        return $results;
    }

    /**
     * Warns when a role can manage categories but can no longer see them.
     *
     * Every category management operation in core_course_category - creating a course, restoring
     * into a category, reviewing its roles, cohorts or filters, moving courses in or out - is
     * gated behind is_uservisible(), which needs this capability. No core role grants it
     * explicitly: Manager, Course creator and Teacher all inherit it from Authenticated user. So
     * the moment the baseline prevents it there, those roles silently lose the ability to use
     * permissions they still appear to hold. Only site administrators are unaffected, because
     * doanything bypasses the check.
     *
     * @return stdClass[]
     */
    protected static function check_privileged_roles(): array {
        global $DB;

        // Nothing to warn about while the default allow is still in place.
        $baselineprevented = false;
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
                $baselineprevented = true;
            }
        }

        if (!$baselineprevented) {
            return [];
        }

        // Capabilities whose category level operations are gated behind category visibility.
        $gated = [
            'moodle/course:create',
            'moodle/category:manage',
            'moodle/restore:restorecourse',
        ];

        [$insql, $params] = $DB->get_in_or_equal($gated, SQL_PARAMS_NAMED, 'cap');
        $params['allow'] = CAP_ALLOW;

        $sql = "SELECT DISTINCT rc.roleid, rc.capability
                  FROM {role_capabilities} rc
                 WHERE rc.capability $insql AND rc.permission = :allow";

        $results = [];
        $reported = [];

        foreach ($DB->get_recordset_sql($sql, $params) as $record) {
            if (isset($reported[$record->roleid])) {
                continue;
            }

            // Does the role itself allow the capability? If not, the baseline prevent is the only
            // other source and the holder ends up without it.
            $grants = $DB->record_exists('role_capabilities', [
                'roleid' => $record->roleid,
                'capability' => self::CAPABILITY,
                'permission' => CAP_ALLOW,
            ]);
            if ($grants) {
                continue;
            }

            if (!$role = $DB->get_record('role', ['id' => $record->roleid])) {
                continue;
            }
            $reported[$record->roleid] = true;

            $results[] = self::result(self::WARNING, get_string(
                'checkprivilegedrole',
                'local_cohortcategoryrole',
                (object) [
                    'role' => role_get_name($role),
                    'capability' => $record->capability,
                ]
            ));
        }

        return $results;
    }

    /**
     * Reports role assignments this plugin owns that no mapping accounts for.
     *
     * Deleting a large mapping queues the release rather than doing it inline, so a few orphans
     * shortly after a deletion are expected and the next reconciliation clears them. Orphans that
     * persist mean the queue is not running, and the access they grant is still live.
     *
     * @return stdClass[]
     */
    protected static function check_orphans(): array {
        $orphans = sync::count_orphans();

        if (!$orphans) {
            return [];
        }

        return [self::result(self::WARNING, get_string(
            'checkorphans',
            'local_cohortcategoryrole',
            $orphans
        ))];
    }

    /**
     * Warns when a cohort can grant access to a category outside its own area of control.
     *
     * Cohorts can live in a category context, and anyone holding moodle/cohort:assign there can
     * change their membership. If such a cohort is mapped to a category outside that subtree, the
     * people who administer the cohort's own area can hand out access to an area they do not
     * administer - a delegation leak that is invisible from both screens involved.
     *
     * @return stdClass[]
     */
    protected static function check_cohort_scope(): array {
        global $DB;

        $results = [];

        foreach (sync::get_mappings() as $mapping) {
            if (!$cohort = $DB->get_record('cohort', ['id' => $mapping->cohortid])) {
                continue;
            }

            $cohortcontext = \context::instance_by_id($cohort->contextid, IGNORE_MISSING);
            if (!$cohortcontext || $cohortcontext->contextlevel != CONTEXT_COURSECAT) {
                // System level cohorts are managed site wide, so there is nothing narrower to leak.
                continue;
            }

            $categorycontext = context_coursecat::instance($mapping->categoryid, IGNORE_MISSING);
            if (!$categorycontext) {
                continue;
            }

            // The mapped category must sit at or below the cohort's own context.
            $ancestors = explode('/', trim($categorycontext->path, '/'));
            if (in_array((string) $cohortcontext->id, $ancestors, true)) {
                continue;
            }

            $category = core_course_category::get($mapping->categoryid, IGNORE_MISSING, true);

            $results[] = self::result(self::WARNING, get_string(
                'checkcohortscope',
                'local_cohortcategoryrole',
                (object) [
                    'cohort' => format_string($cohort->name),
                    'cohortcontext' => $cohortcontext->get_context_name(false),
                    'category' => $category ? $category->get_formatted_name() : '?',
                ]
            ));
        }

        return $results;
    }

    /**
     * Warns when a mapped category cannot be browsed to because an ancestor is hidden.
     *
     * The grant is real - course search and direct links work - but category listings are built
     * from the top down and stop at the first category the user cannot see.
     *
     * @return stdClass[]
     */
    protected static function check_reachability(): array {
        global $DB;

        $results = [];

        foreach (sync::get_mappings() as $mapping) {
            $category = core_course_category::get($mapping->categoryid, IGNORE_MISSING, true);
            if (!$category || !$category->parent) {
                continue;
            }

            // Resolve against a real member, so this reflects actual permissions rather than a guess.
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
                    $results[] = self::result(self::WARNING, get_string(
                        'checkunreachable',
                        'local_cohortcategoryrole',
                        (object) [
                            'category' => $category->get_formatted_name(),
                            'parent' => $parent->get_formatted_name(),
                        ]
                    ));
                    break;
                }

                $parentid = $parent->parent;
            }
        }

        return $results;
    }
}
