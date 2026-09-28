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
 * Admin menu entry for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('accounts', new admin_externalpage(
    'local_cohortcategoryrole',
    get_string('pluginname', 'local_cohortcategoryrole'),
    new moodle_url('/local/cohortcategoryrole/index.php'),
    'local/cohortcategoryrole:manage'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_cohortcategoryrole_settings',
        get_string('pluginname', 'local_cohortcategoryrole')
    );
    $ADMIN->add('localplugins', $settings);

    // Only build the role list when the page is actually being rendered - settings.php is also
    // parsed during upgrades, where these queries would be wasted work.
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'local_cohortcategoryrole/automap',
            get_string('automap', 'local_cohortcategoryrole'),
            get_string('automap_desc', 'local_cohortcategoryrole'),
            0
        ));

        $roleoptions = [0 => get_string('choosedots')];
        foreach (\local_cohortcategoryrole\local\sync::get_category_role_options() as $id => $name) {
            $roleoptions[$id] = $name;
        }

        $settings->add(new admin_setting_configselect(
            'local_cohortcategoryrole/automaprole',
            get_string('automaprole', 'local_cohortcategoryrole'),
            get_string('automaprole_desc', 'local_cohortcategoryrole'),
            0,
            $roleoptions
        ));

        $settings->add(new admin_setting_configtext(
            'local_cohortcategoryrole/deferlimit',
            get_string('deferlimit', 'local_cohortcategoryrole'),
            get_string('deferlimit_desc', 'local_cohortcategoryrole'),
            \local_cohortcategoryrole\local\sync::DEFAULT_DEFER_LIMIT,
            PARAM_INT
        ));
    }
}
