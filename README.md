# Cohort category role synchronisation (local_cohortcategoryrole)

Assigns a role to cohort members **at a course category context**, and keeps those assignments
matching cohort membership.

Moodle ships cohort-to-role synchronisation for three contexts and not the fourth:

| Context  | Plugin              | Ships with  |
|----------|---------------------|-------------|
| Course   | `enrol_cohort`      | Core        |
| User     | `tool_cohortroles`  | Core        |
| System   | `local_cohortrole`  | Third party |
| Category | **this plugin**     | —           |

The intended use is course catalogue visibility: grant a cohort a role holding
`moodle/category:viewcourselist` in one category, and its members can see that category and the
courses in it, while other users cannot.

## Important: the plugin only grants

`moodle/category:viewcourselist` is allowed for the **`user`** and **`guest`** archetypes by
default, so out of the box everyone can already see every category. Moodle resolves a capability
per role — the deepest override wins *within* a role, and the results are OR'd across the roles a
user holds — which means a grant from this plugin's role beats a Prevent on another role, but it
also means **a mapping does nothing until you remove the default allow.**

To make mappings meaningful:

1. Site administration → Users → Permissions → Define roles.
2. Override `moodle/category:viewcourselist` to **Prevent** on **both Authenticated user and
   Guest**, either in the role definitions (site-wide) or as context overrides on the categories
   you want to restrict. Preventing it on Authenticated user alone still leaves every category
   visible to anonymous visitors anywhere guest access is enabled — an easy hole to miss, because
   it is invisible while you are testing as a logged-in user.
3. Create a **Category viewer** role with `moodle/category:viewcourselist` allowed, assignable in
   the **Category** context type. Do not give it `enrol/category:synchronised`, or every holder is
   mass-enrolled into every course in the category.
4. Map cohort → category → Category viewer in this plugin.

### What the baseline does to the rest of the site

Preventing `moodle/category:viewcourselist` is not a small change. **No core role grants that
capability explicitly** — Manager, Course creator, Teacher and Student all inherit it from
Authenticated user. The moment you prevent it there, they lose it too. Only site administrators are
unaffected, because `doanything` bypasses the check.

That matters more than it sounds, because **every category management operation in
`core_course_category` is gated behind being able to see the category**:

```php
public function can_create_course() {
    return $this->is_uservisible() && has_capability('moodle/course:create', $this->get_context());
}
```

The same pattern guards `can_edit()`, `can_review_roles()`, `can_review_cohorts()`,
`can_review_permissions()`, `can_review_filters()`, `can_change_visibility()`,
`can_move_courses_into()`, `can_move_courses_out_of()` and `can_restore_courses_into()`. Verified on
5.1: with the baseline applied and nothing else changed, a Manager, Course creator and Teacher all
reported **no** for seeing the category, creating a course in it, and restoring into it — while the
Permissions screen still showed them holding those capabilities.

**So after applying the baseline, explicitly allow `moodle/category:viewcourselist` on every role
that administers categories** — Manager, Course creator, Teacher, and any custom management role.
The configuration checks panel detects this and names the roles.

Other consequences worth knowing:

- **Self-enrolment is blocked, not merely hidden.** `/enrol/index.php` refuses users who cannot see
  the course and are not already enrolled, so a direct enrolment link shared by a colleague will not
  work for someone outside the audience. That is usually the point, but it surprises people.
- **Already-enrolled users keep full access.** Course search skips the visibility check entirely for
  a user's own courses, and My Courses is unaffected.
- **Front page category and course listings** are built from the same API, so they follow the same
  rules — a user outside every audience sees an empty catalogue.
- **Report builder** honours the capability (`course_category` entity declares it as a required
  capability), so category-based reports narrow per viewer.

### Site-wide Prevent, or per-category?

A single Prevent in the role definition covers every category that exists now *and every category
created later* — new categories are private until a mapping opens them. Per-category context
overrides give finer control, but they are fail-open: a category added next month is public until
someone remembers to override it. Prefer the role definition unless you need some categories to
stay open to everyone.

Use **Prevent**, never **Prohibit**: Prohibit cannot be overridden at a deeper context and vetoes
the grant entirely.

### Category browsing walks the tree

A category listing is built from the top down, and only descends through categories the user can
see. A mapped subcategory whose parent is hidden is therefore still reachable by course search and
by direct URL, but will not appear in the category listing. Map at the level users should browse
from, or map the parent chain as well.

### Visible category lists are cached per session

Moodle caches the set of categories visible to a user in a session cache with a ten minute TTL,
and role assignment does not invalidate it. After a sync, a logged-in user may keep seeing the old
list for up to ten minutes. This is expected; it is not the plugin failing.

## Behaviour at scale

Each role assignment costs about 5 ms — that is core's `role_assign()`, which does an existence
check, an insert, a user cache invalidation, a category enrolment cache purge and a logged event.
Granting a 5,000-member cohort therefore takes around 23 seconds, and a 100,000-member one around
eight minutes. Neither can happen inside an administrator's web request.

So mappings covering more members than **Defer above this many rows** (default 500, under *Plugins →
Local plugins*) are applied by the task queue instead. Creating one returns immediately and reports
that the members will be granted on the next cron run. Deleting one removes the mapping **at once**,
so it stops granting immediately and cannot be re-applied, and releases the existing assignments in
the background.

```
5,000-member cohort      inline      deferred
create mapping           23.5 s      0.02 s
delete mapping           21.6 s      0.01 s
```

Smaller mappings stay inline, so a site whose cron is not running still works for ordinary use.

Deleting a large mapping is therefore eventually consistent: the mapping stops granting instantly,
but the role assignments it created persist until the queue catches up, normally under a minute.
The reconcile task's **orphan sweep** is the backstop — it releases any assignment this plugin owns
that no mapping accounts for, so a lost task cannot strand access permanently, and the checks panel
reports orphans that persist.

Steady state costs nothing worth measuring: a reconciliation with no work to do is **9 queries**
regardless of cohort size, and incremental cost tracks the number of changes rather than the size of
the cohort.

Membership churn is cheap too. Measured against an identical unmapped cohort, this plugin adds
**0.07 ms and one query** per membership change, and 2,000 rapid changes collapse into a single
queued sync.

## Things that can go wrong

Every failure mode of this plugin is silent. Nothing errors; the site just grants or withholds the
wrong access. The **Configuration checks** panel at the top of the plugin's admin screen looks for
the ones that can be detected:

| Problem | Effect | Detected |
|---|---|---|
| Default allow still in place on Authenticated user or Guest | Mappings appear to do nothing; everyone sees everything | ✅ warning |
| `Prohibit` used instead of `Prevent` anywhere | Every grant is silently cancelled | ✅ error |
| Reconcile task disabled, or cron not running | Drift accumulates and nothing repairs it | ✅ warning |
| Mapped role allows `enrol/category:synchronised` | Everyone mapped is mass-enrolled into every course in the category | ✅ error |
| Cohort scoped to one category, mapped to another | Whoever manages that cohort can grant access to an area they don't administer | ✅ warning |
| Mapped subcategory under a hidden parent | Grant works, but users cannot browse to it | ✅ warning |

Checks resolve against a real cohort member where user context matters, so they reflect actual
permissions rather than a guess.

### What the checks cannot catch

**Editing the mapped role.** Adding a capability to your viewer role escalates every mapped user in
every mapped category, instantly and site-wide. The role is shared by design — that is what makes
one role enough for any number of cohorts — so treat changes to it as a change to every mapping at
once. Only the `enrol/category:synchronised` case is detectable, because only that one has an
unambiguous wrong answer.

**Restoring a category from backup.** A restore creates a new category with a new id. Mappings point
at the old id and will not follow. Re-create them against the restored category.

**Very large cohorts.** Synchronisation loads the member id list for one mapping at a time. That is
fine into the hundreds of thousands; beyond that, expect to chunk it.

**Moving a category** is safe — context ids survive a move, so assignments follow it. But the move
may put the category under a different parent, which can make it unbrowsable; the reachability check
will report that after the move.

## Uninstalling

`db/uninstall.php` releases every role assignment the plugin created. This matters: core's
`uninstall_plugin()` drops plugin tables and cleans up capability definitions, but it never touches
`role_assignments`, so without this step every mapped user would keep their category role
permanently with the mapping table gone. Assignments made by hand are left alone, as always.

## Design

Declarative rather than event-driven. Desired state is the cross product of the mappings and
cohort membership; actual state is every role assignment tagged with this plugin's component name.
The scheduled `reconcile` task makes the second match the first, so revocation is not a separate
code path and a missed event costs freshness rather than access control.

Every assignment is written with `role_assign($roleid, $userid, $contextid, 'local_cohortcategoryrole', $mappingid)`
and removed with `role_unassign_all()` filtered on the same component and item id, so the plugin
can never disturb a role assignment made by hand or by another plugin.

Event observers exist only to reduce latency. Because a bulk membership change fires one event per
user, the observers queue a deduplicated adhoc task rather than assigning roles inline.

## Requirements

Moodle 5.1 or later.

## Installation

Copy the plugin to `public/local/cohortcategoryrole/` and run the upgrade:

```bash
php admin/cli/upgrade.php --non-interactive
```

Then follow the baseline steps above, or nothing will appear to change.

## Usage

Site administration → Users → Accounts → **Cohort category role synchronisation**.

## Testing

```bash
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit public/local/cohortcategoryrole/tests
```

## Credits

The component-scoping and observer design follows
[paulholden/moodle-local_cohortrole](https://github.com/paulholden/moodle-local_cohortrole),
which does the same job at the system context. No code was copied, but the safety semantics are
its. Licensed GPLv3, as is that plugin.

## Licence

GNU GPL v3 or later.
