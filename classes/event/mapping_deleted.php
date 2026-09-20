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
 * Mapping deleted event.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\event;

use core\event\base;
use moodle_url;

/**
 * Fired when a mapping is removed, by an administrator or by a cascade.
 *
 * Deletion revokes access from everyone the mapping covered, so it matters more for auditing than
 * creation does. Cascades from a deleted cohort, role or category fire this too, which is how the
 * log explains access that disappeared without anyone visiting this plugin's screen.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mapping_deleted extends base {
    /**
     * Initialises the event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_cohortcategoryrole';
    }

    /**
     * Returns the event name shown in log reports.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventmappingdeleted', 'local_cohortcategoryrole');
    }

    /**
     * Returns a plain text description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        $description = "The user with id '{$this->userid}' deleted the mapping of the cohort with id " .
            "'{$this->other['cohortid']}' to the role with id '{$this->other['roleid']}' " .
            "in the course category with id '{$this->other['categoryid']}'";

        if (!empty($this->other['deferred'])) {
            return $description . ', queueing the release of its role assignments.';
        }

        return $description . ", releasing {$this->other['unassigned']} role assignment(s).";
    }

    /**
     * Returns the page this event relates to.
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/local/cohortcategoryrole/index.php');
    }
}
