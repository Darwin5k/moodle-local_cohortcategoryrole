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
 * Event observer registration for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\cohort_created',
        'callback'  => '\local_cohortcategoryrole\observer::cohort_created',
    ],
    [
        'eventname' => '\core\event\cohort_member_added',
        'callback'  => '\local_cohortcategoryrole\observer::cohort_member_added',
    ],
    [
        'eventname' => '\core\event\cohort_member_removed',
        'callback'  => '\local_cohortcategoryrole\observer::cohort_member_removed',
    ],
    [
        'eventname' => '\core\event\cohort_deleted',
        'callback'  => '\local_cohortcategoryrole\observer::cohort_deleted',
    ],
    [
        'eventname' => '\core\event\role_deleted',
        'callback'  => '\local_cohortcategoryrole\observer::role_deleted',
    ],
    [
        'eventname' => '\core\event\course_category_deleted',
        'callback'  => '\local_cohortcategoryrole\observer::course_category_deleted',
    ],
];
