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
 * Adhoc task that releases the assignments of a deleted mapping.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\task;

use core\task\adhoc_task;
use local_cohortcategoryrole\local\sync;

/**
 * Releases role assignments belonging to a mapping that has already been deleted.
 *
 * Removing a large mapping would take minutes inline, so the mapping row goes immediately - it
 * stops granting at once and cannot be re-applied - and the assignments are released here. The
 * work is addressed by role and item id, so this task needs nothing that the deleted row held.
 *
 * If this task is lost, the reconcile task's orphan sweep releases the same assignments, so the
 * outcome is the same either way and only the timing differs.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class release_assignments extends adhoc_task {
    /**
     * Returns the task name shown in the task logs.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('releasetask', 'local_cohortcategoryrole');
    }

    /**
     * Releases the assignments named in the task's custom data.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->itemid)) {
            return;
        }

        $conditions = [
            'component' => sync::COMPONENT,
            'itemid' => (int) $data->itemid,
        ];
        if (!empty($data->roleid)) {
            $conditions['roleid'] = (int) $data->roleid;
        }

        $count = $DB->count_records('role_assignments', $conditions);
        role_unassign_all($conditions);

        mtrace(sprintf(
            'local_cohortcategoryrole: released %d role assignment(s) from deleted mapping %d.',
            $count,
            $data->itemid
        ));
    }
}
