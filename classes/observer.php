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
 * Event observers for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole;

use core\event\cohort_deleted;
use core\event\cohort_member_added;
use core\event\cohort_member_removed;
use core\event\course_category_deleted;
use core\event\role_deleted;
use local_cohortcategoryrole\local\sync;

/**
 * Keeps role assignments close to current without being the mechanism that guarantees them.
 *
 * These handlers only reduce latency. Correctness is the reconcile task's job, so a missed or
 * failed event costs freshness, not access control.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Queues a synchronisation when a user joins a cohort.
     *
     * @param cohort_member_added $event The event.
     * @return void
     */
    public static function cohort_member_added(cohort_member_added $event): void {
        sync::queue_sync_for_cohort((int) $event->objectid);
    }

    /**
     * Queues a synchronisation when a user leaves a cohort.
     *
     * @param cohort_member_removed $event The event.
     * @return void
     */
    public static function cohort_member_removed(cohort_member_removed $event): void {
        sync::queue_sync_for_cohort((int) $event->objectid);
    }

    /**
     * Removes mappings, and the assignments they created, when their cohort is deleted.
     *
     * @param cohort_deleted $event The event.
     * @return void
     */
    public static function cohort_deleted(cohort_deleted $event): void {
        sync::delete_mappings(['cohortid' => (int) $event->objectid]);
    }

    /**
     * Removes mappings when their role is deleted.
     *
     * @param role_deleted $event The event.
     * @return void
     */
    public static function role_deleted(role_deleted $event): void {
        sync::delete_mappings(['roleid' => (int) $event->objectid]);
    }

    /**
     * Removes mappings when their course category is deleted.
     *
     * @param course_category_deleted $event The event.
     * @return void
     */
    public static function course_category_deleted(course_category_deleted $event): void {
        sync::delete_mappings(['categoryid' => (int) $event->objectid]);
    }
}
