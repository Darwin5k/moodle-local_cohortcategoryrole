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
 * Synchronisation logic for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\local;

use context_coursecat;
use context_system;
use core\task\manager;
use local_cohortcategoryrole\event\mapping_created;
use local_cohortcategoryrole\event\mapping_deleted;
use local_cohortcategoryrole\task\release_assignments;
use local_cohortcategoryrole\task\sync_mapping;
use stdClass;

/**
 * Computes and applies the desired set of category role assignments.
 *
 * The plugin is declarative: desired state is the cross product of its mappings and the
 * membership of the mapped cohorts, and actual state is every role assignment tagged with
 * this plugin's component name. Reconciliation makes the second match the first, which means
 * revocation is not a separate code path and a missed event cannot leave access behind.
 *
 * Every assignment is tagged with the component name and the mapping id, so the plugin can
 * never disturb a role assignment made by hand or by another plugin.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync {
    /** @var string Component name tagged onto every role assignment this plugin creates. */
    public const COMPONENT = 'local_cohortcategoryrole';

    /** @var string Name of the mapping table. */
    public const TABLE = 'local_cohortcategoryrole';

    /** @var int Members above which work is queued rather than done in the request. */
    public const DEFAULT_DEFER_LIMIT = 500;

    /**
     * Returns the number of rows above which synchronisation is deferred to the task queue.
     *
     * Each assignment costs roughly five milliseconds, so a cohort of any real size would run past
     * the web server's time limit if it were granted inline. Small mappings stay inline so that a
     * site with broken cron still works for ordinary use.
     *
     * @return int
     */
    public static function get_defer_limit(): int {
        $limit = (int) get_config('local_cohortcategoryrole', 'deferlimit');

        return $limit > 0 ? $limit : self::DEFAULT_DEFER_LIMIT;
    }

    /**
     * Returns a single mapping.
     *
     * @param int $id Mapping id.
     * @return stdClass
     */
    public static function get_mapping(int $id): stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Returns mappings, optionally filtered.
     *
     * @param array $conditions Optional field => value conditions.
     * @return stdClass[] Mapping records keyed by id.
     */
    public static function get_mappings(array $conditions = []): array {
        global $DB;
        return $DB->get_records(self::TABLE, $conditions, 'id ASC');
    }

    /**
     * Creates a mapping and immediately applies it.
     *
     * @param int $cohortid Cohort id.
     * @param int $categoryid Course category id.
     * @param int $roleid Role id.
     * @return stdClass The created mapping, with a transient ->deferred flag saying whether the
     *                  grant was queued rather than applied immediately.
     */
    public static function create_mapping(int $cohortid, int $categoryid, int $roleid): stdClass {
        global $DB;

        $now = time();
        $mapping = (object) [
            'cohortid'     => $cohortid,
            'categoryid'   => $categoryid,
            'roleid'       => $roleid,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $mapping->id = $DB->insert_record(self::TABLE, $mapping);

        // Granting a large cohort takes minutes, which would time out the administrator's request
        // and leave the grant half applied with no feedback. Queue it instead.
        $members = $DB->count_records('cohort_members', ['cohortid' => $cohortid]);
        $mapping->deferred = $members > self::get_defer_limit();

        if ($mapping->deferred) {
            self::queue_sync((int) $mapping->id);
        } else {
            self::synchronise($mapping);
        }

        mapping_created::create([
            'objectid' => $mapping->id,
            'context' => context_system::instance(),
            'other' => [
                'cohortid' => $cohortid,
                'categoryid' => $categoryid,
                'roleid' => $roleid,
            ],
        ])->trigger();

        return $mapping;
    }

    /**
     * Removes a mapping along with every role assignment it created.
     *
     * @param int $id Mapping id.
     * @return bool True if the release was queued rather than done immediately.
     */
    public static function delete_mapping(int $id): bool {
        global $DB;

        if (!$mapping = $DB->get_record(self::TABLE, ['id' => $id])) {
            return false;
        }

        $owned = count(self::get_assigned_userids($mapping));
        $deferred = $owned > self::get_defer_limit();

        if ($deferred) {
            // Remove the mapping first so it stops granting and cannot be re-applied, then release
            // the assignments in the background. They are addressed by role and item id, so the
            // task does not need the mapping row - and the reconcile task's orphan sweep will
            // release them anyway if the task never runs.
            $DB->delete_records(self::TABLE, ['id' => $mapping->id]);
            self::queue_release((int) $mapping->roleid, (int) $mapping->id);
            $unassigned = 0;
        } else {
            $unassigned = self::unsynchronise($mapping);
            $DB->delete_records(self::TABLE, ['id' => $mapping->id]);
        }

        $event = mapping_deleted::create([
            'objectid' => $mapping->id,
            'context' => context_system::instance(),
            'other' => [
                'cohortid' => (int) $mapping->cohortid,
                'categoryid' => (int) $mapping->categoryid,
                'roleid' => (int) $mapping->roleid,
                'unassigned' => $unassigned,
                'deferred' => $deferred,
            ],
        ]);
        $event->add_record_snapshot(self::TABLE, $mapping);
        $event->trigger();

        return $deferred;
    }

    /**
     * Deletes every mapping matching the given conditions, unassigning their roles first.
     *
     * @param array $conditions Field => value conditions.
     * @return int Number of mappings deleted.
     */
    public static function delete_mappings(array $conditions): int {
        $count = 0;
        foreach (self::get_mappings($conditions) as $mapping) {
            self::delete_mapping((int) $mapping->id);
            $count++;
        }
        return $count;
    }

    /**
     * Makes the role assignments owned by one mapping match its cohort membership.
     *
     * @param stdClass $mapping Mapping record.
     * @return array ['assigned' => int, 'unassigned' => int]
     */
    public static function synchronise(stdClass $mapping): array {
        global $DB;

        $result = ['assigned' => 0, 'unassigned' => 0];

        $context = context_coursecat::instance($mapping->categoryid, IGNORE_MISSING);
        if (!$context) {
            // The category is gone; release anything this mapping still owns.
            $result['unassigned'] = self::unsynchronise($mapping);
            return $result;
        }

        $desired = $DB->get_fieldset_select(
            'cohort_members',
            'userid',
            'cohortid = :cohortid',
            ['cohortid' => $mapping->cohortid]
        );
        $actual = self::get_assigned_userids($mapping, (int) $context->id);

        foreach (array_diff($desired, $actual) as $userid) {
            role_assign($mapping->roleid, $userid, $context->id, self::COMPONENT, $mapping->id);
            $result['assigned']++;
        }

        foreach (array_diff($actual, $desired) as $userid) {
            role_unassign_all([
                'userid'    => $userid,
                'roleid'    => $mapping->roleid,
                'contextid' => $context->id,
                'component' => self::COMPONENT,
                'itemid'    => $mapping->id,
            ]);
            $result['unassigned']++;
        }

        return $result;
    }

    /**
     * Removes every role assignment owned by one mapping.
     *
     * Filtering on component and item id means only this plugin's own assignments are touched,
     * wherever they are - so this stays correct even if the category has been moved or removed.
     *
     * @param stdClass $mapping Mapping record.
     * @return int Number of assignments removed.
     */
    public static function unsynchronise(stdClass $mapping): int {
        $count = count(self::get_assigned_userids($mapping));

        role_unassign_all([
            'roleid'    => $mapping->roleid,
            'component' => self::COMPONENT,
            'itemid'    => $mapping->id,
        ]);

        return $count;
    }

    /**
     * Reconciles every mapping on the site, and releases anything no mapping owns.
     *
     * @return array ['assigned' => int, 'unassigned' => int, 'mappings' => int, 'orphans' => int]
     */
    public static function reconcile(): array {
        $totals = ['assigned' => 0, 'unassigned' => 0, 'mappings' => 0, 'orphans' => 0];

        foreach (self::get_mappings() as $mapping) {
            $result = self::synchronise($mapping);
            $totals['assigned'] += $result['assigned'];
            $totals['unassigned'] += $result['unassigned'];
            $totals['mappings']++;
        }

        $totals['orphans'] = self::release_orphans();

        return $totals;
    }

    /**
     * Returns the SQL selecting assignments this plugin owns that no mapping accounts for.
     *
     * @return string
     */
    protected static function orphan_where(): string {
        return "ra.component = :component
                AND NOT EXISTS (SELECT 1 FROM {local_cohortcategoryrole} m WHERE m.id = ra.itemid)";
    }

    /**
     * Counts role assignments tagged with this plugin that no mapping owns.
     *
     * @return int
     */
    public static function count_orphans(): int {
        global $DB;

        return (int) $DB->count_records_sql(
            'SELECT COUNT(1) FROM {role_assignments} ra WHERE ' . self::orphan_where(),
            ['component' => self::COMPONENT]
        );
    }

    /**
     * Releases role assignments this plugin owns that no mapping accounts for.
     *
     * Deleting a large mapping queues its release rather than doing it inline, so this is the
     * safety net that makes that safe: if the queued task is lost, or any future bug strands
     * assignments, the next reconciliation releases them. Nothing else can produce these rows,
     * because only this plugin writes its own component name.
     *
     * @return int Number of assignments released.
     */
    public static function release_orphans(): int {
        global $DB;

        $itemids = $DB->get_fieldset_sql(
            'SELECT DISTINCT ra.itemid FROM {role_assignments} ra WHERE ' . self::orphan_where(),
            ['component' => self::COMPONENT]
        );

        $released = 0;
        foreach ($itemids as $itemid) {
            $released += $DB->count_records('role_assignments', [
                'component' => self::COMPONENT,
                'itemid' => $itemid,
            ]);
            role_unassign_all(['component' => self::COMPONENT, 'itemid' => $itemid]);
        }

        return $released;
    }

    /**
     * Returns the ids of users currently holding this mapping's role assignment.
     *
     * @param stdClass $mapping Mapping record.
     * @param int|null $contextid Restrict to one context, or null for any context.
     * @return array User ids.
     */
    public static function get_assigned_userids(stdClass $mapping, ?int $contextid = null): array {
        global $DB;

        $conditions = [
            'roleid'    => $mapping->roleid,
            'component' => self::COMPONENT,
            'itemid'    => $mapping->id,
        ];
        if ($contextid !== null) {
            $conditions['contextid'] = $contextid;
        }

        return $DB->get_fieldset_select(
            'role_assignments',
            'userid',
            'roleid = :roleid AND component = :component AND itemid = :itemid'
                . ($contextid !== null ? ' AND contextid = :contextid' : ''),
            $conditions
        );
    }

    /**
     * Queues a low latency synchronisation for every mapping that uses the given cohort.
     *
     * Role assignment is deliberately not done inline in the event observers: a bulk membership
     * change fires one event per user, and doing the work here would multiply that cost. Queued
     * adhoc tasks with identical custom data are collapsed into one by the task API.
     *
     * @param int $cohortid Cohort id.
     * @return void
     */
    public static function queue_sync_for_cohort(int $cohortid): void {
        foreach (self::get_mappings(['cohortid' => $cohortid]) as $mapping) {
            self::queue_sync((int) $mapping->id);
        }
    }

    /**
     * Queues a synchronisation of one mapping.
     *
     * Queueing with the duplicate check means repeated calls collapse into a single pending task.
     *
     * @param int $mappingid Mapping id.
     * @return void
     */
    public static function queue_sync(int $mappingid): void {
        $task = new sync_mapping();
        $task->set_custom_data(['mappingid' => $mappingid]);
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Queues the release of the assignments belonging to a mapping that has already been removed.
     *
     * Addressed by role and item id rather than by mapping, because the mapping row is gone.
     *
     * @param int $roleid Role the assignments use.
     * @param int $itemid The deleted mapping's id.
     * @return void
     */
    public static function queue_release(int $roleid, int $itemid): void {
        $task = new release_assignments();
        $task->set_custom_data(['roleid' => $roleid, 'itemid' => $itemid]);
        manager::queue_adhoc_task($task, true);
    }
}
