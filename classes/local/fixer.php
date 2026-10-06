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
 * One-click fixes offered by the status report.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cohortcategoryrole\local;

use context_system;

/**
 * Plans and applies the fixes the status report offers.
 *
 * Every fix is something an administrator could do by hand, and runs only when one asks: there is
 * a confirmation page listing exactly what will change first, and nothing here is ever re-applied
 * automatically. That distinction matters for the capability fixes. role_capabilities has no
 * component column, so the plugin cannot tell its own overrides from an administrator's; a fix
 * that quietly re-applied itself would silently revert an administrator's later decision. A fix
 * the administrator clicks is simply the same change they would make in Define roles.
 *
 * Detection is shared with diagnostics, so a fix changes exactly what its check reported.
 *
 * @package    local_cohortcategoryrole
 * @copyright  2026 Robert Bellamy <darwin5k@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixer {
    /** @var array Fix action => language string for its button. Spelled out so strings_test sees them. */
    public const ACTIONS = [
        'baseline' => 'fixbaseline',
        'management' => 'fixmanagement',
        'cohortvisibility' => 'fixcohortvisibility',
        'orphans' => 'fixorphans',
        'reconcile' => 'fixreconcile',
    ];

    /**
     * Returns the button label for a fix.
     *
     * @param string $action
     * @return string
     */
    public static function label(string $action): string {
        return get_string(self::ACTIONS[$action], 'local_cohortcategoryrole');
    }

    /**
     * Returns the core capability an administrator needs, beyond managing this plugin, to apply a fix.
     *
     * The plugin's own capability is not enough to change role definitions or cohorts: a fix must
     * never let someone do through this screen what they could not do on the core one.
     *
     * @param string $action
     * @return string|null
     */
    public static function required_capability(string $action): ?string {
        switch ($action) {
            case 'baseline':
            case 'management':
                return 'moodle/role:manage';
            case 'cohortvisibility':
                return 'moodle/cohort:manage';
            default:
                return null;
        }
    }

    /**
     * Can the current user apply this fix?
     *
     * @param string $action
     * @return bool
     */
    public static function can_apply(string $action): bool {
        if (!array_key_exists($action, self::ACTIONS)) {
            return false;
        }
        $capability = self::required_capability($action);

        return $capability === null || has_capability($capability, context_system::instance());
    }

    /**
     * Describes what a fix would change, without changing anything.
     *
     * @param string $action
     * @return array ['changes' => string[], 'notes' => string[]]
     */
    public static function plan(string $action): array {
        $changes = [];
        $notes = [];

        switch ($action) {
            case 'baseline':
                foreach (diagnostics::baseline_roles_allowing() as $role) {
                    $changes[] = self::capability_change($role, CAP_ALLOW, CAP_PREVENT);
                }
                // Preventing the default takes visibility from every role that inherited it.
                // Say so here, rather than let the next check be a surprise.
                $affected = array_map('role_get_name', diagnostics::management_roles_lacking_visibility());
                if ($changes && $affected) {
                    $notes[] = get_string('fixnotebaseline', 'local_cohortcategoryrole', implode(', ', $affected));
                }
                break;

            case 'management':
                foreach (diagnostics::management_roles_lacking_visibility() as $role) {
                    $changes[] = self::capability_change($role, self::current_permission($role->id), CAP_ALLOW);
                }
                break;

            case 'cohortvisibility':
                foreach (diagnostics::visible_mapped_cohorts() as $cohort) {
                    $changes[] = get_string('fixchangehide', 'local_cohortcategoryrole', format_string($cohort->name));
                }
                break;

            case 'orphans':
                if ($count = sync::count_orphans()) {
                    $changes[] = get_string('fixchangeorphans', 'local_cohortcategoryrole', $count);
                }
                break;

            case 'reconcile':
                $changes[] = get_string('fixchangereconcile', 'local_cohortcategoryrole', count(sync::get_mappings()));
                $notes[] = get_string('fixnotereconcile', 'local_cohortcategoryrole');
                break;
        }

        return ['changes' => $changes, 'notes' => $notes];
    }

    /**
     * Applies a fix.
     *
     * @param string $action
     * @return string[] Descriptions of the changes made.
     */
    public static function apply(string $action): array {
        global $CFG;

        $done = [];
        $systemid = context_system::instance()->id;

        switch ($action) {
            case 'baseline':
                foreach (diagnostics::baseline_roles_allowing() as $role) {
                    assign_capability(diagnostics::CAPABILITY, CAP_PREVENT, $role->id, $systemid, true);
                    $done[] = self::capability_change($role, CAP_ALLOW, CAP_PREVENT);
                }
                break;

            case 'management':
                foreach (diagnostics::management_roles_lacking_visibility() as $role) {
                    $from = self::current_permission($role->id);
                    assign_capability(diagnostics::CAPABILITY, CAP_ALLOW, $role->id, $systemid, true);
                    $done[] = self::capability_change($role, $from, CAP_ALLOW);
                }
                break;

            case 'cohortvisibility':
                require_once($CFG->dirroot . '/cohort/lib.php');
                foreach (diagnostics::visible_mapped_cohorts() as $cohort) {
                    $cohort->visible = 0;
                    cohort_update_cohort($cohort);
                    $done[] = get_string('fixchangehide', 'local_cohortcategoryrole', format_string($cohort->name));
                }
                break;

            case 'orphans':
                if ($released = sync::release_orphans()) {
                    $done[] = get_string('fixchangeorphans', 'local_cohortcategoryrole', $released);
                }
                break;

            case 'reconcile':
                \core_php_time_limit::raise();
                $totals = sync::reconcile();
                $done[] = get_string('fixdonereconcile', 'local_cohortcategoryrole', (object) $totals);
                break;
        }

        return $done;
    }

    /**
     * Describes one capability change in words.
     *
     * @param \stdClass $role
     * @param int $from
     * @param int $to
     * @return string
     */
    protected static function capability_change(\stdClass $role, int $from, int $to): string {
        return get_string('fixchangecapability', 'local_cohortcategoryrole', (object) [
            'role' => role_get_name($role),
            'capability' => diagnostics::CAPABILITY,
            'from' => diagnostics::permission_name($from),
            'to' => diagnostics::permission_name($to),
        ]);
    }

    /**
     * The role's current system-level permission for the capability.
     *
     * @param int $roleid
     * @return int
     */
    protected static function current_permission(int $roleid): int {
        global $DB;

        $permission = $DB->get_field('role_capabilities', 'permission', [
            'roleid' => $roleid,
            'capability' => diagnostics::CAPABILITY,
            'contextid' => context_system::instance()->id,
        ]);

        return $permission === false ? CAP_INHERIT : (int) $permission;
    }
}
