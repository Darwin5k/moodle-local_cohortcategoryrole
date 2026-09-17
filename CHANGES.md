# Changelog

## 0.1.0 (2026-09-16)

Initial release. Requires Moodle 5.1.

### Added

- Mapping of cohort → course category → role, managed from Site administration → Users →
  Accounts → Cohort category role synchronisation.
- Declarative synchronisation: desired state is the cross product of the mappings and cohort
  membership, actual state is every role assignment tagged with this plugin's component name,
  and the hourly `reconcile` scheduled task makes the second match the first.
- Event observers for `cohort_member_added`, `cohort_member_removed`, `cohort_deleted`,
  `role_deleted` and `course_category_deleted`. Membership changes queue a deduplicated adhoc
  task rather than assigning roles inline, so a bulk change fires one sync rather than one per
  user.
- Component and item id scoping on every `role_assign()` / `role_unassign_all()` call, so role
  assignments made by hand or by another plugin are never disturbed.
- PHPUnit coverage for synchronisation, observers, reconciliation convergence and idempotency,
  and the category visibility the plugin exists to produce.

### Notes

- The plugin only grants. `moodle/category:viewcourselist` is allowed by default for both the
  Authenticated user and Guest roles, so mappings do nothing until those defaults are overridden
  to Prevent. See the README.
- Category browsing walks the tree from the top down, so a mapped subcategory under a hidden
  parent is reachable by course search but absent from category listings.
