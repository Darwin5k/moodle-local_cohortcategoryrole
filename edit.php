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
 * Creates a cohort category role mapping.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_cohortcategoryrole\form\edit as edit_form;
use local_cohortcategoryrole\local\sync;

admin_externalpage_setup('local_cohortcategoryrole');

$indexurl = new moodle_url('/local/cohortcategoryrole/index.php');

$mform = new edit_form(new moodle_url('/local/cohortcategoryrole/edit.php'));

if ($mform->is_cancelled()) {
    redirect($indexurl);
}

if ($data = $mform->get_data()) {
    sync::create_mapping((int) $data->cohortid, (int) $data->categoryid, (int) $data->roleid);
    redirect($indexurl, get_string('mappingcreated', 'local_cohortcategoryrole'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('addmapping', 'local_cohortcategoryrole'));
$mform->display();
echo $OUTPUT->footer();
