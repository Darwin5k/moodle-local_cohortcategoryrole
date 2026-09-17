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
 * Mapping created event.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\event;

use core\event\base;
use moodle_url;

/**
 * Fired when an administrator maps a cohort to a role in a course category.
 *
 * This is a change to who can see what, so it belongs in the site log alongside role assignment
 * changes. Without it there would be no record of who opened a category to which audience.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mapping_created extends base {
    /**
     * Initialises the event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_cohortcategoryrole';
    }

    /**
     * Returns the event name shown in log reports.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventmappingcreated', 'local_cohortcategoryrole');
    }

    /**
     * Returns a plain text description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' mapped the cohort with id " .
            "'{$this->other['cohortid']}' to the role with id '{$this->other['roleid']}' " .
            "in the course category with id '{$this->other['categoryid']}'.";
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
