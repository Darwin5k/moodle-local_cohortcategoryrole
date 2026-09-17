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
 * Uninstall steps for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Releases every role assignment this plugin created.
 *
 * Core's uninstall_plugin() drops the plugin's tables and cleans up its capability definitions,
 * but it never touches role_assignments tagged with the plugin's component. Without this,
 * uninstalling would leave every mapped user holding their category role permanently, with the
 * mapping table gone and nothing left to explain or undo it.
 *
 * Filtering on the component means only this plugin's own assignments are released; anything an
 * administrator assigned by hand survives, exactly as it does during normal operation.
 *
 * @return bool
 */
function xmldb_local_cohortcategoryrole_uninstall(): bool {
    role_unassign_all(['component' => 'local_cohortcategoryrole']);

    return true;
}
