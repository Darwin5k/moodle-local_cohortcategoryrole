# Developer notes

These notes cover how the plugin works, which Moodle core behaviours it depends on, what it costs,
and how to test it. For what the plugin does and how administrators use it, see the
[administrator guide](ADMIN_GUIDE.md).

## Contents

- [Design](#design)
- [Code map](#code-map)
- [Data model](#data-model)
- [Synchronisation](#synchronisation)
- [Status checks and fixes](#status-checks-and-fixes)
- [Core behaviours the design relies on](#core-behaviours-the-design-relies-on)
- [Performance](#performance)
- [Security](#security)
- [Testing](#testing)
- [Limits](#limits)

## Design

**Declarative, not event-driven.**

- **Desired state** is the cross product of the mapping table and cohort membership.
- **Actual state** is every `role_assignments` row with `component = 'local_cohortcategoryrole'`.
- The hourly `reconcile` task makes actual state match desired state. Revocation is therefore not a
  separate code path, and a missed event costs freshness rather than access control.

**Observers exist only to reduce latency.**

- A bulk membership change fires one event per user. The observers don't assign inline. They queue
  a **deduplicated** adhoc task per mapping, so 2,000 rapid changes collapse into one sync.

**Every write is component-scoped.**

- Assignments are written with
  `role_assign($roleid, $userid, $contextid, 'local_cohortcategoryrole', $mappingid)`.
- They are removed with `role_unassign_all()`, filtered on the same component and item id.
- So the plugin can never remove an assignment made by hand or by another plugin. Core's *Assign
  roles* screen also refuses to remove component-owned assignments.

Only `role_assignments` has component and itemid columns. `role_capabilities` and `role` don't, so
the plugin never creates roles or capability overrides on its own. The Status tab's fix buttons
change role definitions only on explicit, confirmed request, through core APIs that fire core
events.

**Orphans are swept.**

- `reconcile` also releases any component-owned assignment whose item id matches no mapping.
- This is the backstop for deferred deletions, lost adhoc tasks, and table edits made outside the
  API.

## Code map

| Path | Purpose |
|------|---------|
| `classes/local/sync.php` | The engine. Mapping CRUD, `synchronise`/`unsynchronise` of one mapping, `reconcile` of all, orphan release, adhoc queueing, auto-map, mapping-type classification (`mapping_type()`, and the SQL forms `home_sql()`/`type_sql()`). |
| `classes/local/diagnostics.php` | The ten Status checks, the row model, and the cached summary. |
| `classes/local/fixer.php` | `plan()` and `apply()` for the five fixable checks, plus the capability each needs. |
| `classes/observer.php` | Cohort, role and category event observers. All thin, delegating to `sync`. |
| `classes/task/reconcile.php` | Hourly scheduled task. Calls `sync::reconcile()`. |
| `classes/task/sync_mapping.php`, `release_assignments.php` | Adhoc tasks for deferred and incremental work. |
| `classes/event/mapping_created.php`, `mapping_deleted.php` | Logged events. |
| `classes/form/edit.php` | Add-mapping form. Uses core's AJAX `cohort` element, so it never loads every cohort. |
| `classes/privacy/provider.php` | `null_provider`. Role assignments belong to `core_role`. |
| `index.php` | Tabs: Mappings (filtered and paged list), Status (checks table and per-check detail), Settings (links). |
| `edit.php`, `fix.php` | Add mapping. Fix confirmation page. |
| `settings.php` | Admin external page under *Users → Accounts*. Setting page under *Local plugins*. |
| `cli/reconcile.php` | Reconcile everything. `--checks` prints the Status checks and exits 1 on a warning or error. |
| `db/` | `install.xml`, `access.php`, `events.php`, `tasks.php`, `caches.php`, `uninstall.php`, `upgrade.php`. |

## Data model

The plugin has one table, `local_cohortcategoryrole`:

| Field | Notes |
|-------|-------|
| `id` | Used as `itemid` on every role assignment the mapping creates. |
| `cohortid`, `categoryid`, `roleid` | Unique together. |
| `timecreated`, `timemodified` | |

**There is no "type" column.** System, Home and Cross depend on the cohort's *current* context, and
Moodle changes that silently. When a category is deleted, its cohorts are moved to the parent
context by raw SQL, and no cohort event fires. So the type is always computed:

- in PHP by `sync::mapping_type()`;
- in SQL by `sync::type_sql()`, for filtering and paging.

A test asserts the two agree.

**Home** means the mapped category is the cohort's context category, or a descendant of it, by path
prefix. A cohort at System makes a **System** mapping, whatever the category.

The application cache `status` (TTL 300 s) holds the Status summary, `{problems, worst}`, for the
tab badge. It is invalidated on mapping create and delete, and on every fix. Changes made elsewhere,
such as in *Define roles*, show within the TTL, or at once when the Status tab is opened, because
that tab always runs the checks fresh.

## Synchronisation

`sync::synchronise($mapping)` works like this:

1. Load the cohort's member ids.
2. Load the ids of the users this mapping has assigned.
3. Diff them as keyed arrays (`array_flip` and `array_diff_key`), which is O(n).
4. Assign the missing users, and unassign the extra ones.

Both id lists are loaded whole for one mapping at a time.

**Deferral.** The `deferlimit` setting defaults to 500.

- **Create.** `create_mapping()` inserts the row. If the cohort has more members than the limit, it
  queues `sync_mapping` instead of syncing inline. Either way the caller is told which happened.
- **Delete.** `delete_mapping()` deletes the row **first**, so the mapping stops granting at once
  and can't be re-applied. If more assignments exist than the limit, it queues
  `release_assignments`. Until that runs, those assignments are orphans, and the next reconcile
  sweeps them anyway.

Small mappings stay inline, so a site without working cron still behaves for ordinary use.

**Observers.**

| Event | Action |
|-------|--------|
| `cohort_member_added`, `cohort_member_removed` | Queue a deduplicated `sync_mapping` for each mapping of the cohort. |
| `cohort_created` | Auto-map, if enabled and the cohort's context is a category. |
| `cohort_deleted`, `role_deleted`, `course_category_deleted` | Delete the affected mappings and release their assignments. |

## Status checks and fixes

`diagnostics::run()` returns one row per check, in display order:

`baseline`, `management`, `prohibit`, `task`, `mappedroles`, `cohortvisibility`, `crossmappings`,
`unmapped`, `orphans`, `reachability`.

Each row has these fields:

| Field | Contents |
|-------|----------|
| `id` | The check's id. |
| `state` | `ok`, `info`, `warning` or `error`. |
| `found` | A count string, or `null` when the check is met. |
| `items` | Each with `label` and `url`, for the detail view. |
| `detailurl` | A filtered Mappings list, or `?tab=status&check=<id>`. |
| `fix` | A `fixer` action key, or `null`. |
| `actionurl`, `actionlabel` | A manual link to the right core screen. |

Strings follow a convention. Each check has `check<id>` (its name), `check<id>_help`,
`check<id>_requirement` and `check<id>_why`. `tests/strings_test.php` verifies that every string the
code references exists. It allows for these dynamic prefixes.

**Fixes are deliberately narrow.**

- `fixer::plan($action)` lists exactly what would change. `fixer::apply($action)` does it.
- Each fix needs the *core* capability for the screen it replaces, on top of the plugin's own:
  - `moodle/role:manage` for capability changes;
  - `moodle/cohort:manage` for hiding cohorts.
- Fixes are never re-applied automatically.
- Checks whose remedy is a judgement have links only:
  - `prohibit`, which may be intentional elsewhere;
  - `mappedroles`, where the role may be shared;
  - `reachability`, which is a matter of category structure;
  - `crossmappings`, which is often deliberate.

## Core behaviours the design relies on

All of these were verified on Moodle 5.1. Several are not obvious, and a few are the reason the
Status checks exist.

1. **Capability resolution.** `has_capability()` resolves each role separately, with the deepest
   context override winning *within* a role. Then it ORs the results across roles. Prohibit at any
   level vetoes everything. So a grant from the mapped role beats a Prevent on Authenticated user,
   and a Prohibit anywhere above cancels it.
2. **The default allow.** `moodle/category:viewcourselist` is `CAP_ALLOW` for the `user` and
   `guest` archetypes. Nothing else in core allows it explicitly. Manager, Course creator, Teacher
   and Student all inherit it.
3. **Visibility gates management.** In `core_course_category`, `can_create_course()`, `can_edit()`,
   `can_review_roles()`, `can_review_cohorts()`, `can_review_permissions()`,
   `can_review_filters()`, `can_change_visibility()`, `can_move_courses_into()`,
   `can_move_courses_out_of()` and `can_restore_courses_into()` all start with `is_uservisible()`.
   With the baseline applied and nothing else changed, Manager, Course creator and Teacher could not
   create or restore a course in a category, while *Permissions* still showed them holding those
   capabilities.
4. **Browsing is top-down.** Category listings descend only through visible categories, so a
   visible child of a hidden parent is reachable by search and URL only.
5. **Session caching.** The visible-category set lives in the `coursecat` session cache with a
   600 s TTL. Role assignment doesn't invalidate it.
6. **Category deletion moves cohorts.** The cohorts are moved to the parent context by raw SQL, with
   no cohort event. This is why the mapping type is never stored.
7. **Cohort sync availability.** `cohort_get_available_cohorts()` offers every **visible** cohort
   in the course's parent chain. It checks `moodle/cohort:view` against the cohort's context, not
   the course's. So any editing teacher beneath a visible audience cohort can enrol its members.
8. **Uninstall doesn't touch role assignments.** `uninstall_plugin()` drops tables and capability
   definitions but leaves `role_assignments`. `db/uninstall.php` therefore calls
   `role_unassign_all(['component' => 'local_cohortcategoryrole'])`.
9. **Enrolment.** `/enrol/index.php` refuses users who can't see the course and aren't enrolled.
   Course search skips the visibility check for a user's own courses.
10. **`enrol/category:synchronised`.** A role with this capability, assigned in a category, enrols
    every holder into every course in that category, through `enrol_category`.

## Performance

**Role assignment.** Each `role_assign()` costs about 5 ms. That covers an existence check, an
insert, user cache invalidation, a category enrolment cache purge, and a logged event.

| 5,000-member cohort | Inline | Deferred |
|---------------------|--------|----------|
| Create mapping | 23.5 s | 0.02 s |
| Delete mapping | 21.6 s | 0.01 s |

**Steady state.**

- A reconcile with nothing to do is **9 queries**, whatever the cohort size.
- Incremental cost tracks the number of changes, not the size of the cohort.
- A membership change costs **0.07 ms and one query** more than on an unmapped cohort, for the
  deduplicated adhoc task.

**Status tab.** Measured on Moodle 5.1 / MySQL with 306 mappings, each in its own category with
one member:

| Check | Queries |
|-------|---------|
| Nine of the ten checks | 1 to 6 each |
| `reachability` | 2 per mapped subcategory with members (warm), more on a cold cache |
| `diagnostics::run()`, cold | ~1,520 queries, ~1.3 s |

- **Why reachability costs most.** It must resolve real capabilities for a real member. The
  accessdata loads for one sample member per mapped category dominate the cost. It batches the
  category and user loads, and memoizes per (user, category).
- **Where the checks run.** They run only on the Status tab, and on demand from the CLI. The other
  tabs read the cached summary.
- **Mappings tab.** It pages in SQL. Member and assignment counts are fetched only for the cohorts
  and mappings on the current page.

## Security

- **Capability.** `local/cohortcategoryrole:manage`, at system context, granted to the Manager
  archetype. Its risk bitmask (`RISK_SPAM | RISK_PERSONAL | RISK_XSS`) matches core's
  `moodle/role:assign`, because holding it amounts to assigning roles.
- **Role choice.** The add-mapping form offers roles assignable at category level. Its validation
  then rejects any role that `get_assignable_roles()` doesn't allow the current user to assign in
  the chosen category. So the plugin can't be used to assign a role the user couldn't assign
  directly.
- **Fixes.** Each fix needs the corresponding core capability as well, as described above.
- **Requests.** Every state-changing request needs `sesskey` and a confirmation step.
- **Privacy.** `null_provider`. The mapping table holds no personal data. Role assignments are
  exported and deleted by `core_role`.

## Testing

PHPUnit (55 tests):

```bash
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite local_cohortcategoryrole_testsuite
```

| Test | Covers |
|------|--------|
| `sync_test` | Mapping CRUD, deferral, component scoping (manual assignments survive), logged events, type classification (PHP and SQL agree, follows a moved cohort), summary invalidation, and the uninstall releasing only its own assignments. |
| `reconcile_test` | Convergence from a corrupted state, idempotence, the orphan sweep, and the scheduled task. |
| `observer_test` | Every observer, and a bulk membership change queuing one task. |
| `automap_test` | Off by default, System cohorts ignored, no role configured, not retroactive, Home only. |
| `visibility_test` | End-to-end catalogue visibility through `core_course_category`: only the mapped category is revealed, leaving the cohort hides it again, and a hidden parent blocks browsing. |
| `diagnostics_test` | Every check's problem and healthy states, including the management roles losing visibility. |
| `fixer_test` | Every fix's plan and apply, and the core capabilities each requires. |
| `strings_test` | Every `get_string(..., 'local_cohortcategoryrole')` in the code exists, and no string is unused. |

Coding style: `phpcs --standard=moodle` is clean. The GitHub Actions workflow in
`.github/workflows/ci.yml` runs the full
[moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) suite on PostgreSQL and MariaDB:

- phplint, phpmd, phpcs, phpdoc;
- validate, savepoints, mustache, grunt;
- PHPUnit.

**Lang string literals.** `strings_test` scans for literal component names. Always write
`'local_cohortcategoryrole'` in `get_string()` calls. A variable or constant hides the call from
the test.

## Limits

- **Moodle Workplace is untested.** Workplace's multi-tenancy adds tenant-scoped categories and
  cohorts, and its own catalogue features. The plugin uses only core APIs and should behave the same
  within a tenant. That hasn't been verified, nor has how Workplace's cross-tenant capabilities
  interact with category visibility. Test on a Workplace staging site before production.
- **Backup and restore.** Mappings are not backed up with a category. A restored category has a new
  id, and must be mapped again.
- **Very large cohorts.** Member ids for one mapping are loaded into memory at once. That is fine
  into the hundreds of thousands. Beyond that, the sync would need chunking.
- **Session cache.** Users already logged in may see the old category list for up to 10 minutes
  after a change. That is core behaviour, as above.
- **Status summary staleness.** The tab badge can lag changes made outside the plugin by up to
  5 minutes.
- **Mapped role edits.** Changing the mapped role changes every mapping at once. Only the
  `enrol/category:synchronised` case is detectable. Other escalations have no unambiguous wrong
  answer.
