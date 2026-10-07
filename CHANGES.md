# Changelog

## 0.10.0 (2026-10-07)

### Changed

- **Status rows report counts, not lists.** "3 visible cohort(s)", "1 cross mapping(s) across 1
  cohort(s)". A list of names would swamp the page on a site with hundreds of cohorts; the Status
  page now says how much needs attention and where to look.
- Cross mappings are counted as mappings *across* cohorts, since one cohort can hold several and a
  bare number left it unclear which was being counted.

### Added

- **View details** on every row with a count. Cross mappings and unmapped cohorts link to the
  Mappings list, filtered. Every other row opens a detail view listing each affected item, linked
  to the exact screen that changes it - a role's definition, a cohort's edit page, the permissions
  page of the context holding a Prohibit override - with the row's Fix button alongside.
- `cli/reconcile.php --checks` lists the items under each count, since a terminal has room for them.

## 0.9.0 (2026-10-06)

### Changed

- **Status rows state a requirement.** Each row now says what must be true and why - "Authenticated
  user and Guest must have moodle/category:viewcourselist set to Prevent. Otherwise everyone already
  sees every category, and mappings change nothing." - on every row, met or not, so a healthy site
  reads as a reference card. When a requirement is not met, a **Found:** line says what is wrong.
- Mapping type badges use a distinct, readable colour each: System blue, Home green, Cross amber,
  Unmapped outlined. Boost's secondary grey failed contrast with white text.

### Added

- **Fix buttons** on the Status rows where a fix is safe: *Set to Prevent*, *Allow for these
  roles*, *Hide these cohorts*, *Release them now* and *Run now*. Each opens a confirmation page
  listing exactly what will change, and nothing is applied until confirmed. Capability fixes need
  `moodle/role:manage` and cohort fixes `moodle/cohort:manage` as well as this plugin's capability,
  so the screen never lets anyone do more than the core screens would. Fixes are never re-applied
  automatically, so a later decision by an administrator is never quietly reverted. Changes are
  logged through core's own events. The baseline fix warns which roles will lose category
  visibility as a result.
- Prohibit overrides, mapped role safety and category reachability stay as links only: each is a
  judgement about roles or category structure that a button cannot make.

### Fixed

- `cli/reconcile.php --checks` printed nothing useful since 0.6.0: it still read the old
  `level`/`message` fields. It now prints each check's state, name and requirement, with what was
  found, and **exits 1 when any check reports a warning or a problem**, so monitoring can alert on it.

## 0.8.0 (2026-10-05)

### Added

- **Mapping types: System, Home and Cross**, shown as a badge in a new **Type** column whose help
  icon defines them. *System*: the cohort's context is System, so it is managed centrally. *Home*:
  the cohort's context is a category and the mapped category is that category or beneath it, so the
  same people look after both. *Cross*: the mapped category is outside that branch, so whoever
  manages the cohort decides who can see an area they do not run. Cross takes the warning colour.
  The type is worked out from current data every time, because Moodle silently moves cohorts to the
  parent category when a category is deleted, which can change a mapping's type.
- A **Type** filter, so the Cross mappings can be listed on their own for an audit.
- **Map to another category** on every row, mapped or not, so further mappings for a cohort can be
  added from its own row.

### Changed

- **Row actions are icons**, each with a tooltip naming the actual cohort and category - "Map Sales
  Contractors to Sales Training, its context category" - which doubles as the screen reader label.
- Unmapped rows show their **Unmapped** badge in the Type column rather than the Role column.
- The Status check **Cohort scope** is now **Cross mappings**, and is a note rather than a warning:
  cross mappings are a supported, named type, and a Status badge count should not include them. It
  links to the list filtered to Cross.
- The "outside this category" badge is replaced by the Cross type.

## 0.7.0 (2026-10-05)

### Changed

- **One list for mappings and unmapped cohorts.** The list is now built from cohorts: a cohort with
  mappings shows one row per mapping, and a cohort with none shows a single row. A **Show** filter
  chooses *Mapped* (the default), *Unmapped*, or both. Unmapped rows carry an **Unmapped** badge in
  place of the role, and their own action instead of a delete icon: **Map to this category** for a
  category cohort, which pre-fills both the cohort and its category, or **Map this cohort** for a
  system cohort, which pre-fills the cohort and leaves the category to choose.
- **Unmapped system cohorts are hidden unless asked for**, with an *Include system cohorts* box. A
  system cohort implies no category and many exist for reasons unrelated to this plugin, so listing
  them all by default would be noise. A mapped system cohort is a real grant and always shows.
- "Unmapped" now means a cohort with **no mapping at all**. A category cohort mapped somewhere other
  than its own category is no longer counted as unmapped; the "outside this category" badge covers
  that case.
- The category filter matches the mapped category for a mapping and the context category for an
  unmapped cohort, and its options include categories that hold cohorts as well as mapped ones.
- **Settings tab restored**, now as a page of its own listing Plugin settings, Cohorts and Define
  roles, each with what it is for and where it lives in Site administration, so it is clear these
  open other areas of the site.
- The Status tab's unmapped-cohorts panel and related links are gone. Unmapped category cohorts are
  now a row in the report - a note, not a warning - linking to the list filtered to *Unmapped*.
- The **Cohort belongs to** column is now **Cohort context**, matching the term Moodle uses.
- Shorter help text for **Automatically map cohorts created in a category**, stating up front that
  enabling it does not map cohorts that already exist.

## 0.6.0 (2026-09-30)

### Changed

- **The screen is now two tabs: Mappings and Status.** The checks used to be six full-width alert
  banners stacked above the table, which read as a list of complaints rather than a report.
- **Checks are a report.** Every check reports a row whatever its state, so "all clear" can be
  told apart from "did not run". Each row is a short result, a state badge, a help icon carrying
  the full reasoning, and where relevant a link straight to the screen that fixes it.
- **The Mappings tab holds only mappings**, with a search box and category and role filters. The
  filter options are drawn from the mappings that exist rather than every category on the site, so
  the lists stay short and cannot offer a choice that returns nothing.
- Settings, Cohorts and Define roles moved into a **Related settings and pages** section on the
  Status tab, and the Settings tab was removed - it navigated away from a page whose tabs did not
  follow. The settings page itself gained a link back.

### Fixed

- `get_string('manage')` was not a core string, so the page filled with "invalid string
  identifier" errors for anyone logged in. HTTP status checks could not see this, because the page
  still returned a redirect for anonymous requests.

### Added

- `tests/strings_test.php`, asserting every literal `get_string()` call resolves and that no
  defined string is unused. Check names, help text and state labels are built by concatenation, so
  `diagnostics_test` asserts separately that each check has all three.

## 0.5.0 (2026-09-28)

### Added

- **Automatically map cohorts created in a category** (setting, off by default). A cohort created
  inside a category already states which audience it belongs to; with this on, creating the cohort
  is the whole configuration. Not retroactive, and only ever maps a cohort to its own category, so
  an automatic mapping can never produce the delegation mismatch the checks panel warns about.
- **Role for automatic mappings** setting, listing the roles assignable in a category context.
- Check for **visible mapped cohorts**. A visible cohort can be selected for cohort sync by any
  teacher beneath its context and mass-enrolled into their course, which also sidesteps the
  catalogue restriction, since enrolled users always see their own courses.
- **Category cohorts without a mapping** panel on the mapping list, each with a one-click link to
  create the mapping — so adopting automatic mapping on an existing site is a series of deliberate
  choices rather than one irreversible tick.
- Quick links to Cohorts, Define roles and the plugin's own settings.

### Changed

- The mapping list now shows, for every mapping, which context the cohort belongs to, whether the
  cohort is visible, and a badge when the cohort is mapped outside its own category. Cohort,
  category and role names link to the screens that manage them.
- The role option list is now shared between the form and the settings page rather than duplicated.

## 0.4.0 (2026-09-24)

### Security

- The Prohibit configuration check embedded a role name straight from the database into a
  notification. Notification templates render their message as raw HTML (`{{{ message }}}`), so a
  role named with markup could inject it into the plugin's admin page. Role names now go through
  `role_get_name()`, which applies `format_string()`. Administrator-only and low severity, but a
  genuine injection path.

### Added

- **Check for privileged roles that can no longer see categories.** No core role allows
  `moodle/category:viewcourselist` explicitly — Manager, Course creator and Teacher inherit it from
  Authenticated user — and every category management operation is gated behind category visibility.
  Applying the baseline therefore strips Managers and Course creators of the ability to create
  courses, restore into categories or review their roles and cohorts, while the Permissions screen
  still shows them holding those capabilities. The checks panel now names the affected roles.
- `cli/reconcile.php` for administrators: forces a reconciliation, or prints the configuration
  checks with `--checks`, without waiting for cron.
- README section documenting what the baseline does to the rest of the site, including that
  self-enrolment is blocked rather than merely hidden for courses in invisible categories.

### Changed

- The mapping list no longer runs two count queries per row. Counts come from two grouped queries
  and the table is paged at 50, so the page costs the same with 500 mappings as with five.

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
