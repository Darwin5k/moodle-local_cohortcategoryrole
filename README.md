# Cohort category role synchronisation

`local_cohortcategoryrole` · Moodle 5.1 · GPL v3 or later

Give a cohort a role **in a course category**, and keep it matching the cohort's membership. Add
someone to the cohort and they get the role there. Remove them and it goes.

The usual reason for this is **course catalogue visibility**. You can show each audience only the
categories, and the courses in them, that are meant for it. Staff see the staff catalogue, partners
see the partner catalogue, and nobody sees the other's.

Moodle can already sync cohorts to roles in some places, but not in categories:

| Where the role is given | Plugin                | Comes with Moodle |
|-------------------------|-----------------------|-------------------|
| A course (enrolment)    | `enrol_cohort`        | Yes               |
| A user (e.g. parents)   | `tool_cohortroles`    | Yes               |
| The whole site          | `local_cohortrole`    | No, third party   |
| **A course category**   | **this plugin**       | —                 |

## Read this before installing

Out of the box, **every logged-in user and every guest can see every category.** This plugin only
*grants* access. It doesn't take away the default. So a mapping changes nothing until you switch off
that site-wide default.

Switching it off is a deliberate, site-wide change, and it has side effects. In particular, the
Manager, Course creator and Teacher roles lose category visibility, and with it their ability to
create or restore courses in categories, until you give it back to them. The plugin's **Status**
tab detects both steps and offers a one-click fix for each, with a confirmation page. Still, read
[What this changes on your site](docs/ADMIN_GUIDE.md#5-what-this-changes-on-your-site) first.

## Features

- **Mappings** of cohort → course category → role. A cohort can map to several categories.
- **Stays in sync automatically.** Event observers queue a sync when membership changes. An hourly
  reconcile task repairs anything missed, and releases assignments that no mapping accounts for.
- **Never touches assignments it didn't make.** Every assignment is tagged with the plugin's
  component, and only those are ever removed. That includes on uninstall.
- **Mapping types** show who controls each grant:
  - **System**: a centrally managed cohort.
  - **Home**: a category cohort that opens its own branch.
  - **Cross**: a category cohort that opens a category somewhere else.
- **Optional automatic mapping.** Cohorts created inside a category can be mapped to that category
  automatically. This is off by default and is never retroactive.
- **Status tab** with ten configuration checks. Each states its requirement and why it matters,
  and shows what it found. Safe fixes have a confirmation step.
- **Scales.** Large mappings are applied by the task queue, so a request never times out.
  Steady-state reconciliation costs 9 queries whatever the cohort size.
- **CLI:** `cli/reconcile.php` reconciles everything. `--checks` runs the Status checks and exits
  1 on any warning, ready for monitoring.

## Documentation

| Guide | For |
|-------|-----|
| [Administrator guide](docs/ADMIN_GUIDE.md) | Site administrators and client teams. Explains the Moodle concepts involved from scratch, setup step by step, every screen, and troubleshooting. |
| [Developer notes](docs/DEVELOPER.md) | Developers and reviewers. Covers the architecture, the core behaviours the design relies on, performance, testing and limits. |
| [CHANGES.md](CHANGES.md) | Release history. |

## Requirements

- Moodle 5.1 (`2025100600`) or later in the 5.1 series.
- Cron running. The reconcile task is what guarantees correctness.

Moodle Workplace 5.1 has not been tested. See
[Developer notes → Limits](docs/DEVELOPER.md#limits).

## Installation

1. Put the plugin in `public/local/cohortcategoryrole/`. You can unzip it there, or clone it:

   ```bash
   git clone https://github.com/Darwin5k/moodle-local_cohortcategoryrole.git public/local/cohortcategoryrole
   ```

2. Visit *Site administration → Notifications*, or run
   `php admin/cli/upgrade.php --non-interactive`.
3. Follow [Setting it up](docs/ADMIN_GUIDE.md#6-setting-it-up) in the administrator guide.

The plugin is at *Site administration → Users → Accounts → Cohort category role synchronisation*.

## Uninstalling

Uninstalling releases every role assignment the plugin created. Assignments made any other way are
left alone. Undoing the site-wide default change is up to you: see
[Uninstalling](docs/ADMIN_GUIDE.md#11-uninstalling).

## Credits

The component-scoping and observer design follows
[paulholden/moodle-local_cohortrole](https://github.com/paulholden/moodle-local_cohortrole), which
does the same job at the system context. No code was copied.

## Licence

Copyright 2026 Robert Bellamy. Licensed under the [GNU GPL v3](LICENSE) or later.
