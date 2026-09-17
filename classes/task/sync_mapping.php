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
 * Adhoc task that synchronises a single mapping.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\task;

use core\task\adhoc_task;
use dml_missing_record_exception;
use local_cohortcategoryrole\local\sync;

/**
 * Synchronises one mapping outside the request that triggered it.
 *
 * Queued by the event observers so that a bulk cohort membership change does not do thousands
 * of role assignments inline. Identical queued tasks are collapsed by the task API, so a change
 * affecting many users results in a single run.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_mapping extends adhoc_task {
    /**
     * Returns the task name shown in the task logs.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('syncmappingtask', 'local_cohortcategoryrole');
    }

    /**
     * Synchronises the mapping named in the task's custom data.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->mappingid)) {
            return;
        }

        try {
            $mapping = sync::get_mapping((int) $data->mappingid);
        } catch (dml_missing_record_exception $e) {
            // The mapping was deleted between queueing and running; nothing left to do.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return;
        }

        $result = sync::synchronise($mapping);

        mtrace(sprintf(
            'local_cohortcategoryrole: mapping %d synchronised, %d assignment(s) added, %d removed.',
            $mapping->id,
            $result['assigned'],
            $result['unassigned']
        ));
    }
}
