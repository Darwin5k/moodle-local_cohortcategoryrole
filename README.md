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
