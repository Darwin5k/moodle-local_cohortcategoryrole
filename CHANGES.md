# Changelog

## 0.3.0 (2026-09-20)

### Fixed

- **Large mappings no longer time out the request.** Assignments cost about 5 ms each, so granting
  a 5,000-member cohort took 23 seconds inline and a 100,000-member one would take eight minutes —
  past any web server's limit, leaving the grant half applied with no feedback. Mappings covering
  more members than the new **Defer above this many rows** setting (default 500) are now applied by
  the task queue: creating one takes 0.02 s instead of 23 s, and deleting one 0.01 s instead of
  21 s. Smaller mappings stay inline so sites without working cron still function.

### Added

- Deleting a large mapping removes the mapping row immediately — so it stops granting at once and
  cannot be re-applied — and releases its assignments through a new `release_assignments` task.
- **Orphan sweep** in the reconcile task, releasing any assignment this plugin owns that no mapping
  accounts for. This is what makes deferred deletion safe: a lost task cannot strand access.
- A configuration check reporting orphaned assignments that persist, which means the queue is not
  running and that access is still live.

## 0.2.0 (2026-09-17)

### Added

- **Configuration checks** panel on the admin screen. Every way this plugin can be misconfigured
  fails silently, so the checks look for them directly: the default allow still being in place on
  Authenticated user or Guest, a `Prohibit` anywhere on the capability, the reconcile task being
  disabled or cron not running, a mapped role that mass-enrols its holders, a cohort able to grant
  access outside its own area, and a mapped category that cannot be browsed to.
- `db/uninstall.php`, releasing every role assignment the plugin created. Core's
  `uninstall_plugin()` never touches `role_assignments`, so uninstalling previously left every
  mapped user holding their category role permanently.
- `mapping_created` and `mapping_deleted` events, so changes to who can see what appear in the site
  log — including deletions that cascade from a removed cohort, role or category.

### Changed

- Cohort and category pickers are now searchable autocompletes rather than plain selects, for sites
  with many cohorts.
- The static baseline notice is replaced by the checks panel, which reports the same problem only
  when it actually applies, and names the role responsible.

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
