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
 * Scheduled reconciliation task for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\task;

use core\task\scheduled_task;
use local_cohortcategoryrole\local\sync;

/**
 * Makes every mapping's role assignments match its cohort membership.
 *
 * This is the correctness mechanism for the plugin. It runs regardless of whether any event
 * fired, so bulk imports that bypass the cohort API, or an observer that failed midway, are
 * repaired on the next run.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile extends scheduled_task {
    /**
     * Returns the task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('reconciletask', 'local_cohortcategoryrole');
    }

    /**
     * Runs the reconciliation.
     *
     * @return void
     */
    public function execute(): void {
        $totals = sync::reconcile();

        mtrace(sprintf(
            'local_cohortcategoryrole: reconciled %d mapping(s), %d assignment(s) added, %d removed, '
                . '%d orphan(s) released.',
            $totals['mappings'],
            $totals['assigned'],
            $totals['unassigned'],
            $totals['orphans']
        ));
    }
}
