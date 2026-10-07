# Administrator guide

This guide is for the people who set up and look after the plugin. It doesn't assume you already
know how Moodle permissions work. Section 2 explains the handful of Moodle ideas the plugin is built
on. If you already know roles, capabilities, contexts and cohorts, skip to
[section 3](#3-words-this-plugin-uses).

**Contents**

1. [What the plugin does](#1-what-the-plugin-does)
2. [Moodle concepts in plain language](#2-moodle-concepts-in-plain-language)
3. [Words this plugin uses](#3-words-this-plugin-uses)
4. [How a mapping works](#4-how-a-mapping-works)
5. [What this changes on your site](#5-what-this-changes-on-your-site)
6. [Setting it up](#6-setting-it-up)
7. [The plugin's screens](#7-the-plugins-screens)
8. [Planning your mappings](#8-planning-your-mappings)
9. [What happens when things change](#9-what-happens-when-things-change)
10. [Troubleshooting](#10-troubleshooting)
11. [Uninstalling](#11-uninstalling)
12. [Questions and answers](#12-questions-and-answers)

---

## 1. What the plugin does

You have groups of people, such as staff, partners or a region. You also have areas of your course
catalogue that are meant for each group. This plugin lets each group see its own areas and not the
others.

You tell it: *"members of **this cohort** get **this role** in **this course category**."* It gives
every member that role there, and keeps that true as people join and leave the cohort. The role is
what lets them see the category and the courses in it.

The plugin controls **what people can see in the catalogue**. It doesn't enrol anyone in a course.
Enrolment is still done the normal way, for example by self-enrolment or cohort sync.

---

## 2. Moodle concepts in plain language

### Capabilities: the individual permissions

A **capability** is one specific thing a person may or may not do. Moodle has hundreds, each with a
technical name. The one this plugin is about is:

> **`moodle/category:viewcourselist`**, shown in Moodle as *"View course category and courses within
> it"*.

If you have it in a category, you can see that category in the course catalogue, and the courses
listed in it. If you don't, the category and its courses are hidden from you.

### Roles: bundles of capabilities

A **role** is a named bundle of capabilities, such as *Student*, *Teacher* or *Manager*. Roles are
defined once for the whole site, at *Site administration → Users → Permissions → Define roles*.

For each capability, a role says one of four things:

| Setting     | Meaning |
|-------------|---------|
| **Not set** | This role has no opinion. It falls back to whatever applies further up the tree (see Contexts). |
| **Allow**   | This role grants the capability. |
| **Prevent** | This role does **not** grant the capability. Another role that does can still grant it. |
| **Prohibit**| **Nobody** may have this capability here or below, whatever other roles they hold. It can't be undone further down. |

> **Prevent** means "not from me". **Prohibit** means "not from anyone". This plugin needs
> **Prevent**. A **Prohibit** on `moodle/category:viewcourselist` cancels every mapping.

### Contexts: where a permission applies

Moodle arranges everything in a tree, and each point in the tree is called a **context**:

```
System (the whole site)
├── Category: Staff
│   ├── Category: Staff › Onboarding
│   │   └── Course: Welcome to the company
│   └── Course: Health and safety
└── Category: Partners
    └── Course: Partner product training
```

A role is always given to a person **in a context**. That role applies there and everywhere
beneath it. A role given in *Staff* applies to *Staff*, *Onboarding*, and every course inside them.
It doesn't apply in *Partners*.

Two more details matter here:

- **Overrides.** A role's settings can be changed for one context only. For example, you can set
  *Prevent* for one category without changing the role anywhere else. That is called an
  **override**.
- **How several roles combine.** Most people hold more than one role. For example, every logged-in
  user holds *Authenticated user* at System. Moodle works out each role's answer separately. Then,
  if **any** of a person's roles says *Allow*, they have the capability. The exception is a
  *Prohibit*, which beats everything.

That last point is the key to this plugin. You set *Authenticated user* to *Prevent*, so nobody gets
category visibility by default. Then the plugin gives a cohort a role that *Allows* it, in one
category. The members of that cohort see that category, and nobody else does.

### Role assignments

A **role assignment** is one person, holding one role, in one context. For example: *"Sam holds
Category viewer in Staff"*. This plugin's only job is to create and remove these.

### Cohorts

A **cohort** is a list of people kept by administrators. Some sites call them "site-wide groups"
or "audiences". They are at *Site administration → Users → Accounts → Cohorts*. People can be added
by hand, by CSV upload, or by other plugins and integrations.

Every cohort has two settings that are easy to misunderstand:

- **Context.** A cohort lives either at **System** or in a **course category**. That decides **who
  can manage the cohort**: a category manager can look after cohorts in their own category. It also
  decides **which courses can use it for enrolment**. It **doesn't** give the members anything.
  Putting a cohort in the *Staff* category doesn't let its members see *Staff*. That is what this
  plugin adds.
- **Visible.** Despite the name, this isn't about whether members can see things. A *visible*
  cohort can be **selected by teachers** in any course beneath its context, to enrol all its members.
  For an audience cohort, that is usually not what you want. See
  [Hide your audience cohorts](#hide-your-audience-cohorts).

### Course categories

**Course categories** are the folders of your course catalogue. They can be nested. People browse
the catalogue **from the top down**, so to browse to a category, you need to be able to see every
category above it as well.

---

## 3. Words this plugin uses

The plugin's screens use these words consistently:

| Word | Meaning |
|------|---------|
| **Context** (of a cohort) | Where the cohort lives in Moodle: System or a category. It's Moodle's setting and decides who manages the cohort. On its own it grants nothing. |
| **Mapping** / **mapped** | This plugin's link: *cohort → category → role*. This is what grants visibility. |
| **Role assignment** | One person holding the mapped role in the mapped category. The plugin creates one per member per mapping. |
| **Unmapped** | A cohort with no mapping at all. Its members get nothing from this plugin. |

Each mapping also has a **type**. The type says who effectively controls the grant:

| Type | When | What it means |
|------|------|---------------|
| **System** (blue) | The cohort's context is System | Managed centrally. It can be mapped to any category. |
| **Home** (green) | The cohort's context is a category, and the mapping opens that category or one beneath it | The people who manage the cohort also look after the area it opens. Automatic mapping only ever creates Home mappings. |
| **Cross** (amber) | The cohort's context is a category, and the mapping opens a category **outside** that branch | Whoever can edit the cohort's members decides who can see an area **they don't run**. This is allowed and often intended. It is flagged so the decision is a deliberate one. |
| **Unmapped** (outlined) | No mapping | Nothing is granted. |

The type is worked out fresh every time from the cohort's current context. So if a cohort is moved,
its mappings' types change with it.

---

## 4. How a mapping works

Say you map the cohort *All staff* to the category *Staff*, with the role *Category viewer*:

```
  Cohort "All staff"                      Category "Staff"
  ┌───────────────┐   mapping            ┌───────────────────────────┐
  │ Sam           │ ───────────────────▶ │ Sam   holds Category viewer│
  │ Priya         │   role: Category     │ Priya holds Category viewer│
  │ Alex          │   viewer             │ Alex  holds Category viewer│
  └───────────────┘                      └───────────────────────────┘
```

- **Someone joins the cohort.** They are given the role within a minute or so, at the next cron
  run.
- **Someone leaves the cohort.** Their role there is removed in the same way.
- **The mapping is deleted.** It stops granting at once, and the role assignments it made are
  removed.
- **A role assignment is removed by hand, or something is missed.** The hourly **reconcile** task
  puts everything back to match the mappings.

The plugin only removes role assignments **it** made. If you gave someone the same role in the same
category by hand, that assignment is never touched.

**One role is enough.** You don't need a role per cohort or per category. The *same* Category viewer
role can be used in every mapping, because a role only applies in the category where it's assigned.

---

## 5. What this changes on your site

### The plugin alone changes nothing visible

Moodle's default is that *Authenticated user* (every logged-in person) and *Guest* both **Allow**
`moodle/category:viewcourselist`. So everyone already sees every category. A mapping that grants
visibility to people who already have it makes no visible difference.

To make mappings meaningful, you set that capability to **Prevent** for both *Authenticated user*
and *Guest*. This guide calls that **the baseline**. The plugin's Status tab checks it and can apply
it for you.

> **Both roles matter.** If you prevent it for *Authenticated user* only, anyone not logged in can
> still see every category wherever guest access is on. You won't notice this while you test as a
> logged-in user.

### What the baseline does to everyone else

Once the baseline is in place, **nobody sees any category unless something grants it.** That
includes staff roles, not just learners:

- **Manager, Course creator and Teacher lose category visibility too.** None of the standard roles
  allows the capability itself. They all relied on *Authenticated user*'s default.
- **Losing visibility blocks their category work, not just their view.** Moodle won't let anyone
  create a course in a category, restore into it, move courses in or out, or review its roles,
  cohorts or permissions unless they can see it. The *Permissions* screen will still show them
  *holding* those permissions. That is confusing until you know why.
- **Site administrators are not affected.** They bypass all capability checks.

So, straight after the baseline, **allow `moodle/category:viewcourselist` on every role that manages
categories or courses.** The Status tab's **Category management roles** check lists those roles and
has an **Allow for these roles** button.

Other effects worth knowing about:

| What | Effect |
|------|--------|
| **Learners' own courses** | Unaffected. People always see courses they're enrolled in, on *My courses* and in search. |
| **Self-enrolment** | Blocked, not just hidden, for anyone who can't see the course. A direct enrolment link shared by a colleague won't work for someone outside the audience. |
| **Front page and catalogue listings** | Follow the same rules. Someone outside every audience sees an empty catalogue. |
| **Custom reports** (Report builder) | Category-based reports only show categories the viewer can see. |
| **Not-logged-in visitors** | See no categories, unless you map something for guests. Guests can't be cohort members, so you would grant that by hand. |

### Prevent everywhere, or category by category?

You can apply the baseline in two ways:

- **In the role definition (recommended).** Edit *Authenticated user* and *Guest* once. This covers
  every category, including ones created later. New categories are private until a mapping opens
  them. The **Set to Prevent** button does this.
- **As an override on chosen categories.** Use this if you want most of the catalogue open to
  everyone and only some categories restricted. Be aware that it **fails open**: a category added
  next month is public until someone remembers to override it.

Either way, use **Prevent**, never **Prohibit**.

---

## 6. Setting it up

You need to be a site administrator. Otherwise you need this plugin's *manage* capability, plus the
core permissions for whatever you change: *Define roles* needs `moodle/role:manage`, and *Cohorts*
needs `moodle/cohort:manage`.

### Step 1: Install the plugin

Install it like any other plugin (see the [README](../README.md#installation)). Then open *Site
administration → Users → Accounts → **Cohort category role synchronisation***.

### Step 2: Make sure cron is running

The plugin relies on Moodle's **cron** for background work. Check *Site administration → Server →
Tasks → Scheduled tasks*: *Reconcile cohort category role assignments* should show a recent run. If
cron isn't running, small changes still apply straight away, but nothing repairs drift and large
mappings never apply. The **Reconciliation task** check warns about this.

### Step 3: Create the role you'll map

Go to *Site administration → Users → Permissions → Define roles → Add a new role*.

1. **Use role or archetype:** *No role*. Then select **Continue**.
2. **Short name:** `categoryviewer`. **Custom full name:** *Category viewer*.
3. **Context types where this role may be assigned:** tick **Category** only.
4. Find `moodle/category:viewcourselist` (*View course category and courses within it*) using the
   filter box, and tick **Allow**.
5. Leave everything else as it is. In particular, **don't** allow
   `enrol/category:synchronised`. That would enrol every member into every course in the category.
6. Select **Create this role**.

If people other than site administrators will create mappings, their role must be allowed to assign
*Category viewer*. Set this on the *Allow role assignments* tab of *Define roles*.

> Keep this role minimal. Every capability you add to it is given to every member of every mapped
> cohort, in every mapped category, immediately.

### Step 4: Apply the baseline

Open the plugin's **Status** tab.

1. On **Default category visibility**, select **Set to Prevent**. The confirmation page lists
   exactly what will change, and which roles will lose visibility as a result. Confirm.
2. On **Category management roles**, select **Allow for these roles**, check the list, and confirm.
   Manager, Course creator and Teacher get their visibility back. If you have custom roles that
   create courses or manage categories, they are listed too.

You can make the same changes by hand in *Define roles*. Select **Show advanced** to see the
*Prevent* option.

### Step 5: Prepare your cohorts

At *Cohorts*, create a cohort for each audience, or use existing ones. For each:

- **Context:** *System* if it's managed centrally. Otherwise the category whose managers should
  look after it. See [Planning your mappings](#8-planning-your-mappings).
- **Visible:** **untick** it. See [Hide your audience cohorts](#hide-your-audience-cohorts).
- Add the members.

### Step 6: Create the mappings

On the **Mappings** tab, select **Add mapping**. Choose the cohort, the category and the role, then
save.

A cohort with fewer than 500 members (the default **Defer** limit) gets its role assignments
straight away. A larger one is handed to the task queue. The message says so, and the assignments
appear on the next cron run.

### Step 7: Check it

- The **Status** tab should be all **OK**, apart from any **Note** rows you've reviewed.
- Test as a real member. On the member's profile, select **Log in as** and browse the catalogue.
  They should see their category and not the others.
- Or, on the category, open *Permissions → **Check permissions***, choose a user, and look for
  *View course category and courses within it*.

> **Changes can take up to 10 minutes to appear for someone already logged in.** Moodle remembers
> each user's visible categories for their session. *Log in as* starts a fresh session, so it shows
> the current state.

---

## 7. The plugin's screens

All three tabs are at *Site administration → Users → Accounts → Cohort category role
synchronisation*. A badge on the tabs shows how many Status checks need attention.

### Mappings tab

Lists cohorts and their mappings. A cohort mapped to several categories has one row per mapping.

| Column | Shows |
|--------|-------|
| **Cohort** / **Cohort context** | The cohort, and where it lives. |
| **Cohort visible** | Whether teachers can select the cohort for enrolment. Audience cohorts should say No. |
| **Type** | System, Home, Cross or Unmapped (see [section 3](#3-words-this-plugin-uses)). |
| **Course category** / **Role** | What the mapping grants, and where. |
| **Cohort members** / **Role assignments** | These should match. A difference that persists means the task queue is behind. |
| Actions (icons) | Hover over an icon to see exactly what it will do. See the list below. |

The action icons are:

- **＋ Map** opens the *Add mapping* form already filled in. For an unmapped category cohort, it's
  filled in with the cohort's own category, which makes a Home mapping.
- **🔗 Map to another category** adds a further mapping for the same cohort.
- **🗑 Delete** deletes that one mapping.

**Filters:**

- **Show:** *Mapped* (the default), *Unmapped* or both.
- **Include system cohorts:** whether unmapped *System* cohorts are listed. They are hidden by
  default because most sites have many that are never meant as audiences.
- **Search:** matches cohort or category names.
- **Category**, **Role** and **Type.**

Deleting a mapping asks you to confirm first. It removes the role assignments that mapping made, and
nothing else.

### Status tab

Ten checks of the setup. Each row states its **requirement** and **why** it matters, on healthy
sites as well. That way the page doubles as a reference card. When a check isn't met, a **Found:**
line gives a count, and **View details** lists exactly what was found, with a link to the screen
that fixes each item.

States: **OK**, **Note** (worth reviewing, may be intended), **Warning** (likely wrong), and
**Problem** (definitely wrong).

| Check | Looks for | Fix button |
|-------|-----------|------------|
| Default category visibility | Authenticated user or Guest still allowing the capability | **Set to Prevent** |
| Category management roles | Roles that create courses or manage categories but can't see categories | **Allow for these roles** |
| Prohibit overrides | Any *Prohibit* on the capability, anywhere | — (links to each one) |
| Reconciliation task | The task disabled, or not run for hours | **Run now** (runs it once; cron still needs fixing) |
| Mapped role safety | A mapped role allowing `enrol/category:synchronised` | — (links to the role) |
| Audience cohort visibility | Mapped cohorts that are visible | **Hide these cohorts** |
| Cross mappings | Cross mappings (a **Note**) | — (links to the filtered list) |
| Unmapped category cohorts | Category cohorts with no mapping (a **Note**) | — (links to the filtered list) |
| Orphaned assignments | Assignments the plugin made that no mapping accounts for | **Release them now** |
| Category reachability | Mapped categories whose parent the members can't see | — (links to the category) |

**How fix buttons behave:**

- Every fix button opens a confirmation page listing exactly what will change. Nothing happens until
  you confirm.
- Fixes are **never re-applied automatically**. If you later change something back on purpose, the
  plugin won't quietly undo it.
- The buttons need the same core permissions as the screens they replace.
- Changes are logged through Moodle's normal event logs.

The Status tab works the checks out fresh each time you open it. The badge on the other tabs uses a
summary remembered for up to 5 minutes, or until a mapping changes. So a change made elsewhere, such
as on *Define roles*, can take a few minutes to show in the badge. Opening the Status tab always
refreshes it.

### Settings tab

Links to the three other places you'll need, with what each is for and where it lives in Site
administration:

- **Plugin settings**
- **Cohorts**
- **Define roles**

### Plugin settings

At *Site administration → Plugins → Local plugins → Cohort category role synchronisation*:

| Setting | Default | What it does |
|---------|---------|--------------|
| **Automatically map cohorts created in a category** | Off | A cohort created *inside a category* is mapped to that category straight away. See below. |
| **Role for automatic mappings** | — | The role automatic mappings use, usually Category viewer. |
| **Defer above this many rows** | 500 | Mappings bigger than this are applied by the task queue rather than while you wait, so a large cohort can't time out the page. |

#### Automatic mapping

When this is on, anyone who creates a cohort in a category also opens that category to the
cohort's members. That includes category managers.

- It is **off by default**. Creating access as a side effect of creating a cohort would surprise
  people who didn't ask for it.
- It is **not retroactive**. Turning it on maps nothing that already exists. To adopt it on an
  existing site, filter the Mappings tab to *Unmapped* and map the cohorts you want one by one.
- It only creates **Home** mappings, so it can never create a Cross mapping.
- It only acts when a cohort is created. Moving a cohort later doesn't create or move mappings.

---

## 8. Planning your mappings

### Where should each cohort live?

A cohort's context and its mapping answer **different questions**:

- the **context** decides *who manages the audience*;
- the **mapping** decides *what the audience can see*.

| Situation | Put the cohort in | Resulting type |
|-----------|-------------------|----------------|
| The audience is managed centrally, by HR or a sync | **System** | System |
| A category's own managers look after who is in it | **That category** | Home |
| An audience needs several categories in different branches | **System**, or the lowest category that contains all of them | System or Home |
| An audience kept in one area needs to open an area somewhere else | Its own category, deliberately | Cross |

A cohort **can** be mapped to several categories. That is often the cleanest answer for an audience
that needs, say, *Onboarding* and *Health and safety*.

### Map at the level people browse from

People browse the catalogue from the top down. If you map *Staff › Onboarding* but not *Staff*,
members won't see *Staff*, so they can't browse into *Onboarding*. The grant still works: the
courses appear in course search and open from a direct link. But the category doesn't appear in the
listing.

Either map the parent as well, or map the parent alone. A role given in *Staff* also covers
everything beneath it. The **Category reachability** check finds these cases.

### Hide your audience cohorts

A *visible* cohort can be picked by **any teacher** in any course beneath the cohort's context, to
enrol all its members. Enrolled people always see their own courses. So a teacher could, by
accident, put an audience into a course outside the area it's meant for.

**Untick *Visible* on every cohort you use as an audience.** Hidden cohorts can still be used for
enrolment by people who manage cohorts in that context. The **Audience cohort visibility** check
lists visible mapped cohorts and can hide them for you.

### Keep the mapped role minimal

The mapped role is shared by every mapping. Adding one capability to it adds that capability for
every member of every mapped cohort, in every mapped category, at once. Treat any change to it as a
change to every mapping.

---

## 9. What happens when things change

| When… | What happens |
|-------|--------------|
| Someone is added to or removed from a mapped cohort | Their role assignment is added or removed by the task queue, usually within a minute. |
| Many people are added at once (CSV upload, sync) | The changes are gathered into one background job, not processed one by one. |
| A mapping is created | Members get the role straight away. Above the *Defer* limit, they get it on the next cron run. |
| A mapping is deleted | It stops granting **immediately**. Its role assignments are removed straight away, or on the next cron run above the *Defer* limit. Until then they show as **orphaned assignments**. |
| A cohort is deleted | Its mappings are deleted, and their role assignments removed. |
| A category is deleted | Mappings to it are deleted. Moodle moves cohorts that lived in it **up to the parent category** without telling any plugin. Their mappings' types are recalculated, so a Home mapping can become Cross. |
| The mapped role is deleted | Mappings using it are deleted. |
| A category is moved | Role assignments move with it. If its new parent is hidden from the members, the **Category reachability** check reports it. |
| A cohort's context is changed | Its mappings stay as they are. Their **type** may change, for example from Home to Cross. |
| A category is restored from backup | The restore creates a **new** category. Mappings point at the original and don't follow, so map the restored one again. |
| Someone tries to remove a role assignment the plugin made | Moodle's *Assign roles* screen won't remove assignments that belong to a plugin. If one is removed some other way, the next reconcile puts it back while the mapping exists. |
| Cron stops | Nothing repairs drift, and large mappings don't apply. The **Reconciliation task** check warns after a few hours. |

---

## 10. Troubleshooting

**"I mapped a cohort, but nothing changed. Everyone still sees everything."**
The baseline isn't in place. Check **Default category visibility** on the Status tab. Also check
for a category-level override that sets *Allow* for Authenticated user. Use *Check permissions* on
the category for an unmapped user to find it.

**"A member can't see their category."**

1. Have they had a fresh session? Their old view can last up to 10 minutes. Test with **Log in as**.
2. On the Mappings tab, do *Cohort members* and *Role assignments* match? If not, cron is behind.
   Check the **Reconciliation task** check.
3. Is there a **Prohibit** anywhere? See the **Prohibit overrides** check.
4. Is it a subcategory under a parent they can't see? See the **Category reachability** check. Try
   course search: if the courses appear there, this is the cause.
5. Use *Check permissions* on the category for that user. It shows which role allows or blocks the
   capability.

**"A teacher or manager can't create courses any more."**
They lost category visibility with the baseline. Use **Allow for these roles** on the **Category
management roles** check. If the role isn't listed, it may be a custom role assigned in a category.
Allow `moodle/category:viewcourselist` on it by hand.

**"Someone outside the audience can see a category."**
Use *Check permissions* on that category for that person. Common causes:

- they're enrolled in a course there (people always see their own courses);
- they hold another role that allows it;
- they're a site administrator;
- the category has an override allowing it for *Authenticated user*.

**"People were given a course I didn't expect."**
Probably a teacher used a *visible* audience cohort for cohort-sync enrolment. Hide the cohort (see
**Audience cohort visibility**) and review the course's enrolment methods.

**"Role assignments count is lower than members count."**
The mapping is being applied in the background, or cron isn't running. Select **Run now** on the
**Reconciliation task** check to apply it immediately. Cron still needs fixing.

**"Orphaned assignments won't go away."**
The task queue isn't running. Select **Release them now**, and get cron working.

From the command line, `php public/local/cohortcategoryrole/cli/reconcile.php --checks` prints every
check, and exits with status 1 if any reports a warning or problem. That suits monitoring.
`php public/local/cohortcategoryrole/cli/reconcile.php` reconciles everything immediately.

---

## 11. Uninstalling

Uninstall it at *Site administration → Plugins → Plugins overview*. Uninstalling:

- deletes every mapping;
- removes **every role assignment the plugin created**;
- leaves role assignments made any other way untouched.

It **doesn't** undo changes to role definitions. That covers the baseline and any **Allow for these
roles** fix, whether made by a fix button or by hand. Those are ordinary role settings, and you may
have made them for other reasons. If you want the catalogue open to everyone again, set
`moodle/category:viewcourselist` back to **Allow** for *Authenticated user* and *Guest* yourself. Do
this **before** uninstalling if you don't want a period where only mapped users can see the
catalogue.

---

## 12. Questions and answers

**Does the plugin create a role for each mapping or category?**
No. You create one role, typically *Category viewer*, and map it as often as you like. A role only
applies where it is assigned, so one role covers every category independently.

**Can one cohort be mapped to several categories?**
Yes. Each mapping is listed on its own row.

**Can I map a different role, for example to let a cohort manage a category?**
Yes. Any role that can be assigned in a category context can be mapped. The plugin will keep that
role matching the cohort. Choose carefully: the plugin applies whatever the role contains.

**Does a mapping enrol people in courses?**
No. It only makes the category and its courses *visible*. People still enrol the usual way. Avoid
mapping a role that allows `enrol/category:synchronised`, which **would** enrol them all. The
**Mapped role safety** check catches it.

**Does putting a cohort in a category give its members anything?**
No. A cohort's context only decides who manages it and which courses can use it. Only a mapping
grants anything. That is why the Status tab notes **unmapped category cohorts**: they look like
working audiences but aren't.

**How do I give one extra person access without adding them to the cohort?**
Assign them the role in the category by hand, at *Category → Permissions → Assign roles*. The plugin
never touches assignments it didn't make. Assignments it did make are managed through the cohort:
to remove someone's access, remove them from the cohort.

**What does the plugin store about users?**
Nothing of its own. It stores only the mappings: cohort, category and role. The role assignments
are stored by Moodle's core role system, and appear in Moodle's privacy exports there.

**Is it safe for large sites?**
Yes. Mappings larger than the *Defer* limit are applied in the background. Day-to-day reconciling
costs almost nothing however large the cohorts are. The Status tab is the only expensive page. On a
site with about 300 mappings it takes around a second and a half to load, so it isn't run on the
other tabs. See [Developer notes → Performance](DEVELOPER.md#performance).
