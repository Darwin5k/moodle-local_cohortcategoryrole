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
 * Confirms and applies a fix offered by the status report.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_cohortcategoryrole\local\fixer;

$action = required_param('action', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

admin_externalpage_setup('local_cohortcategoryrole', '', ['action' => $action], '/local/cohortcategoryrole/fix.php');

if (!array_key_exists($action, fixer::ACTIONS)) {
    throw new moodle_exception('invalidparameter', 'debug');
}

// The plugin's own capability is not enough to change role definitions or cohorts.
if ($capability = fixer::required_capability($action)) {
    require_capability($capability, context_system::instance());
}

$statusurl = new moodle_url('/local/cohortcategoryrole/index.php', ['tab' => 'status']);

if ($confirm) {
    require_sesskey();
    $done = fixer::apply($action);

    if (!$done) {
        redirect($statusurl, get_string('fixnothing', 'local_cohortcategoryrole'), null, notification::NOTIFY_INFO);
    }

    redirect(
        $statusurl,
        get_string('fixapplied', 'local_cohortcategoryrole', count($done)) . ' ' . implode('; ', $done),
        null,
        notification::NOTIFY_SUCCESS
    );
}

$plan = fixer::plan($action);

echo $OUTPUT->header();
echo $OUTPUT->heading(fixer::label($action));

if (!$plan['changes']) {
    echo $OUTPUT->notification(get_string('fixnothing', 'local_cohortcategoryrole'), notification::NOTIFY_INFO);
    echo $OUTPUT->continue_button($statusurl);
    echo $OUTPUT->footer();
    die();
}

$message = html_writer::tag('p', get_string('fixconfirmintro', 'local_cohortcategoryrole'))
    . html_writer::alist($plan['changes']);

foreach ($plan['notes'] as $note) {
    $message .= html_writer::div($note, 'alert alert-warning mt-3');
}

echo $OUTPUT->confirm(
    $message,
    new moodle_url('/local/cohortcategoryrole/fix.php', ['action' => $action, 'confirm' => 1, 'sesskey' => sesskey()]),
    $statusurl
);

echo $OUTPUT->footer();
