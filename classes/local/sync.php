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
use core\task\manager;
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
     * @return stdClass The created mapping.
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

        self::synchronise($mapping);

        return $mapping;
    }

    /**
     * Removes a mapping along with every role assignment it created.
     *
     * @param int $id Mapping id.
     * @return void
     */
    public static function delete_mapping(int $id): void {
        global $DB;

        if (!$mapping = $DB->get_record(self::TABLE, ['id' => $id])) {
            return;
        }

        self::unsynchronise($mapping);
        $DB->delete_records(self::TABLE, ['id' => $mapping->id]);
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
     * Reconciles every mapping on the site.
     *
     * @return array ['assigned' => int, 'unassigned' => int, 'mappings' => int]
     */
    public static function reconcile(): array {
        $totals = ['assigned' => 0, 'unassigned' => 0, 'mappings' => 0];

        foreach (self::get_mappings() as $mapping) {
            $result = self::synchronise($mapping);
            $totals['assigned'] += $result['assigned'];
            $totals['unassigned'] += $result['unassigned'];
            $totals['mappings']++;
        }

        return $totals;
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
            $task = new sync_mapping();
            $task->set_custom_data(['mappingid' => (int) $mapping->id]);
            manager::queue_adhoc_task($task, true);
        }
    }
}
