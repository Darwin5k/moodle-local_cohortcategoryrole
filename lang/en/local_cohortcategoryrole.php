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
 * Language strings for local_cohortcategoryrole.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addmapping'] = 'Add mapping';
$string['assignmentcount'] = 'Role assignments';
$string['baselinenotice'] = 'This plugin only grants visibility. Because the capability moodle/category:viewcourselist is allowed by default for both the Authenticated user and Guest roles, everyone can already see every category, and a mapping will appear to do nothing. To make mappings meaningful, override moodle/category:viewcourselist to Prevent for both of those roles at the system context (or at the categories you want to restrict). Preventing it for Authenticated user alone still leaves every category visible to anonymous visitors wherever guest access is enabled. Never use Prohibit: it cannot be overridden at a deeper context and will break the grant.';
$string['category'] = 'Course category';
$string['category_help'] = 'Members of the cohort are assigned the selected role in this category context, which makes the category and its courses visible to them.

Category browsing walks the tree from the top down, so a category is only reachable by browsing if every category above it is also visible to the user. If you map a subcategory whose parent is hidden, the courses will still be found by course search but the category will not appear in the category listing.';
$string['cohort'] = 'Cohort';
$string['cohortcategoryrole:manage'] = 'Manage cohort category role mappings';
$string['confirmdelete'] = 'Are you sure you want to delete this mapping? The role assignments it created will be removed.';
$string['deletemapping'] = 'Delete mapping';
$string['editmapping'] = 'Edit mapping';
$string['errorduplicate'] = 'A mapping for this cohort, category and role already exists.';
$string['errorrolenotassignable'] = 'The selected role cannot be assigned in a course category context. Add Category to the role\'s assignable context types first.';
$string['mappingcreated'] = 'Mapping created.';
$string['mappingdeleted'] = 'Mapping deleted.';
$string['mappings'] = 'Cohort category role mappings';
$string['membercount'] = 'Cohort members';
$string['nomappings'] = 'No mappings have been defined yet.';
$string['pluginname'] = 'Cohort category role synchronisation';
$string['privacy:metadata'] = 'The Cohort category role synchronisation plugin stores only the mapping between a cohort, a course category and a role. It stores no personal data of its own; the role assignments it creates are stored by the core role subsystem.';
$string['reconciletask'] = 'Reconcile cohort category role assignments';
$string['role'] = 'Role';
$string['syncmappingtask'] = 'Synchronise a cohort category role mapping';
