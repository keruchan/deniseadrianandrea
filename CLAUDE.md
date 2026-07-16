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
  view ∈ attendance|activities|group-activities|groupings|quizzes|midterm|finals|participation|dashboard|analytics|predictions|recommendations
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

Sidebar nav is built by `includes/sidebar_config.php`: `instructor_sidebar_menu()` returns a nested array (types: `link`, `group` — collapsible, localStorage-persisted via `data-sidebar-group` — and `section`), rendered by recursive `render_sidebar_*()` functions in `dashboard_layout.php`. When a class is selected, three extra sections appear: **CLASS** (Overview), **ASSESSMENTS** (Course Requirements > Attendance/Participation, Activities, Quizzes, Groupings, Major Exams > Midterm/Finals), **INSIGHTS** (Dashboard, Analytics, Predictions, Recommendations — see Insights Module).

## The `classes.php` Monolith

`pages/instructor/classes.php` (~2900 lines) is simultaneously: the class list page, the class create/edit modal host, and the **entire per-class "workspace"** (every class-scoped module). This is the single most important file to understand before touching instructor features.

- **Dispatch**: `render_instructor_class_workspace()` if-chains on `$_GET['view']` to one of `render_attendance_workspace()`, `render_participation_workspace()`, `render_assessment_workspace($pdo, ..., $type)` (shared across **all four** of `activity`/`quiz`/`midterm`/`finals`), `render_groupings_workspace()`, the four insights workspaces (`render_insights_{dashboard,analytics,predictions,recommendations}_workspace()` from `insights_render.php`) — or falls through to a generic "module placeholder" empty-state. **Only `group-activities` (legacy, unused route) hits that placeholder now.**
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
       │         ├─ class_assessment_settings (class_id 1:1) — total_activities, total_quizzes, total_midterms, total_finals
       │         │    (target counts per type, all nullable)
       │         ├─ class_assessment_items (class_id) — type: activity|quiz|midterm|finals (shared table), position, title,
       │         │    max_score, scheduled_date, activity_mode: individual|group, grouping_id (FK, nullable), description
       │         │    └─ class_assessment_scores (item_id, student_id, UNIQUE) — score
       │         ├─ class_groupings (class_id) — scope: class|activity, item_id (set only when scope=activity),
       │         │    method: random|suggested|manual
       │         │    └─ class_grouping_groups (grouping_id) — name, leader_student_id (nullable FK -> students)
       │         │         └─ class_grouping_members (group_id, student_id, UNIQUE)
       │         ├─ class_grading_weights (class_id 1:1) — attendance/participation/activities/quizzes/midterm/finals_weight
       │         │    (sum to 100) + passing_grade (default 75); feeds the Insights prediction engine (see Insights Module)
       │         ├─ class_student_goals (class_id, student_id, UNIQUE) — target_grade; the STUDENT's own saved Goal-Analysis
       │         │    target. Instructors' Goal-Analysis input is temporary (session-only) and never written here.
       │         └─ class_enrollments (class_id, student_id, UNIQUE) — status: active|removed (soft; students join via class_code)
       ├─ students (user_id 1:1) — student_no
       └─ notifications (user_id FK, ON DELETE CASCADE) — type, title, body, link, is_read, created_at, read_at.
            Per-user bell feed for every role (see Notifications under Major Modules).
settings — flat key/value app config (setting_group, setting_key UNIQUE, setting_value, label):
           general/app_name, general/institution_name, academic/default_term. Edited via the admin System Settings page.
```
Everything cascades `ON DELETE CASCADE` from `classes` down except `classes.instructor_id` (`SET NULL`). `delete_instructor_class()` is a **hard delete** (irreversible) — `archive` (status change) is the safe/reversible action instructors actually use.

## Major Modules (`includes/*_management.php`)

**`attendance_management.php`** — Meeting-based. A class's weekly recurring schedule (`class_teaching_schedules`+`_slots`) is the source of truth; `regenerate_class_meetings()` materializes/reconciles `class_meetings` rows for the whole term whenever the schedule is saved (idempotent — won't duplicate or clobber meetings that already have records). Only `status='regular'` meetings count toward attendance-rate math. Attendance is taken per-meeting, one record per enrolled student.

**`participation_management.php`** — Deliberately mirrors attendance's per-meeting shape (reuses `class_meetings`, same keying pattern) so the two modules feel consistent. Numeric score per student per meeting; max score from `participation_max_score()` (a function, not a DB/settings value).

**`assessment_management.php`** — Activities and Quizzes share one table (`class_assessment_items.type` discriminates). Instructor sets a target *count* (`class_assessment_settings.total_activities`/`total_quizzes`); `sync_assessment_items()` generates/trims numbered items to match (never deletes an item that already has scores). `resequence_assessment_items()` renumbers default-labeled items after an add/delete while preserving custom titles and all scores. `is_assessment_item_gradeable()` is the single gate: requires Title + Date + Max Score, and — for `activity_mode='group'` — a `grouping_id`. All of this is enforced server-side with per-field `$itemFieldErrors` and the item-setup modal auto-reopening on failure (see `render_assessment_item_modal`).

**`grouping_management.php`** — Reusable groupings, decoupled from any one activity. `class_groupings.scope`: `'class'` (reusable, shown in the picker) vs `'activity'` (private to one `item_id`, e.g. an ad-hoc grouping made just for one group activity). Generation methods: `random`, `suggested` (balanced by `get_student_performance_map()` — blends attendance rate + participation avg + assessment score avg per student), `manual` (empty groups, instructor assigns). The grouping editor (`render_grouping_edit()` in `classes.php`) is shared verbatim between the standalone Groupings module and a group activity's "manage groups" screen, and **persists every edit instantly via AJAX** (`ajax/grouping.php` — rename group/grouping, reassign member, set leader) — no save button, matching the "instant" UX decision made for this module specifically (everything else in the app is full-page POST+redirect).

**`class_management.php`** — Plain class-shell CRUD (name/section/subject/schedule/join code validation, exists/archive/delete helpers). Not a class-scoped "module" like the others.

**`insights_management.php` + `insights_render.php`** — The Insights subsystem (Dashboard / Analytics / Predictions / Recommendations, + a per-student page). Logic and presentation are deliberately split into two new files instead of growing the 2900-line `classes.php` (documented deviation from "everything lives in classes.php"). See the dedicated **Insights Module** section below.

There are **two** AJAX endpoints: `ajax/grouping.php` (live grouping editor; re-uses `csrf_class_manage_token`) and `ajax/notifications.php` (the topbar bell; `csrf_notifications_token`). Everything else is server-rendered PRG (POST → redirect → GET).

**Notifications** (`includes/notification_management.php`, shared by all roles): a per-user feed backing the topbar bell in `dashboard_layout.php` (unread badge, read/unread, delete, relative timestamps, keyset-paginated "load more"/infinite scroll — see `js/notifications.js` + `.notification-*` CSS). Table `notifications` (self-migrating via `ensure_notifications_schema()`, run on every dashboard render). `create_notification()` is called at event points — currently: a student joining a class notifies the instructor (`notify_class_instructor_of_join()` in `pages/student/classes.php`), and posting grades notifies each student whose score changed (`notify_students_of_grade()` in the `assessment_grade_save` handler, diffed against existing scores so re-saves don't re-notify). The bell reads via `ajax/notifications.php` actions `list`/`unread_count`/`mark_read`/`mark_all_read`/`delete` (mutations require CSRF; reads only require login). Add new notification sources by calling `create_notification($pdo, $userId, $type, $title, $body, $link)` — `type` picks the icon (`notification_icon()`).

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

- **Grading Settings** (`settings/grading`) now **persists** per-class weights (see Insights Module below) — the old client-side category-tree mockup is gone. Account Settings is still an empty "Planned" placeholder.
- **Instructor Dashboard** (`pages/instructor/dashboard.php`) is now a **live cross-class rollup** (via `get_instructor_dashboard()`): the 8 summary KPI cards are **clickable** (`.metric-card-link`) and route to the page each describes (Classes → `classes`; Students/Average/Attendance/Participation/At-risk → `students.php` with a `sort=`/`risk=` query; Pending grading/Upcoming → `assessments.php?status=`). Also a **"Your Classes" grid** (`.dash-class-card`) routing to each class's Insights Dashboard/Predictions, charts (attendance trend, grade distribution, completion), and non-student feeds (recently graded, upcoming meetings/deadlines). Deliberately **summary-and-route only** — never enumerates students inline.
- **Cross-class Students page** (`pages/instructor/students.php`, sidebar "Students", `active_route` `students`, data from `get_instructor_students_overview()`): every student × class row with account status (users.status), current grade, attendance, predicted final, and risk. Server-side GET filters (`class`, `status`, `risk` incl. `risk=atrisk` = the forecast-at-risk set matching the dashboard KPI, `sort`) + the shared client-side name/ID search (`data-student-search-scope`). Each row links to the student's Predictions drill-down.
- **Cross-class Assessments page** (`pages/instructor/assessments.php`, sidebar "Assessments", data from `get_instructor_assessments_overview()`): every assessment item across classes with grading progress (`graded/enrolled`) and status (pending/upcoming/graded/setup), GET filters (`class`, `type`, `status`) + client search, each row linking to its grading screen. Both new pages are hit by **direct file path** (like `dashboard.php`) — no `.htaccess` rewrite.
- **Administrator role is fully built** (`includes/admin_management.php` + `admin_sidebar_menu()` in `sidebar_config.php`; routes `admin.*`). No `href="#"` left. Pages under `pages/administrator/`: **Dashboard** (live system rollup via `get_admin_overview()` — clickable KPI cards, users-by-role doughnut + classes-by-status bar, recent users/classes, system-wide at-risk count), **User Management** `users.php` (list/filter by role+status + search via `get_admin_users()`; create/edit/approve/enable/disable/reset-password/delete — Instructors & Students nav items are just `?role=` filters of this page; guards prevent disabling/deleting yourself or the last active admin), **Classes** `classes.php` (institution-wide oversight: activate/archive/delete via `get_admin_classes()`), **System Settings** `settings.php` (edits the `settings` table — app/institution name, default term — now wired to a real UI), and **Announcements** `announcements.php` (`admin_broadcast_announcement()` fan-outs a `type=announcement` notification to every non-disabled user in the chosen audience — leverages the notification bell). CSRF per form-family (`csrf_admin_users_token`/`_classes_token`/`_settings_token`/`_announce_token`), standard validate→PRG→flash. Admin pages are hit by direct file path (no `.htaccess` rewrites).
- **Student role is fully built.** Every sidebar item works — no `href="#"` left. The nav is centralized in `student_sidebar_menu(?int $classId)` (`sidebar_config.php`; a flat top section + **MY PROGRESS** + **Account** sections, route-based active states `student.*`). Pages: **Dashboard** (`pages/student/dashboard.php`, live rollup via `get_student_dashboard()` — KPI cards, per-class routing cards, a warnings preview, recent grades, and upcoming meetings/deadlines), **My Classes** (join-by-code + a "My insights" link per joined class), **Class Insights** (Grades/Attendance/Progress/Target Grade/Predictions/Goal — the shared per-student page), **Warnings** (`pages/student/warnings.php` — severity-ranked risk signals across classes: at-risk, low attendance, missing work, declining trend, each linking to the relevant insights tab), and **Settings** (`pages/student/settings.php` — editable profile + change-password, two PRG forms with their own CSRF keys `csrf_student_profile_token`/`csrf_student_password_token`). `get_student_dashboard()` (insights_management.php) is the shared aggregate behind the dashboard and warnings pages.
- No automated tests, no linter, no CI beyond the no-gate FTP deploy.

## Insights Module (Dashboard / Analytics / Predictions / Recommendations)

Live-computed academic insights for instructors, plus a read-only student mirror. Everything is recomputed on each page load from the existing tables (attendance, participation, assessments, groupings, meetings, enrollment) — no caching, no persisted results. Split across two new files to avoid growing `classes.php`:

- **`includes/insights_management.php`** — all logic. Grading-weights persistence, a single batch-loaded dataset (`build_class_insights_dataset()` — one query for all scores, no N+1), the per-student prediction engine (`get_all_student_metrics()` → `compute_student_bundle()`), class aggregates (`get_class_dashboard_summary()`, `get_class_descriptive_analytics()`, `get_class_diagnostic_analytics()`), goal analysis (`compute_goal_analysis()`), and recommendation generators (`generate_student_/assessment_/group_/instructor_recommendations()`). Reuses the domain modules' existing readers (`get_attendance_summary`, `get_student_performance_map`, `get_grouping_with_groups`, etc.) rather than re-querying.
- **`includes/insights_render.php`** — all markup. `render_insights_{dashboard,analytics,predictions,recommendations}_workspace(PDO, array $class)` called from `classes.php`'s dispatch chain, plus `render_student_class_insights_page(PDO, $class, $studentId, $tab, $readOnly, $viewerRole)` shared verbatim by the instructor drill-down and the student portal (only `$readOnly` differs). Multi-section pages use Bootstrap **nav-tabs within one route**, not extra sidebar nodes.

**Grading weights (the only new table).** `ensure_insights_schema()` creates `class_grading_weights` (class_id UNIQUE; `attendance/participation/activities/quizzes/midterm/finals_weight` decimals; `passing_grade` decimal default 75; FK → classes ON DELETE CASCADE). `get_class_grading_weights()` returns the persisted row **or** `grading_weight_defaults()` (5/15/20/20/20/20, passing 75) — classes that never open Grading Settings still work. Weights **must sum to exactly 100** (`validate_grading_weights()`, server-authoritative; live JS total in `dashboard.js` keyed on `[data-grading-weights-form]`). `pages/instructor/grading-settings.php` is now a real per-class form (class selector + 6 weights + passing grade, own CSRF key `csrf_grading_settings_token`, standard validate→save→flash→PRG).

**Prediction methodology** — transparent weighted-heuristic, **not ML** (UI copy says "weighted performance model", never "AI"). Current grade = weighted avg of components that have data. Predicted final = each component projected at its own standing (or the student's baseline for unstarted ones), clamped 0–100. Trend = least-squares slope over the chronological per-item %-series (needs ≥3 points; total change >5 improving / <−5 declining). Risk (0–100 → low/medium/high) blends grade-gap 0.45 / attendance 0.20 / trend 0.15 / missing 0.15 / volatility 0.05, floored to ≥55 when the projected final is below passing. Confidence = weighted completion fraction. Goal analysis: `max_achievable = locked_points + remaining_weight`; `required_avg = (goal − locked)/remaining × 100`, flagged `infeasible` (>100) or `secured` (≤0).

**Routes.** Instructor: added `dashboard` and `recommendations` to `instructor_class_views()`, the INSIGHTS sidebar section (now 4 links: Dashboard/Analytics/Predictions/Recommendations), and the `.htaccess` view regex; `analytics`/`predictions` already existed. The instructor per-student drill-down is `classes/{id}/predictions?student={sid}` (same `?student=` convention as `?item=` on assessments). Student: **one new page** `pages/student/class-insights.php?class_id=&tab=grades|attendance|participation|predictions|goal` (no rewrite rule — direct file path like all student pages). It enforces active-enrollment ownership, resolves the class (0 enrollments → empty state, 1 → direct, 2+ → picker), and the 5 placeholder nav links in student `dashboard.php`/`classes.php` now point at it.

**Charts.** Chart.js 4.4.3 via CDN (added in `dashboard_layout.php`) + `js/insights.js`, which reads a compact JSON contract off `<canvas class="insights-chart" data-chart="…">` (`{type,labels,series,yMax,…}`; `type` ∈ line|bar|hbar|pie|doughnut|scatter) and applies a fixed-order palette from the design tokens. Categorical charts always ship a legend/table beside them so identity is never colour-alone; charts inside inactive tabs are resized on `shown.bs.tab`. Insights-specific CSS lives at the end of `dashboard.css` under the `/* Insights module */` banner.

**Goal Analysis targets (persistence + ownership).** The student's own target grade is persisted in `class_student_goals` (`get_student_goal()`/`save_student_goal()`); the student saves it via a POST on `class-insights.php` (`insights_action=save_goal`, CSRF `csrf_student_goal_token`, enrollment-checked). The **instructor's** Goal-Analysis input is temporary — a GET `?goal=` that only affects the current view and is never written; the instructor sees the student's saved target as the default/reference. `render_student_goal_tab($bundle, $readOnly, $savedGoal, $classId, $studentId, $csrf)` branches on `$readOnly` to render the student's save-form vs the instructor's temporary analyze-form.

**Student-insights tabs are client-side.** `render_student_class_insights_page()` renders **all five panes at once** (`stins-*` ids) and switches them with Bootstrap `data-bs-toggle="tab"` — no page reload, no scroll-to-top when moving between Overview/Attendance/Participation/Predictions/Goal. `?tab=` only sets the initially-active pane (deep links from the student sidebar still work). Only the Goal analyze/save actions reload (explicit form submits); `insights.js` smooth-scrolls the tabset into view when a `goal=` param is present so the result isn't a top-jump.

## Assessment grading & student assessment views (recent additions)

- **Attendance-in-grading**: both the individual grading sheet and the group grading form show each student's Present/Absent/Late/Excused for the assessment's `scheduled_date`, resolved via `get_attendance_status_for_date()` (attendance_management.php) → rendered by `render_grade_attendance_pill()` (classes.php). "No meeting"/"Not recorded" pills appear when the date has no class meeting or no record.
- **Group activities** keep the **leader** star badge, show each member's attendance pill, and still allow **individual per-member score overrides** (the group score input pre-fills every member; any member field overrides).
- **"View Student {Activities/Quizzes/Midterms/Finals}"**: a per-student assessment summary modal (`render_student_assessment_modal()`, data from `build_student_assessment_overview()` in assessment_management.php) mirroring the Student Attendance modal — history, per-item scores, average, highest/lowest, overall performance label, with search. All student list/detail history modals (attendance, participation, every assessment type) now share the JS via the `data-student-history-modal` hook (not hardcoded modal ids).
- **Grouping live editor** re-syncs the roster dropdowns from the server snapshot after every assignment (`renderGroups()` in dashboard.js), so both the group cards and the member list always reflect the persisted state.
- **Grading Settings** shows an "Unsaved changes" / "All changes saved" indicator (dirty-tracking in `dashboard.js` keyed on `[data-grading-weights-form]`) alongside the existing success flash.
