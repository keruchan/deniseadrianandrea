# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

EDUPREDICT — "Academic Performance Monitoring and Prediction System." A PHP/MySQL web app for three roles (administrator, instructor, student). **Only the instructor role is substantially built** (class management, meeting-based attendance/participation, activities/quizzes with grouping support). Administrator and student roles are mostly placeholder shells — see "Known Limitations" below before assuming a feature exists.

## Tech Stack & Commands

- **Backend**: Vanilla PHP (no framework), PDO/MySQL. No Composer, no autoloader — every file `require_once`s what it needs.
- **Frontend**: Bootstrap 5.3.3 + Bootstrap Icons 1.11.3 via CDN, Google Fonts (Inter/Manrope) via CDN. One hand-rolled `css/dashboard.css`. Plain JS in `js/dashboard.js` (no framework/bundler/npm).
- **DB**: MySQL/MariaDB, database `edupredict_db`, connection in `config/config.php` (`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`, currently root/no-password local XAMPP).
- **No build step, no test suite, no linter configured.** There is nothing to `npm install`, `composer install`, build, or run tests for. Verify changes by exercising the page in a browser / via HTTP requests against the running XAMPP Apache server.
- **Deploy**: `.github/workflows/deploy.yml` — on push to `master`, FTP-syncs the entire repo as-is to Hostinger (`ccsbsis.com/edutrack`). No build/test gate.
- **Local dev**: XAMPP (Apache + MySQL) serving this directory at `http://localhost/EDUPREDICT`. `APP_BASE_PATH` in `config/config.php` must match the folder name under `htdocs`.

## Routing

`.htaccess` (mod_rewrite) maps pretty URLs to query strings, instructor-only:
```
classes/                                  -> pages/instructor/classes.php
classes/archived                          -> pages/instructor/archived-classes.php
classes/{id}                              -> pages/instructor/classes.php?class_id={id}
classes/{id}/{view}                       -> pages/instructor/classes.php?class_id={id}&view={view}
  view ∈ attendance|activities|group-activities|groupings|quizzes|midterm|finals|participation|analytics|predictions
settings/grading  -> pages/instructor/grading-settings.php
settings/account  -> pages/instructor/account-settings.php
```
All other pages (auth, administrator, student) are hit by direct file path — no rewrite rules for them. Build links with `url_for()` / redirect with `redirect_to()` (`includes/helpers.php`), never hardcode `/EDUPREDICT/...`.

## Folder Structure

```
config/       config.php (PDO + session bootstrap), schema.sql (reference dump, NOT auto-applied),
              create_users.php (seeds default admin/instructor accounts), seed_students.php
includes/     one file per domain (see "Major Modules"), + helpers.php, sidebar_config.php, dashboard_layout.php
pages/
  auth/       login.php, register.php (student self-signup only), logout.php
  administrator/  dashboard.php (placeholder shell)
  instructor/ classes.php (2900+ line monolith — see below), dashboard.php, grading-settings.php,
              account-settings.php, archived-classes.php
  student/    dashboard.php, classes.php (join-by-code)
ajax/         grouping.php — the ONLY AJAX endpoint in the app
css/, js/     dashboard.css/.js (app shell), auth.css, main.css/.js (public landing, pages/index.html)
uploads/, assets/img/   static assets
```

## Authentication & Security Conventions

- Session-based auth: `$_SESSION['id']`, `['role']`, `['name']`. `require_role('instructor'|'administrator'|'student')` in `helpers.php` gates every protected page and redirects by role otherwise.
- Roles come from `user_roles` table (`administrator`/`instructor`/`student`). Each `users` row has exactly one profile row in `administrators`/`instructors`/`students` (1:1 via `user_id`), which is what actually holds the person's name/contact/employee-or-student-no.
- CSRF: `csrf_token($sessionKey)` / `csrf_is_valid()` / `rotate_csrf_token()` — tokens are scoped per form-family by session key (e.g. `csrf_class_manage_token`), rotated after every successful mutation.
- **All forms use `novalidate`.** Server-side PHP validation is authoritative everywhere in this codebase; never rely on HTML5 `required` alone for a new form — mirror the existing pattern (validate in PHP, show inline errors).
- Public registration (`pages/auth/register.php`) only creates **student** accounts. Admin/instructor accounts are provisioned via `config/create_users.php` (a one-off seed script), not self-service.

## Central Layout Architecture

`includes/dashboard_layout.php` → `render_dashboard_page(array $page)` is the **one shared shell** every role page calls into (sidebar, topbar, page header, `<head>`/CDN includes). Two content modes:
- Placeholder pages pass static `cards` (stat tiles) + `widgets` (empty-state panels) arrays — used by all of administrator's dashboard, student's dashboard, and instructor's account-settings.
- Real pages pass a `content` closure that echoes arbitrary markup — used by `classes.php`, `grading-settings.php`.

Sidebar nav is built by `includes/sidebar_config.php`: `instructor_sidebar_menu()` returns a nested array (types: `link`, `group` — collapsible, localStorage-persisted via `data-sidebar-group` — and `section`), rendered by recursive `render_sidebar_*()` functions in `dashboard_layout.php`. When a class is selected, three extra sections appear: **CLASS** (Overview), **ASSESSMENTS** (Course Requirements > Attendance/Participation, Activities, Quizzes, Groupings, Major Exams > Midterm/Finals), **INSIGHTS** (Analytics, Predictions).

## The `classes.php` Monolith

`pages/instructor/classes.php` (~2900 lines) is simultaneously: the class list page, the class create/edit modal host, and the **entire per-class "workspace"** (every class-scoped module). This is the single most important file to understand before touching instructor features.

- **Dispatch**: `render_instructor_class_workspace()` if-chains on `$_GET['view']` to one of `render_attendance_workspace()`, `render_participation_workspace()`, `render_assessment_workspace($pdo, ..., $type)` (shared for both `activities` and `quizzes`), `render_groupings_workspace()` — or falls through to a generic "module placeholder" empty-state. **`group-activities` (legacy, unused route), `midterm`, `finals`, `analytics`, `predictions` all hit that placeholder** — they are registered in nav/routing metadata only, with no real implementation.
- **Mutations**: one big `if ($_SERVER['REQUEST_METHOD']==='POST')` block at the top keyed by `$_POST['class_action']` (`create`, `edit`, `archive`, `delete`, `meeting_add`, `meeting_save`, `attendance_save`, `participation_save`, `assessment_configure`, `assessment_item_add`, `assessment_item_save`, `assessment_item_delete`, `assessment_grade_save`, `assessment_assign_grouping`, `grouping_create`, `grouping_save`, `grouping_delete`). Pattern: validate into `$errors` (flat array) and, for the item-setup form, also into `$itemFieldErrors` (keyed by field name for inline display) → on success `rotate_csrf_token()` + `$_SESSION['class_success']` flash + `redirect_to()` (POST-redirect-GET) → on failure, fall through and re-render the same page with `$errors` in scope (no redirect). Some flows re-open the relevant Bootstrap modal automatically after a failed POST via a `data-auto-open-modal="#modalId"` hidden `<span>` that `js/dashboard.js` picks up on load.
- Every render function for a class-workspace module lives in this same file (`render_attendance_workspace`, `render_assessment_item_modal`, `render_grouping_edit`, etc.) — grep this file first for any instructor-facing UI change.

## Self-Migrating Schema (no migration files)

There is **no migration system**. Each domain include has an `ensure_*_schema(PDO $pdo)` function — `ensure_attendance_schema`, `ensure_participation_schema`, `ensure_assessment_schema`, `ensure_grouping_schema` — run on **every page load** of `classes.php`. They issue `CREATE TABLE IF NOT EXISTS` and (MariaDB-specific) `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` for incrementally-added columns. `config/schema.sql` is a reference dump only — **not** auto-applied and can drift; trust the `ensure_*_schema()` functions and/or a live `SHOW CREATE TABLE` over it.

To add a column/table: edit the relevant `ensure_*_schema()` function directly (add table via `CREATE TABLE IF NOT EXISTS`, add column via `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`), don't hand-edit the DB or add a separate migration file.

## Database Schema & Relationships

```
user_roles (administrator|instructor|student)
  └─ users (role_id FK) — username/email/password/status
       ├─ administrators (user_id 1:1)
       ├─ instructors (user_id 1:1)
       │    └─ classes (instructor_id FK, ON DELETE SET NULL) — class_code (unique join code), status: draft|active|archived
       │         ├─ class_teaching_schedules (class_id 1:1) — course_start_date, semester_weeks, meetings_per_week
       │         │    └─ class_teaching_schedule_slots (class_id, slot_order) — weekly recurring day_of_week + meeting_type
       │         ├─ class_meetings (class_id) — meeting_date, week_number, status: regular|holiday|cancelled|review|exam,
       │         │    source: generated|manual, is_customized. MATERIALIZED from the weekly schedule (see below).
       │         │    ├─ attendance_records (meeting_id, student_id, UNIQUE) — status: present|absent|late|excused
       │         │    └─ participation_records (meeting_id, student_id, UNIQUE) — score, remarks
       │         ├─ class_assessment_settings (class_id 1:1) — total_activities, total_quizzes (target counts, nullable)
       │         ├─ class_assessment_items (class_id) — type: activity|quiz (shared table), position, title, max_score,
       │         │    scheduled_date, activity_mode: individual|group, grouping_id (FK, nullable), description
       │         │    └─ class_assessment_scores (item_id, student_id, UNIQUE) — score
       │         ├─ class_groupings (class_id) — scope: class|activity, item_id (set only when scope=activity),
       │         │    method: random|suggested|manual
       │         │    └─ class_grouping_groups (grouping_id) — name, leader_student_id (nullable FK -> students)
       │         │         └─ class_grouping_members (group_id, student_id, UNIQUE)
       │         └─ class_enrollments (class_id, student_id, UNIQUE) — status: active|removed (soft; students join via class_code)
       └─ students (user_id 1:1) — student_no
settings — flat key/value app config (setting_group, setting_key UNIQUE, setting_value, label); currently only
           general/app_name, general/institution_name, academic/default_term. Not wired to any admin UI yet.
```
Everything cascades `ON DELETE CASCADE` from `classes` down except `classes.instructor_id` (`SET NULL`). `delete_instructor_class()` is a **hard delete** (irreversible) — `archive` (status change) is the safe/reversible action instructors actually use.

## Major Modules (`includes/*_management.php`)

**`attendance_management.php`** — Meeting-based. A class's weekly recurring schedule (`class_teaching_schedules`+`_slots`) is the source of truth; `regenerate_class_meetings()` materializes/reconciles `class_meetings` rows for the whole term whenever the schedule is saved (idempotent — won't duplicate or clobber meetings that already have records). Only `status='regular'` meetings count toward attendance-rate math. Attendance is taken per-meeting, one record per enrolled student.

**`participation_management.php`** — Deliberately mirrors attendance's per-meeting shape (reuses `class_meetings`, same keying pattern) so the two modules feel consistent. Numeric score per student per meeting; max score from `participation_max_score()` (a function, not a DB/settings value).

**`assessment_management.php`** — Activities and Quizzes share one table (`class_assessment_items.type` discriminates). Instructor sets a target *count* (`class_assessment_settings.total_activities`/`total_quizzes`); `sync_assessment_items()` generates/trims numbered items to match (never deletes an item that already has scores). `resequence_assessment_items()` renumbers default-labeled items after an add/delete while preserving custom titles and all scores. `is_assessment_item_gradeable()` is the single gate: requires Title + Date + Max Score, and — for `activity_mode='group'` — a `grouping_id`. All of this is enforced server-side with per-field `$itemFieldErrors` and the item-setup modal auto-reopening on failure (see `render_assessment_item_modal`).

**`grouping_management.php`** — Reusable groupings, decoupled from any one activity. `class_groupings.scope`: `'class'` (reusable, shown in the picker) vs `'activity'` (private to one `item_id`, e.g. an ad-hoc grouping made just for one group activity). Generation methods: `random`, `suggested` (balanced by `get_student_performance_map()` — blends attendance rate + participation avg + assessment score avg per student), `manual` (empty groups, instructor assigns). The grouping editor (`render_grouping_edit()` in `classes.php`) is shared verbatim between the standalone Groupings module and a group activity's "manage groups" screen, and **persists every edit instantly via AJAX** (`ajax/grouping.php` — rename group/grouping, reassign member, set leader) — no save button, matching the "instant" UX decision made for this module specifically (everything else in the app is full-page POST+redirect).

**`class_management.php`** — Plain class-shell CRUD (name/section/subject/schedule/join code validation, exists/archive/delete helpers). Not a class-scoped "module" like the others.

`ajax/grouping.php` is the **only** AJAX endpoint in the entire app; it re-uses the same session CSRF token (`csrf_class_manage_token`) as the main POST flow. Everything else is server-rendered PRG (POST → redirect → GET).

## UI/UX & Coding Conventions

- Design tokens in `css/dashboard.css` `:root`: `--blue-950/900/800`, `--indigo-600`, `--emerald-500/100`, `--amber-500/100`, `--rose-500`, `--slate-*`. Reuse these, don't introduce new colors ad hoc.
- Button classes: `.btn-edupredict` (primary, filled navy), `.btn-copy` (secondary, outlined), `.btn-danger-soft` (destructive, often icon-only). `.meeting-actions` uses `align-items: center` deliberately (fixes a button-height-mismatch bug when a conditional button like "Grade" appears/disappears) — don't remove it.
- `.meeting-item`/`.meeting-list` is a **generic list-row pattern**, not attendance-specific — reused for meetings, assessment items, and groupings despite the name.
- `.status-pill` / `.meeting-status-badge` are colored state chips using `tone-*`/`status-*` modifier classes for consistent status coloring across modules.
- `.metric-card`/`.metric-grid` for stat tiles; `.form-grid`/`.field`/`.field-wide` for 2-column responsive forms; `.empty-state` for "nothing here yet" panels.
- Modal validation UX pattern (see `render_assessment_item_modal` for the canonical example): on a failed POST, repopulate fields from `$_POST` (not stale DB values), mark bad fields `.is-invalid`, show `.field-error` text under each, and auto-reopen the modal via `data-auto-open-modal`.
- Mobile: `@media (max-width: 767.98px)` in `dashboard.css` handles the off-canvas sidebar, stacked grids, and 16px form-control font-size (prevents iOS Safari auto-zoom on focus).
- PHP style: no framework, functions grouped by domain in `includes/`, page files are `require_once` + procedural top-to-bottom + a handful of `render_*()` functions for markup. `e()` = `htmlspecialchars` wrapper, use it around every echoed value.

## Known Limitations / Unimplemented (verified from code, not aspirational)

- **Grading Settings** (`settings/grading`) is a **client-side-only UI mockup** — the weight categories (Course Requirements 20% [Attendance 5 + Participation 15], Activities 20%, Quizzes 20%, Major Exams 40% [Midterm 20 + Finals 20]) are a hardcoded PHP array for display; the form has no `method=post` action and nothing persists to the database. There is currently no real connection between this weighting scheme and any actual grade computation.
- **Account Settings** is an empty "Planned" placeholder.
- **Midterm, Finals, Analytics, Predictions** class-workspace views have no dedicated implementation — generic placeholder only (see "The `classes.php` Monolith" above).
- **Administrator role**: dashboard shell only; every sidebar item is `href="#"`; all stat cards are hardcoded `0`. No user/instructor/class/system-settings management exists.
- **Student role**: only Dashboard and My Classes (join-by-code) work. Grades/Attendance/Progress/Target Grade/Predictions/Warnings/Settings are all `href="#"` placeholders — **students currently cannot view their own attendance, participation, or grades anywhere.**
- No automated tests, no linter, no CI beyond the no-gate FTP deploy.
