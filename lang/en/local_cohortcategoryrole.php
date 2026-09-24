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
$string['category'] = 'Course category';
$string['category_help'] = 'Members of the cohort are assigned the selected role in this category context, which makes the category and its courses visible to them.

Category browsing walks the tree from the top down, so a category is only reachable by browsing if every category above it is also visible to the user. If you map a subcategory whose parent is hidden, the courses will still be found by course search but the category will not appear in the category listing.';
$string['checkbaselineallowed'] = 'The capability moodle/category:viewcourselist is still allowed for the "{$a}" role at system level, so everyone can already see every category and mappings will appear to do nothing. Override it to Prevent for that role.';
$string['checkcohortscope'] = 'The cohort "{$a->cohort}" belongs to {$a->cohortcontext}, but is mapped to "{$a->category}", which sits outside it. Anyone who can add members to that cohort can therefore grant access to a category they do not administer. Move the cohort to the system context, or map it within its own area.';
$string['checkcrondisabled'] = 'The "Reconcile cohort category role assignments" scheduled task is disabled. Role assignments will drift out of step with cohort membership and nothing will repair them.';
$string['checkcronnever'] = 'The reconciliation task has not run yet. It is the mechanism that guarantees assignments match cohort membership, so confirm cron is running on this site.';
$string['checkcronstale'] = 'The reconciliation task last ran {$a} ago. It is scheduled hourly, so cron may not be running. Until it runs, a missed event leaves stale access in place.';
$string['checkmassenrol'] = 'The mapped role "{$a}" allows enrol/category:synchronised. Everyone holding it will be enrolled into every course in the mapped category, which is almost certainly not intended for a catalogue viewer role. Remove that capability from the role.';
$string['checkok'] = 'No configuration problems detected.';
$string['checkorphans'] = '{$a} role assignment(s) created by this plugin are not accounted for by any mapping. This is normal for a short while after deleting a large mapping, and the next reconciliation clears them. If the number persists, the task queue is not running and that access is still live.';
$string['checkprivilegedrole'] = 'The role "{$a->role}" grants {$a->capability}, but does not allow moodle/category:viewcourselist. Every category management operation - creating a course, restoring into a category, reviewing its roles or cohorts - is gated behind being able to see the category, so holders of this role can no longer use a permission they still appear to have. No core role grants the capability explicitly; they all inherited it from Authenticated user. Allow moodle/category:viewcourselist on this role.';
$string['checkprohibit'] = 'The role "{$a->role}" has moodle/category:viewcourselist set to Prohibit at {$a->context}. Prohibit cannot be overridden at a deeper context, so it silently cancels every grant this plugin makes. Use Prevent instead.';
$string['checksheading'] = 'Configuration checks';
$string['checkunreachable'] = 'Members mapped to "{$a->category}" cannot browse to it, because its parent category "{$a->parent}" is not visible to them. The courses are still reachable by course search and direct link. Map the parent category too, or map at the level users browse from.';
$string['cohort'] = 'Cohort';
$string['cohortcategoryrole:manage'] = 'Manage cohort category role mappings';
$string['confirmdelete'] = 'Are you sure you want to delete this mapping? The role assignments it created will be removed.';
$string['deferlimit'] = 'Defer above this many rows';
$string['deferlimit_desc'] = 'Mappings covering more members than this are applied by the task queue instead of immediately, so that granting or releasing a large cohort cannot time out the request. Smaller mappings are applied straight away, which keeps the plugin usable on a site whose cron is not running.';
$string['deletemapping'] = 'Delete mapping';
$string['errorduplicate'] = 'A mapping for this cohort, category and role already exists.';
$string['errorrolenotassignable'] = 'The selected role cannot be assigned in a course category context. Add Category to the role\'s assignable context types first.';
$string['eventmappingcreated'] = 'Cohort category role mapping created';
$string['eventmappingdeleted'] = 'Cohort category role mapping deleted';
$string['mappingcreated'] = 'Mapping created.';
$string['mappingcreateddeferred'] = 'Mapping created. Its {$a} members will be given the role on the next cron run.';
$string['mappingdeleted'] = 'Mapping deleted.';
$string['mappingdeleteddeferred'] = 'Mapping deleted, so it no longer grants access. Its {$a} existing role assignments will be released on the next cron run.';
$string['mappings'] = 'Cohort category role mappings';
$string['membercount'] = 'Cohort members';
$string['nomappings'] = 'No mappings have been defined yet.';
$string['pluginname'] = 'Cohort category role synchronisation';
$string['privacy:metadata'] = 'The Cohort category role synchronisation plugin stores only the mapping between a cohort, a course category and a role. It stores no personal data of its own; the role assignments it creates are stored by the core role subsystem.';
$string['reconciletask'] = 'Reconcile cohort category role assignments';
$string['releasetask'] = 'Release cohort category role assignments';
$string['role'] = 'Role';
$string['syncmappingtask'] = 'Synchronise a cohort category role mapping';
