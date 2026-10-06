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

$string['actioncohorts'] = 'Manage cohorts';
$string['actiondefineroles'] = 'Define roles';
$string['actiondelete'] = 'Delete the mapping of {$a->cohort} to {$a->category}';
$string['actionmaphome'] = 'Map {$a->cohort} to {$a->category}, its context category';
$string['actionmapother'] = 'Map {$a} to another category';
$string['actionmappings'] = 'Review mappings';
$string['actionmapsystem'] = 'Map {$a} to a category';
$string['actionreviewcross'] = 'Review cross mappings';
$string['actionscheduledtasks'] = 'Scheduled tasks';
$string['actionviewunmapped'] = 'View unmapped cohorts';
$string['addmapping'] = 'Add mapping';
$string['assignmentcount'] = 'Role assignments';
$string['automap'] = 'Automatically map cohorts created in a category';
$string['automap_desc'] = 'When a cohort is created inside a course category, immediately map it to that category using the role below. Enabling this does not map cohorts that already exist. Off by default.';
$string['automaprole'] = 'Role for automatic mappings';
$string['automaprole_desc'] = 'The role assigned by automatically created mappings. Choose the least powerful role that grants what the audience needs - usually one allowing only moodle/category:viewcourselist.';
$string['category'] = 'Course category';
$string['category_help'] = 'Members of the cohort are assigned the selected role in this category context, which makes the category and its courses visible to them.

Category browsing walks the tree from the top down, so a category is only reachable by browsing if every category above it is also visible to the user. If you map a subcategory whose parent is hidden, the courses will still be found by course search but the category will not appear in the category listing.';
$string['checkbaseline'] = 'Default category visibility';
$string['checkbaseline_help'] = 'Core allows moodle/category:viewcourselist for both the Authenticated user and Guest archetypes, so out of the box everyone can see every category and a mapping grants access the user already had.

Override it to Prevent on both roles. Preventing it on Authenticated user alone still leaves every category visible to anonymous visitors wherever guest access is enabled - a hole that is invisible while testing as a logged-in user.

Never use Prohibit: it cannot be overridden at a deeper context and would cancel every grant this plugin makes.';
$string['checkcohortvisibility'] = 'Audience cohort visibility';
$string['checkcohortvisibility_help'] = 'A visible cohort is offered to every course beneath its context for cohort sync enrolment, and the capability check runs against the cohort\'s own context rather than the course\'s. So any teacher below it can select the audience and enrol every member into their course.

Because enrolled users always see their own courses, that also sidesteps the catalogue restriction the mapping exists to create.

Edit the cohort and untick Visible. Only holders of moodle/cohort:view at the cohort\'s own context can then use it.';
$string['checkcrossmappings'] = 'Cross mappings';
$string['checkcrossmappings_help'] = 'Mappings whose cohort\'s context is one category but which open a category outside that branch.

These are supported and often deliberate - an audience kept in one area may need to open another. They are reported because anyone who can edit the cohort, through moodle/cohort:assign in its context, can decide who sees an area they do not run, and nothing on the cohort screens shows it.

If a cohort needs categories across the tree, consider moving it to the lowest category that contains all of them, or to System. Its mappings then become Home or System mappings and stop appearing here. Automatic mapping only ever creates Home mappings.';
$string['checkmanagement'] = 'Category management roles';
$string['checkmanagement_help'] = 'No core role allows moodle/category:viewcourselist explicitly - Manager, Course creator, Teacher and Student all inherit it from Authenticated user. Preventing it there takes it from them too.

That matters because every category management operation is gated behind being able to see the category: creating a course, restoring into it, moving courses in or out, and reviewing its roles, cohorts, permissions or filters. Holders of the roles listed here still appear to have those permissions on the Permissions screen but cannot use them.

Only site administrators are unaffected, because doanything bypasses the check. Allow moodle/category:viewcourselist on each role listed.';
$string['checkmappedroles'] = 'Mapped role safety';
$string['checkmappedroles_help'] = 'A role allowing enrol/category:synchronised mass-enrols everyone holding it into every course in the category it is assigned in.

That is almost never what a role intended to grant catalogue visibility should do. Remove the capability from the role, or map a different role.';
$string['checkorphans'] = 'Orphaned assignments';
$string['checkorphans_help'] = 'Role assignments created by this plugin that no mapping accounts for.

A few are expected for a short while after deleting a large mapping, because the release runs in the background, and the next reconciliation clears them. A number that persists means the task queue is not running and the access those assignments grant is still live.';
$string['checkprohibit'] = 'Prohibit overrides';
$string['checkprohibit_help'] = 'Prohibit cannot be overridden at a deeper context, so a single one anywhere above a mapped category silently cancels the mapping for everyone it covers.

Use Prevent instead. Prevent means this role does not grant the capability, and loses to another role that does. Prohibit means nobody may hold it, ever, and vetoes every role.';
$string['checkreachability'] = 'Category reachability';
$string['checkreachability_help'] = 'Category listings are built from the top down and stop at the first category the user cannot see, so a mapped subcategory under a hidden parent is granted but unreachable by browsing.

The grant is real: the courses are still found by course search and by direct link. Map the parent category as well, or map at the level users browse from.';
$string['checksheading'] = 'Status';
$string['checktask'] = 'Reconciliation task';
$string['checktask_help'] = 'The scheduled task is what guarantees role assignments match cohort membership. The event observers only reduce latency, so if the task stops running a missed event leaves stale access in place with nothing to repair it.

It also releases orphaned assignments left by a deferred deletion, and applies mappings too large to have been granted in the request that created them.';
$string['checkunmapped'] = 'Unmapped category cohorts';
$string['checkunmapped_help'] = 'Cohorts whose context is a course category but which have no mapping at all.

Membership of these cohorts grants nothing, because a cohort\'s context only decides who can manage it and which courses can use it for enrolment - it never affects what members can see. That makes them easy to mistake for a working audience.

This is a note rather than a warning: a cohort may sit in a category purely for enrolment or delegation. Use the link to list them, each with a button to map it to its own category.';
$string['cohort'] = 'Cohort';
$string['cohortcategoryrole:manage'] = 'Manage cohort category role mappings';
$string['cohortcontext'] = 'Cohort context';
$string['cohortvisible'] = 'Cohort visible';
$string['columncheck'] = 'Check';
$string['columnlocation'] = 'Where it lives';
$string['columnpage'] = 'Page';
$string['columnpurpose'] = 'What it is for';
$string['columnresult'] = 'Result';
$string['columnstate'] = 'State';
$string['columntype'] = 'Type';
$string['columntype_help'] = 'System: the cohort\'s context is System, so it is managed centrally and can be mapped to any category.

Home: the cohort\'s context is a category, and the mapped category is that category or one of its subcategories. Whoever manages the cohort also looks after the area it opens. Automatic mapping creates Home mappings.

Cross: the cohort\'s context is a category, and the mapped category is outside that branch. Whoever manages the cohort decides who can see an area they do not run, so it deserves a deliberate decision.

Unmapped: the cohort has no mapping, so its members gain nothing from it.

The type is worked out from the cohort\'s current context every time, so it can change when a cohort is moved - including when Moodle moves cohorts to the parent category after a category is deleted.';
$string['confirmdelete'] = 'Are you sure you want to delete this mapping? The role assignments it created will be removed.';
$string['deferlimit'] = 'Defer above this many rows';
$string['deferlimit_desc'] = 'Mappings covering more members than this are applied by the task queue instead of immediately, so that granting or releasing a large cohort cannot time out the request. Smaller mappings are applied straight away, which keeps the plugin usable on a site whose cron is not running.';
$string['deletemapping'] = 'Delete mapping';
$string['errorduplicate'] = 'A mapping for this cohort, category and role already exists.';
$string['errorrolenotassignable'] = 'The selected role cannot be assigned in a course category context. Add Category to the role\'s assignable context types first.';
$string['eventmappingcreated'] = 'Cohort category role mapping created';
$string['eventmappingdeleted'] = 'Cohort category role mapping deleted';
$string['filterany'] = 'Any';
$string['filterapply'] = 'Apply';
$string['filterclear'] = 'Clear filters';
$string['filterincludesystem'] = 'Include system cohorts';
$string['filternoresults'] = 'No mappings match these filters.';
$string['filtersearch'] = 'Search cohort or category';
$string['filtershow'] = 'Show';
$string['linkcohorts'] = 'Cohorts';
$string['linkcohortslocation'] = 'Site administration > Users > Accounts > Cohorts';
$string['linkcohortspurpose'] = 'Create cohorts, set their context, choose whether they are visible, and manage their members.';
$string['linkdefineroles'] = 'Define roles';
$string['linkdefineroleslocation'] = 'Site administration > Users > Permissions > Define roles';
$string['linkdefinerolespurpose'] = 'Adjust the role you map, prevent the default category visibility, and give category management roles visibility back.';
$string['linkpluginsettings'] = 'Plugin settings';
$string['linkpluginsettingslocation'] = 'Site administration > Plugins > Local plugins';
$string['linkpluginsettingspurpose'] = 'Automatic mapping of new category cohorts, the role it uses, and when large mappings are deferred to the task queue.';
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
$string['resultallcansee'] = 'All management roles can see categories';
$string['resultallhidden'] = 'All mapped cohorts hidden';
$string['resultallmapped'] = 'Every category cohort has a mapping';
$string['resultallreachable'] = 'All mapped categories reachable';
$string['resultbaselineprevented'] = 'Prevented for Authenticated user and Guest';
$string['resultcrosscount'] = '{$a->count} cross mapping(s): {$a->cohorts}';
$string['resultnocross'] = 'No cross mappings';
$string['resultnonefound'] = 'None found';
$string['resultnotapplicable'] = 'Not applicable';
$string['resultorphancount'] = '{$a} assignment(s) with no mapping';
$string['resultstillallowed'] = 'Still allowed for {$a}';
$string['resulttaskdisabled'] = 'Disabled';
$string['resulttasknever'] = 'Never run';
$string['resulttaskran'] = 'Last ran {$a} ago';
$string['resultunmappedcount'] = '{$a} with no mapping';
$string['returnto'] = 'Return to:';
$string['role'] = 'Role';
$string['settingsintro'] = 'These pages belong to other areas of Site administration. Each link takes you there; the plugin settings page links back here, and the others can be reached again from Site administration.';
$string['showboth'] = 'Mapped and unmapped';
$string['showmapped'] = 'Mapped';
$string['showunmapped'] = 'Unmapped';
$string['stateerror'] = 'Problem';
$string['stateinfo'] = 'Note';
$string['stateok'] = 'OK';
$string['statewarning'] = 'Warning';
$string['statussummary'] = '{$a} configuration check(s) need attention - see the Status tab.';
$string['syncmappingtask'] = 'Synchronise a cohort category role mapping';
$string['systemcohort'] = 'System';
$string['tabmappings'] = 'Mappings';
$string['tabsettings'] = 'Settings';
$string['tabstatus'] = 'Status';
$string['typecross'] = 'Cross';
$string['typehome'] = 'Home';
$string['typesystem'] = 'System';
$string['unmappedbadge'] = 'Unmapped';
