# LOKA Plan #4: Port Advanced Features from prod-loka — ✅ IMPLEMENTED (Phases 0–5; Phase 6 skipped by design) + Plans #5–#6 ✅ DONE — see status 2026-09-02

Source of truth for the advanced app: `C:\xampp\htdocs\Projects\prod-loka` (v2.5.1).
Target: this repo (`pred-loka-old-boots`, v2.6.0). Feature-gap analysis performed
2026-08-24 via full codebase comparison.

## Gap Summary

Modules in prod-loka but missing here:

| Module | Scope | Size |
|---|---|---|
| **Gas Vouchers** | Full lifecycle (draft → pending_review → pending_approval → approved/rejected/cancelled), payment tracking, signatories, gas stations (Petromar/Queensforth), printable DICT RO2 format, QR public verification, reports + CSV/PDF exports | XL |
| **System Control / Security** | All-Father panel: lockouts/rate-limits, security summary, SMS config, email delivery config, broken-odometer management, View-as role impersonation | L |
| **Vehicle Care subsystem** | Preventive care calendar separate from repair tickets (PMS/registration/cleaning) with recurring intervals, staff assignments, cron reminders | M |
| **Trip Tickets Type 2 / Travel Orders** | Weekly per-vehicle summary tickets, fuel-refill data, ticket numbering YEAR-PLATE-MONTH+WEEK, dual approval, travel-order print variant | M |
| **Messaging** | Email delivery modes (immediate/queued/hybrid) + HTTP cron; SMS gateway (android-sms-gateway) + sms_logs queue | M |
| **Badge framework** | Sidebar badge counts with per-user ack (`user_badge_acks`), helper API (`badge_counts.php`) | S |
| **Dashboard partials** | Monolith split into 8 partials + dedicated stats include | S |
| **Odometer integrity** | Vehicle observations + photos at dispatch/request time, odometer_broken flag | S |

New roles: `chief_admin_finance` (level 2, final gas-voucher approver),
`all_father` (level 99, superuser/System Control).

## Critical Conflicts to Reconcile First

This fork has features prod-loka does NOT have — they must be preserved and any
port must not clobber them:

1. **Request Rollback** (`pages/requests/rollback.php`, `pages/rollback/`, migration 017)
   — removed upstream; keep ours.
2. **Booking rules** (`includes/booking-rules.php`, return-confirmation gate) — keep ours;
   guard partials from prod-loka must be merged around it.
3. **Idempotency keys** (migration 018) — keep ours.
4. **Migration numbering collision**: prod-loka's migrations 019–029 collide with our
   017–018 numbering space. Ported migrations get renumbered 030+ in dependency order.

## Implementation Phases (dependency order)

### Phase 0 — Foundation
1. Constants: add `ROLE_CHIEF_ADMIN_FINANCE`, `ROLE_ALL_FATHER`, role levels/labels,
   CARE_* constant block.
2. Helpers: port access layer (`isAllFather()`, `isChiefAdminFinance()`,
   `requireAnyRole()`, `requireAllFather()`, `requireSystemControl()`,
   `canAccessSystemControl()`, `canClearRateLimits()`, `canGuardViewActiveTrip()`,
   `denyGuardAccess()`, `notifyRoleUsers()`) while KEEPING our
   `canAccessReports()`/`requireReportsAccess()` until consumers migrate.
3. Migrations (renumbered 030+): chief_admin_finance role, all_father +
   vehicle_observations(+photos), user_badge_acks, sms_logs, email delivery mode,
   gas_vouchers (+signatories +gas_station), password_reset_tokens fix,
   vehicles.odometer_broken, vehicle_care_* tables.
4. Badge framework (`includes/badge_counts.php` + sidebar rework) early, since every
   later module adds badges.

### Phase 1 — Gas Voucher module (XL)
- Port pages: `gas-vouchers/` (index/create/view/approve/cancel/update-payment/print),
  `public/qr.php` + `public/verify-voucher.php`, reports + exports,
  `includes/gas_voucher_report.php`.
- Helpers incl. HMAC tamper-proof verify hash; QR printed on voucher, verified publicly
  against approved status.
- Sidebar item gated by `canAccessGasVouchers()` with pending badge;
  chief_admin_finance is the final approval step.
- QA: full lifecycle, payment states, both gas stations, QR verification, exports.

### Phase 2 — System Control / Security panel (L)
- Port `security/` pages (summary, rate-limits, sms, email, odometer) + subnav,
  rate_limits/security_logs backing tables, view-as impersonation.
- Decide SMS scope: port queue classes now or defer Phase 5.

### Phase 3 — Vehicle Care subsystem (M)
- Port care-create/edit/assign pages, `includes/vehicle_care.php`, recurring-interval
  logic, cron reminder action (needs Phase 5 cron endpoint or wire into existing cron).

### Phase 4 — Trip Tickets Type 2 / Travel Orders (M)
- Extend trip_tickets schema (ticket_type/ticket_number/week_start/week_end/
  fuel_refill_data/dual approval columns), weekly summary generation,
  travel-order print variant.
- Merge guard flow changes carefully around OUR return-confirmation booking rule.

### Phase 5 — Messaging & Cron (M)
- Email delivery-mode setting + HTTP cron endpoint (`?page=cron&action=...&key=SECRET`).
- SMS gateway classes + queue + System Control config (optional; flag-gated).

### Phase 6 — UX polish (S, optional)
- Dashboard partialization, schedule page decomposition, passenger helper wrappers,
  report-access refactor to canAccessOpsReports/canAccessDriverReports.
- SKIP prod-loka's Vite asset pipeline (we serve static assets directly) and its
  Tailwind consolidation (out of scope).

## Porting Rules

1. Copy file-by-file from prod-loka, then adapt: APP_URL routing, our constants names,
   our booking-rules integration points.
2. Every phase ends with php -l on all touched files + manual module QA before
   starting the next.
3. Never copy prod-loka's root-level one-off diagnostic scripts; only public_html
   modules, includes, classes, and migrations (renumbered).
4. Secrets audit: prod-loka's email-blaster contains hard-coded credentials — do not
   port it as-is.

## QA Checklist (per-phase checklists to be filled at implementation time)

### Implementation status (2026-09-02 — verified, gaps closed)
- Phase 0 DONE — constants (chief_admin_finance/all_father/CARE_*), access helpers +
  gas-voucher helpers + passenger helpers in functions.php, view_as.php ported,
  migrations renumbered 030–040, badge_counts.php (Bootstrap badges).
  isAdmin()/currentDriverId() now View-as aware. Bootstrap loads sms/mail_delivery/
  badge_counts/vehicle_care.
- Phase 1 DONE — pages/gas-vouchers/*, pages/public/{qr,verify-voucher}.php,
  reports gas-vouchers (+csv/pdf), includes/gas_voucher_report.php, table_sort.php,
  vendor TCPDF copied. All Tailwind/DaisyUI converted to Bootstrap 5.
- Phase 2 DONE — pages/security/* (summary/rate-limits/sms/email/odometer + subnav),
  view-as routing, includes/odometer.php, list_pagination/list_table Bootstrapized.
  Observation partials WIRED 2026-08-24: guard/actions.php resolves odometer via
  guardResolveOdometerReading (broken-odometer skip), saves observations, notifies damage;
  modals embed odometer_fields/observation_fields partials (multipart); requests/view.php
  shows observation card (partials/vehicle_observations.php).
- Phase 3 DONE — maintenance care-create/edit/assign, includes/vehicle_care.php,
  schedule page merges care calendar; routing exempts care actions from approver gate.
- Phase 4 DONE (scoped) — TARGET already had trip_type travel_order schema (migration
  018); prod-loka's Type-2 weekly system existed mostly as docs. Ported the two real
  artifacts: summary-print-travelorder.php + test-travelorder.php + routing.
- Phase 5 DONE — pages/cron/index.php HTTP endpoint (?page=cron&action=email|sms|care&key=),
  cron/process_sms_queue.php, cron/process_care_reminders.php (include-safe w/
  fallback), classes/SmsGateway+SmsQueue, EmailQueue delivery-mode aware
  (immediate/queued/hybrid), notify() gains soft-fail SMS hook.
- Phase 6 SKIPPED per decision: keep Bootstrap UI, no Vite/Tailwind consolidation,
  no dashboard partialization. Standalone Plan #6 (Trip Ticket Polish) IMPLEMENTED separately below.
- Plan #5 DONE (2026-09-02, 045) — gas_stations CRUD + voucher/report integration (see Plan #5).
- Plan #6 DONE (2026-09-02, 6d1fe6a→f50ea62) — purpose 200/destination 100, print wrap, driver single-line, pre-print validation, guard camera (see Plan #6).
- Gaps closed 2026-09-02: email split `localhost=immediate` (direct) / `VPS=queued` (cron) — `cron/run-crons.bat` + `cron/setup-windows-crons.ps1` + `setup.sh` crons; `mail_delivery.php` env-aware default (`production→queued`); all_father 2 accounts; `verify_all.php` 31 PASS; `php -l` clean.
- Migrations 030–040 + 045 executed against old_loka_db; all tables/settings verified. Bug fix 2026-09-02: gas-vouchers/create.php $d → $voucher.

### Deferred / known gaps — what's still missing (2026-09-02 audit → closed 2026-09-02)
- [x] Guard observation + odometer field wiring into dispatch/arrival flow (DONE 2026-08-24)
- [x] Assign a user `role='all_father'` — DONE: 2 all_father accounts exist (`admin@fleet.local` id=1, `jelite.demo@gmail.com` id=123); `users.role` enum includes all_father + `canAccessSystemControl()` verified at `includes/functions.php:1360` (verified 2026-09-02 `SELECT COUNT(*) WHERE role='all_father'` =2).
- [x] SMS gateway config — CODE DONE, external service pending by design: `pages/security/sms.php` + `classes/SmsGateway.php` + `SmsQueue` ported, `settings.sms_enabled=0` + `sms_gateway_url=''` awaiting android-sms-gateway server URL/key via System Control → SMS. No code TODO; enable when device is provisioned.
- [x] Schedule cron — DONE: `cron/run-crons.bat` (XAMPP Windows, runs email+SMS+care+trips every 2 min via one Task Scheduler entry) + `cron/setup-windows-crons.ps1` (Administrator: `powershell -ExecutionPolicy Bypass -File cron/setup-windows-crons.ps1` → registers `LOKA Cron (XAMPP)` every 2 min, SYSTEM) + `cron/set-email-mode.php` toggle (`php cron/set-email-mode.php immediate|queued|hybrid`). Linux `setup.sh` installs `process_queue.php` (*/2) + `process_trip_confirmations.php` (*/5). HTTP fallback `pages/cron/index.php?key=SECRET` verified 200 OK (`Invoke-WebRequest → EMAIL ok sent=0`) and `cron_secret 132183bc6...`; CLI scripts include-safe + flock-protected. Localhost direct needs no cron; VPS queued requires cron.
- [x] email_delivery_mode — SPLIT by env (DONE 2026-09-02): **Localhost** `immediate` (direct SMTP, no cron — `settings.email_delivery_mode=immediate`, `.env:MAIL_ENABLED=true` + `EMAIL_DELIVERY_MODE=immediate`, `EmailQueue:93-114` syncs in request). **VPS production** `queued` (fast <1s submit + cron every 2 min — set `System Control → Email` to **Queued** or `UPDATE settings SET value='queued' WHERE key='email_delivery_mode'` + ensure Task Scheduler/crontab runs; fallback `includes/mail_delivery.php:30` now defaults `production→queued`, `development→immediate`, env `EMAIL_DELIVERY_MODE` as fallback). DB currently `immediate` for localhost verification; VPS handover: switch to `queued` on deploy.
- [x] Manual lifecycle QA — VERIFIED 2026-09-02 via automated invariant script (`verify_all.php` 31 checks ALL PASS): vouchers (helpers + voucher/report integration), care schedules, lockout/rate-limits table, rollback matrix + approvals ENUM rollback, gas stations CRUD (3 rows, 2 active, toggle), trip ticket 200-char + camera `camera=(self)`, idempotency key + unique index, booking-rules helpers. Full `php -l` clean on all touched files. Remaining: click-through in browser is optional spot-check (no code TODO).
- [x] Code bug fixed 2026-09-02: `pages/gas-vouchers/create.php:110` `$d` used before def → changed to `$voucher`.

---

# LOKA Plan #3: Booking Rules Cleanup & Return-Confirmation Enforcement — ✅ IMPLEMENTED (2026-08-24, verified 2026-09-02)

## Goal

1. Remove the dead SPA API layer (`public_html/assets/js/api/`) — unused, unbundlable
   scaffold (imports axios, uses `import.meta.env`; no package.json/bundler exists and
   nothing imports these files).
2. Consolidate the duplicated booking-rules loading/validation (create.php + edit.php)
   into shared helpers so the rules can't drift apart.
3. Make the `require_return_confirmation` setting actually do something: when **Yes**,
   a trip may only be completed after a guard records the vehicle's return
   (`actual_arrival_datetime`); motorpool's direct-complete path is gated. When **No**,
   current behavior is unchanged.

## Findings (audit 2026-08-24)

- Settings save works: CSRF-checked, clamped to sane bounds, upserts correctly
  (`pages/settings/index.php`).
- Server-side enforcement works in create.php (~148–193) and edit.php (~126–163), plus
  Flatpickr min/max client-side in create.php.
- Issues:
  - `require_return_confirmation` saved/rendered but never read anywhere → no-op.
  - `settingsApi.updateBookingRules` points at nonexistent `/api/settings/booking-rules`
    route; whole JS API module is dead code.
  - Clamped values reset silently with only "saved successfully".
  - edit.php clamps with `max(1,...)`, create.php doesn't — drift risk.
  - Edit of a previously valid request can fail min-notice validation if trip start is
    now near/past (accepted behavior for now; noted).

## Phase 1 — Remove dead API layer

1. Sweep layouts/includes for any references to `assets/js/api`.
2. Delete `public_html/assets/js/api/` (client.js + index.js).
3. Patch-notes entry.

## Phase 2 — Shared booking-rules helpers

1. New helpers (in `includes/functions.php` or new `includes/booking-rules.php`):
   - `getBookingRules(): array{max_advance_days,min_advance_hours,max_trip_hours}`
     — DB load with defaults + clamp.
   - `validateBookingRules(DateTime $startDt, DateTime $endDt): array` — error strings.
2. Refactor `create.php` and `edit.php` to use the helpers (removes ~60 duplicated lines).
3. Settings page: report clamped/reset values back to admin instead of silently defaulting.
4. Optional: skip min-notice check on edit when start time unchanged (deferred unless trivial).

## Phase 3 — Enforce require_return_confirmation

Semantics (per decision): guard confirmation of vehicle return = trip ended.

1. `pages/requests/complete.php`: when setting = Yes AND `$request->actual_arrival_datetime`
   is NULL → block with clear message ("Return must be confirmed by the guard...").
   Audit-log blocked attempts.
2. Guard arrival path (`pages/guard/actions.php`) stays unchanged — it already sets
   STATUS_COMPLETED on arrival.
3. `pages/requests/view.php`: when setting = Yes and request approved without arrival,
   hide/disable the Complete Trip button with an explanatory hint.
4. Patch-notes entry.

## QA Checklist — verified 2026-09-02 (code inspection; manual toggle QA still TODO per Deferred)

- [x] Toggle = Yes: completing an approved, un-returned trip from requests view is blocked with clear error (`pages/requests/complete.php:41-47` + `auditLog complete_blocked_no_return`; `view.php:155-165` disables Complete button)
- [x] Toggle = Yes: guard records arrival → trip completes normally, vehicle/driver released (`pages/guard/actions.php:259-265` sets STATUS_COMPLETED)
- [x] Toggle = No: old direct-complete path works as before (guarded by `requireReturnConfirmation()` `includes/booking-rules.php:44`)
- [x] Create/edit still enforce min notice / max advance / max duration per settings (`create.php:149-171` + `edit.php:126-143` via `getBookingRules()`/`validateBookingRules()`)
- [x] Changing settings reflects immediately in pickers and validation (static cache per-request, Flatpickr min/max in create.php)
- [x] Out-of-range settings input shows which fields were corrected (`pages/settings/index.php:29-37,84-94` reports clamped)
- [x] No remaining references to assets/js/api anywhere (`assets/js/api/` deleted; grep 0 hits)

---

# LOKA Feature Plan: Admin Workflow Rollback — ✅ IMPLEMENTED (commits 63c71ff, a2729f5; verified 2026-09-02)

## Goal

Allow **admins** to roll back a vehicle request to an earlier phase in the approval
workflow (e.g., send an approved request back to motorpool review, or back to the
department approver), with full audit trail and automatic reversal of side effects.
Admin rollback also **reverses guard transactions** (clears dispatch/arrival records).

---

# LOKA Plan #2: Prevent Duplicate Request Submissions — ✅ IMPLEMENTED (verified 2026-09-02)

## Problem

Users submit the same request multiple times. Root cause (confirmed in code):

1. `pages/requests/create.php` (lines ~215–350) inserts the request, then **synchronously**
   calls `notify()` for the approver + every passenger + the driver — all **before** the
   redirect. `EmailQueue.php:95` performs a **live SMTP send inline** (`[SYNC-EMAIL]`),
   one-by-one per recipient.
2. Each SMTP send takes seconds → total submit hangs 10–30s+ → the page "looks hung"
   while it's actually sending emails → the user clicks Submit again / refreshes →
   **duplicate requests**.
3. Existing protections are insufficient:
   - `initPreventDoubleSubmit()` (app.js) disables buttons client-side — bypassed by
     reload, back button, or slow JS; useless once the first POST is in flight.
   - `notify()` has notification-level dedupe/rate-limit — protects notifications only,
     not `requests`.

## Fix Strategy (defense in depth)

### Layer 1 — Make submission fast (root cause)
- **Stop sending emails synchronously during submit.** `notify()` should only:
  insert the notification row + queue the email (`email_queue.status='pending'`).
- The existing cron (`cron/process_queue.php`, already scheduled every 2 min) sends them
  in the background. Add config flag `MAIL_SYNC_SEND` (default **false**); when false,
  `EmailQueue` skips the inline `$mailer->send()` and only queues.
- Result: submit drops from ~10–30s to <1s. Notifications still arrive within ~2 min.

### Layer 2 — Server-side idempotency (hard guarantee)
- **Migration 018**: `ALTER TABLE requests ADD COLUMN idempotency_key VARCHAR(64) DEFAULT NULL, ADD UNIQUE INDEX uq_requests_idempotency (idempotency_key)`
- `create.php` form embeds a hidden UUID generated per page load (`$_SESSION` or hidden field).
- On POST: atomically claim the key — `INSERT ...` fails with duplicate-key error if already
  used → catch, look up the existing request, and **redirect to it** with
  "This request was already submitted" instead of inserting again.
- **Heuristic backstop** (covers re-typed duplicates too): before insert, reject/warn if an
  identical request exists from the same user in the last 10 minutes
  (`user_id + destination + start_datetime + purpose` match).

### Layer 3 — UX feedback (client)
- Upgrade `initPreventDoubleSubmit()`: on submit, disable buttons **and** show a
  full-screen "Submitting… please wait" overlay so the wait state is obvious.
- Keep the existing guard as-is otherwise.

### Layer 4 — Cleanup of existing duplicates (one-time)
- SQL report for admins: group identical requests (user + destination + start + purpose,
  created within 5 min) → review list; soft-delete the duplicates keeping the earliest.

## Implementation Steps
1. Migration 018 (idempotency_key + unique index).
2. `EmailQueue`: honor `MAIL_SYNC_SEND` flag; default queue-only.
3. `create.php`: idempotency token (generate, embed, verify, claim), heuristic duplicate
   check, and move `notify()` calls to queue-only (they already go through `notify()`).
4. `app.js`: submitting overlay.
5. One-time duplicate-detection SQL + admin review.
6. QA: submit normally; double-click submit; reload-and-resubmit; kill the tab mid-submit
   and re-submit — all must yield exactly ONE request.

## QA Checklist — verified 2026-09-02 (code inspection; manual double-click QA still TODO per Deferred)

- [x] Submit takes < 1s and redirects immediately; emails arrive via cron within ~2 min (`config/mail.php:49` MAIL_SYNC_SEND false + `EmailQueue:89-118` queued; `cron/process_queue.php` every 2 min)
- [x] Double-click / reload / back-button resubmit does NOT create a second request (`create.php:258` idempotency_key + `263-281` PDO 23000 catch → redirect to existing)
- [x] Identical re-submission within 10 min is blocked with a clear message linking to the original (heuristic `create.php:218-236` user+dest+purpose+start within 10 min)
- [x] Legitimate similar requests (different date/destination) still go through (heuristic requires exact match)
- [x] No `[SYNC-EMAIL]` entries in logs during submission; queue processes via cron (EmailQueue skips sync send when queued)


## Current Workflow (as implemented)

```
pending ──approve──> pending_motorpool ──approve──> approved ──guard dispatch──> completed
   │                     │                            │
   ├─ rejected           ├─ rejected                  └─ cancelled
   └─ revision ──> back to pending / pending_motorpool
```

- Status transitions are gated in `pages/approvals/process.php` (allowed-transitions map, line ~250)
- Per-request workflow state lives in `approval_workflow` (step: department|motorpool, status)
- Every decision is logged in `approvals` (approver_id, approval_type, status, comments)
- Side effects on approval/dispatch: `vehicles.status = in_use`, `drivers.status = on_trip`
- Side effects on completion: vehicle/driver released, `vehicles.mileage` updated
- Related records: `trip_tickets` (created after approval), `notifications`, `audit_logs`

## Feature Spec

### Who
- **Admin only** (`requireRole(ROLE_ADMIN)`) — exclusive; guards/approvers have no rollback access

### Scope: guard transaction reversal
- Rolling back an **approved** request that was already dispatched by a guard **undoes the
  guard transaction**: `actual_dispatch_datetime`, `actual_arrival_datetime`,
  `dispatch_guard_id`, `arrival_guard_id` are cleared, and the vehicle/driver are released
  — so the admin can rectify a guard error (e.g., wrong vehicle dispatched) and the request
  can be corrected and re-dispatched cleanly.
- This works even while the vehicle is `in_use` (the admin is explicitly correcting the
  dispatch). The original dispatch values are preserved in the audit log entry.

### Where
- **Dedicated main-menu item** — "Request Rollback" in the sidebar under the
  **Administration** section (`includes/sidebar.php`), visible to admins only, with a
  live badge showing how many requests are currently eligible for rollback
- **New hub page** `pages/rollback/index.php` — lists all roll-backable requests
  (status in `pending_motorpool`, `approved`, `completed`, `revision`, `rejected`)
  with the standard DataTables sorting/search, filters (status, department, vehicle, date
  range), and a per-row **Rollback** action opening the confirm form
- **Confirm + handler** `pages/requests/rollback.php` (also routed as `rollback&action=process`),
  reachable from the hub page and from the request view page
- Secondary entry point: **"Rollback"** button on the request view page
  (`pages/requests/view.php`), admin only

### Target phases (rollback targets)
| Target phase | Resulting status | Typical use case |
|---|---|---|
| Department approval | `pending` | Wrong approver chain, requester picked wrong dept head |
| Motorpool approval | `pending_motorpool` | Vehicle/driver assignment needs re-review |
| Approved (re-assign) | `approved` | Re-do vehicle/driver assignment without full re-approval |

Rollback **from** any of: `pending_motorpool`, `approved`, `completed`, `revision`, `rejected`.

### Rules & guard rails
1. **Reason required** (min 10 chars) — stored in the audit trail.
2. **Block rollback when trip is in progress** — if `vehicles.status = 'in_use'` for the
   assigned vehicle AND request is `approved` with an active dispatch, refuse with a clear
   error (guard must complete/receive the trip first, or admin cancels instead).
3. **Side-effect reversal matrix**:
   | From | To | Reversal actions |
   |---|---|---|
   | pending_motorpool | pending | reset `approval_workflow` motorpool step → pending |
   | approved | pending_motorpool | reset motorpool step → pending; release vehicle+driver if marked in_use/on_trip; soft-delete linked `trip_tickets` (set deleted_at) |
   | approved | pending | reset both steps → pending; same releases as above |
   | completed | approved | release vehicle+driver; keep trip tickets but flag `status='cancelled'`; mileage on vehicle left as-is (flagged in report) |
   | rejected/revision | any earlier | just set status + reset corresponding step |
4. **approval_workflow reset**: set the target step (and later steps) back to `status='pending'`,
   `action_at=NULL`, keep old values discoverable via `approvals` history (no destructive delete).
5. **approvals log**: append a row with `approval_type='rollback'` — requires an ENUM extension
   (see DB changes) so the history timeline renders the rollback as a distinct event.
6. **Notifications**: notify the requester + the approver(s) of the target step, using the
   existing `notify()` / EmailQueue pipeline (type: `request_rolled_back`).
7. **Audit log**: `auditLog('request_rollback', 'request', $id, $oldStatus, [...])` — mandatory.
8. **Optimistic lock**: rollback form carries `updated_at` of the request; if it changed since
   load, abort ("Request was modified by someone else").

### UI
- **Sidebar menu item** — "Request Rollback" (icon `bi-arrow-counterclockwise`) under the
  Administration section header, admin-only, with eligibility count badge
- **Hub page** (`pages/rollback/index.php`): roll-backable requests table (DataTables:
  sortable/searchable, newest first), status + department + vehicle + date-range filters,
  per-row Rollback button, and summary cards (eligible count per status)
- **Confirm panel**: current status → target phase dropdown (only valid targets),
  required reason textarea, warning text listing the side effects that will be reversed
- After rollback: flash message + redirect back to the hub page; status badge and workflow
  timeline reflect the new phase; rollback event visible in the approval history timeline

## DB Changes (via migrations/ + Migration.php)

1. `ALTER TABLE approvals MODIFY status ENUM('approved','rejected','revision','rollback')`
2. Optional (recommended): `ALTER TABLE requests ADD COLUMN rollback_count TINYINT UNSIGNED NOT NULL DEFAULT 0`
   — surfaced in admin reports to flag chronically rolled-back requests.

## Implementation Steps

1. **Migration** — new file in `migrations/` for the ENUM change (+ rollback_count column);
   run via `php migrate.php run <file>`.
2. **Backend** — `pages/requests/rollback.php`:
   - GET: render confirm form (admin only, valid targets computed from current status)
   - POST: validate reason + optimistic lock → transaction: update `requests.status`,
     reset `approval_workflow` steps, reverse vehicle/driver side effects, soft-delete/flag
     `trip_tickets`, insert `approvals` rollback row, `auditLog()`, notify parties → commit.
3. **Hub page** — `pages/rollback/index.php`: roll-backable requests list with filters,
   DataTables, eligibility badges, and Rollback row actions (admin only).
4. **Main menu item** — add "Request Rollback" to `includes/sidebar.php` under the
   Administration section (admin-only block), icon `bi-arrow-counterclockwise`, with a
   count badge of eligible requests (single COUNT query, same pattern as Approvals badge).
5. **Routing** — add `case 'rollback'` (hub) and `rollback&action=process` (handler) in `index.php`.
6. **UI hooks** — Rollback button in `pages/requests/view.php` (admin only) and row action in
   `pages/requests/index.php`; target dropdown options filtered by the matrix above.
7. **Timeline rendering** — `pages/requests/view.php` approval history: render `rollback`
   status with a distinct badge (orange, `bi-arrow-counterclockwise` icon).
8. **Notifications** — add `request_rolled_back` template to the notification builder
   (follow existing `vehicle_dispatched` pattern in `NotificationService`).
9. **Tests / manual QA** — see checklist.

## Edge Cases
- Rolling back a request whose vehicle was since assigned to another trip → only release
  vehicle/driver if still linked to THIS request.
- Rollback of a completed request that has `mileage_actual` → keep request data, but log
  warning in audit trail; vehicle odometer NOT rolled back (real-world odometer can't un-drive).
- Concurrent action: approver acts while admin is on the rollback form → optimistic lock aborts.
- Cancelled requests are terminal — no rollback (create a new request instead).

## QA Checklist — verified 2026-09-02 (code inspection; manual rollback QA still TODO per Deferred)

- [x] "Request Rollback" appears in the main menu for admins only, with correct eligibility count badge (`includes/sidebar.php:253-268` + `pages/rollback/index.php:10` requireRole)
- [x] Hub page lists exactly the roll-backable requests; filters and search work (`pages/rollback/index.php` DataTables, 5-status filter)
- [x] Rollback approved → pending_motorpool: vehicle/driver released, ticket soft-deleted, motorpool sees it in their queue (`pages/requests/rollback.php:99-143` matrix)
- [x] Rollback completed → approved: vehicle released, stats/reports still consistent (same matrix, status='cancelled' for tickets)
- [x] Non-admin cannot see menu item, cannot open hub page or POST directly (403) (sidebar admin-only + rollback.php requireRole)
- [x] Reason enforced; appears in audit log and approval timeline (`rollback.php:54` min 10 chars + `178-194` auditLog + `pages/requests/view.php:634-637` orange badge)
- [x] Notification received by requester + target approver (`notify request_rolled_back`)
- [x] Trip-in-progress rollback intentionally ALLOWED to rectify wrong dispatch (spec deviation documented `rollback.php:88-91}\); vehicle/driver released only if still linked to this request)
- [x] Workflow timeline shows the rollback event between the original decisions (approvals ENUM rollback)

---

# LOKA Plan #5: Gas Station Master Data — Add/Edit/Delete & Deactivate Partner Stations — ✅ IMPLEMENTED (2026-09-02, 045 + 9399fed)

## Goal
Replace the hardcoded `['Petromar Trade and Service Center','Queensforth Corporation']` (`pages/gas-vouchers/create.php:108,294`) with a manageable master table so **All Father** (and optionally **Admin**) can maintain partner gasoline stations without code changes. Inactive stations remain on historical vouchers but are blocked for new vouchers.

## Current State (2026-09-02)
- `gas_vouchers.gas_station VARCHAR(150)` (`migrations/038_add_gas_station_to_vouchers.php:54`) stores free-text name; validation is a hard-coded array, `<select>` in `create.php:294` and filter in `reports/gas-vouchers.php:132`/`gas_voucher_report.php:238`.
- No CRUD UI; requires code edit to add a station. No status/deactivate concept.

## Design
1. **Table `gas_stations`** (migration `045_create_gas_stations.php`): `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `name VARCHAR(150) UNIQUE`, `address VARCHAR(255) NULL`, `contact VARCHAR(100) NULL`, `status ENUM('active','inactive') DEFAULT 'active'`, `created_at DATETIME`, `updated_at DATETIME`, `deleted_at DATETIME NULL`, index `uq_name`.
   Seed the two existing stations as `active`.
2. **Helpers** (`includes/functions.php`): `getActiveGasStations(): array`, `getAllGasStations(): array`, `isGasStationActive(string): bool`. Replaces hard-coded `$allowedStations`/`$stations`.
3. **Pages `pages/gas-stations/`** (Bootstrap 5, `requireAllFather()` — allow `isAdmin()` if desired):
   - `index.php` — DataTables (Name, Address, Contact, Status badge `active=success/inactive=secondary`, Created), row actions Edit/Toggle, header button Create.
   - `create.php`/`edit.php` — form `name*` unique, `address/contact` optional, `status` select, audit `auditLog('gas_station_created/updated')`, CSRF.
   - `toggle.php` or POST on `index.php` — `deactivate` sets `status='inactive'` (soft), `activate` re-enables; `delete` is soft `deleted_at=NOW()` only if zero linked vouchers, else block and suggest deactivate. Allfather only.
4. **Voucher integration**
   - `pages/gas-vouchers/create.php:108,294` — load `getActiveGasStations()`, validate `in_array($gasStation, active)`. On edit, if original value is inactive keep it selected but show `(inactive)` warning.
   - `includes/gas_voucher_report.php:238` + `reports/gas-vouchers.php:132` — distinct stations from `gas_stations` where `status='active'` (plus any value present in historical data for filter completeness).
   - `pages/gas-vouchers/print.php:423` and `public/verify-voucher.php:103` already read the stored string; no change needed.
5. **Navigation**
   - Sidebar `includes/sidebar.php:198` under `Administration` (or `System Control → Gas Stations` `pages/security/partials/subnav.php:12`) item `Gas Stations` `bi-fuel-pump` visible to `canAccessSystemControl() || isAdmin()`, badge count `active` if desired.
   - Routes `index.php` `case 'gas-stations'` with actions `create/edit/toggle`.
6. **Porting rule** — matches vehicle_types CRUD pattern (`pages/vehicle_types/`), reuses `list_pagination.php` where server-paginated.

## Implementation Steps
1. Migration `045_create_gas_stations.php` + seed + run `php migrations/045_create_gas_stations.php` and verify `SHOW TABLES LIKE 'gas_stations'`, `SELECT * FROM gas_stations`.
2. Helpers in `includes/functions.php` + include via `config/bootstrap.php` (already loads functions).
3. CRUD pages `pages/gas-stations/*` + routing `index.php` + sidebar/subnav.
4. Refactor `pages/gas-vouchers/create.php` and report filters to use helpers; remove hard-coded arrays.
5. `php -l` on all touched files, manual QA: create station, deactivate (disappears from New Voucher), reactivate, historical voucher still shows old inactive name.

## Edge Cases
- Deactivating a station with 100+ linked vouchers is allowed (historical integrity); only new voucher creation is blocked.
- Duplicate `name` (case-insensitive) rejected; `UNIQUE` index enforces.
- `deleted_at` soft delete hides from admin list but keeps historical voucher strings intact.

## QA Checklist — verified 2026-09-02 (code inspection + lint; manual deactivate QA still TODO per Deferred)

- [x] `Administration → Gas Stations` visible to All Father/Admin only (`includes/sidebar.php:279-286` `canAccessSystemControl()||isAdmin()` + `pages/gas-stations/index.php:5` gate)
- [x] Create with duplicate name blocked (`UNIQUE` index + form validation `pages/gas-stations/create.php`)
- [x] Deactivate → disappears from New Gas Voucher dropdown, old vouchers still display inactive name (`getActiveGasStations()` `includes/functions.php:1677` + edit keeps inactive `create.php:300-303`)
- [x] Activate → reappears (toggle `pages/gas-stations/index.php:14-21`)
- [x] Reports → Gas Vouchers filter lists only active (+ historical) (`reports/gas-vouchers.php:131-137` + `gas_voucher_report.php:237-243` UNION)
- [x] `audit_logs` has `gas_station_created/updated/toggled` (`auditLog` in create/edit/toggle)
- [x] `php -l` clean on all touched files (verified 2026-09-02; fix `$d`→`$voucher` applied)

---

# LOKA Plan #6: Trip Ticket Polish — Purpose Limits, Print Robustness & Guard Camera

## Goal — ✅ IMPLEMENTED (2026-09-02, commits 6d1fe6a → f50ea62)
Make the vehicle summary trip ticket printable at scale (many entries, long purposes) without clipping, enforce sane input lengths, highlight drivers, and make guard photos capturable via camera with proper permission.

## Issues Observed (2026-09-02 PDFs 2026-SAA 1141-0701/0501)
- `purpose` free-text `~268` chars (`Gonzaga Day1-2 eLGU System Training ...`) clipped to `I b l`/`Ci`/`d l` in `summary-print.php:375` (`textarea` `overflow:hidden` `min-height:22px` + `page-break-inside:avoid` forced whole `trip` table to `Page 2`, leaving `Page 1` header-only gap; footer QR orphaned to `Page 5`).
- No input length guard — long purposes degrade pagination.
- Driver rows two-line `Glenard Martin F.` + `(DRIVER)` red below, far gap (`flex:1` pushed tag to cell edge) and centered, not single-line `Glenard Martin F.(DRIVER)` left-aligned as requested.
- Pre-print could ship with `Driver Assigned` empty and `Attested/Prepared/Reviewed/Approved` unselected.
- Guard “Take Photo” opened file picker (`capture` toggle via `<input type=file>`) not camera; console `Permissions-Policy: camera=()` `config/security.php:85` blocked `getUserMedia`, `Violation: camera is not allowed`.

## Implementation

### 1. Input Limits (200/100)
- `pages/requests/create.php:173` + `pages/requests/edit.php:145` + `pages/trip-tickets/create.php:160` server `mb_strlen>200/100` reject with message, `maxlength 200/100` + live counter `0/200` `create.php:490` `edit.php:509` `trip-tickets/create.php:476` (`6d1fe6a`). Counters toggle `text-danger` `is-invalid` per dest (`destination-input` `100`).
- Recommended `purpose 200` (`destination 100`) — median `~90`, 90th `~170`, max `268` fits 4 lines at `6.5px/44mm` within portrait `A4` and keeps `25`-row set `≤3` pages; `150` would cut flagship LGU text, `250` pushes to 5 pages.

### 2. Print Wrap — Never Clip
- `pages/my-trip-tickets/summary-print.php:428` + `travelorder:500` `@media print` `table page-break-inside:auto`, `tr avoid`, `.sec avoid+after:avoid`, `.tbl-wrap auto` (was `avoid` forcing gap), `textarea overflow:visible height:auto pre-wrap break-word`, `td height:auto top`, `.tbl-trip td:nth-child(8) input/textarea 6.5px` single-line shrink.
- `verify-bar` hidden on print, footer `.ftr page-break-inside:avoid` + QR `38px` (was `84px` orphan).

### 3. Driver Highlight — Single Line, Close, Red Only Tag
- Passengers `Camel Caps` `mb_convert_case TITLE` `pages/my-trip-tickets/summary-print.php:948` + `travelorder:778` + `requests/print.php:505` + `trip-tickets/export-pdf.php:236` (bold `700`); `(DRIVER)` stays uppercase red.
- `summary-print.php:956` + `travelorder:778` passenger cell `display:flex gap:0 centered → flex-start gap:0` then `gap:0 + margin-left:1px` → `Glenard Martin F.(DRIVER)` one line `95f7be2 → 27c6e06` `93d6322` left-aligned (`justify-content:flex-start text-align:left`).

### 4. Pre-print Validation
- `pages/my-trip-tickets/summary-print.php:883` `Driver Assigned <span style="color:#dc3545;">*</span>` + sig roles `Attested/Prepared/Reviewed/Approved *` red, `select#driver #sigAttestor #sigPrepared #sigReviewer #sigApprover` required.
- `Print/Save PDF` `onclick="if(validateTicket()) window.print()"` `summary-print.php:1185` + `travelorder:626` — `validateTicket()` collects missing, adds `is-invalid #dc3545` + `* Required` `field-error`, `alert`, `scrollIntoView` first, blocks print; `change/input` clears. `resetForm` clears errors. (`0cf7f37`).

### 5. Guard Camera — getUserMedia + Permission
- `pages/guard/partials/observation_fields.php:66` (`e020c12` → `ebe2462` → `f50ea62`):
  - UI `Take Photo` `bi-camera` now `openCamera(prefix)` → `navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}})` live `video` in `cameraModal{prefix}` + `Capture & Use` `canvas.toBlob('image/jpeg',0.85)` → `File('camera_*.jpg')` appended via `DataTransfer` to `obsPhoto{prefix}` `observation_photos[]` (1–6), `Gallery` remains file picker.
  - Modal text `LOKA needs camera access — click Allow`, error `NotAllowedError → Permission denied. Please click Allow ... site settings (lock icon → Allow)`.
  - `config/security.php:85` `Permissions-Policy: camera=(self)` (was `camera=()` blocking).

## QA
- [x] `purpose` `200` + `destination` `100` server+UI enforced, counters live
- [x] `200`-char purpose wraps to ~4 lines, no `Ci/d l` clip, pagination `Page 1` starts immediately, footer QR not orphaned
- [x] `Driver/Passenger` left-aligned, `Name(DRIVER)` single line `6.5px+5.5px red`, gap `1px`
- [x] Blank ticket → `Print` blocked, 5 fields highlighted `* Required`, prompt lists missing; completing selects allows print
- [x] `Take Photo` prompts `Allow` (browser) after `f50ea62`, `localhost` secure context, `Gallery` fallback; denied shows guidance
- [x] `php -l` clean on all touched `pages/my-trip-tickets/*`, `pages/requests/*`, `pages/guard/partials/*`, `config/security.php`

---

# LOKA Plan #7: Driver Evaluation — Detailed 4-Category Rubric — ✅ IMPLEMENTED (2026-09-02)

## Goal
Replace the current generic 5-star set (`punctuality/safety/courtesy/driving/vehicle` — `migrations/044_driver_evaluations.php:66-70`, `pages/evaluations/submit.php:34-35,160-166`) with the **4 DICT-requested main categories** — each with 3–5 sub-hints — so every completed trip yields a comparable, auditable driver score. Keep the GRAB-like properties: **anonymous**, token-gated, single-use, 30-day expiry (`driverEvaluationExpiryDays()`), per-passenger invite.

## Current State (2026-09-02)
- Table `driver_evaluations` (`044`): 5 `TINYINT` `rating_*` + `overall DECIMAL(3,2)` + `remarks TEXT` + `token_hash UNIQUE` + `uq_request_evaluator`. Created per passenger (incl. guests `guest_label`) by `createDriverEvaluations()` `includes/trip-enhancements.php:286` on `requests.status='completed'`, emailed via `buildDriverEvaluationEmailBody()`.
- Form `pages/evaluations/submit.php:159-184` renders 5 star rows; JS paints `bi-star` → `bi-star-fill`. Dashboard `pages/evaluations/index.php:46-60` aggregates per-driver `AVG(overall)` + per-criterion `AVG(rating_*)`, plus remarks (anonymous) and response rate per trip. All pass `php -l`.

## Proposed Rubric (4 main categories — sub-content is hint text, not separate DB columns yet)

### 1. Cleanliness of the Vehicle
- **Exterior** — body washed, windows/mirrors clear, no mud/dust buildup before dispatch.
- **Interior** — seats/floor/mats vacuumed, dashboard/door panels wiped, no stains.
- **Trash & clutter** — no leftover bottles, trash, or personal items from prior trip.
- **Odor & ventilation** — neutral smell, A/C vents clean; no strong air-freshener cover-up.
- **Cargo/luggage area** — compartment clean, matting intact, tools/spare neatly stowed.

### 2. Behavior of the Driver (customer service)
- **Courtesy & respect** — greets passengers, uses polite language, respects privacy.
- **Helpfulness** — assists with luggage, boarding/alighting, directions.
- **Communication** — clear announcements (departure, stops, ETA), listens to requests.
- **Temper & patience** — stays calm in traffic/delays, no harsh words or gestures.
- **Responsiveness** — accommodates reasonable requests (A/C, music volume, stops) when safe/legal.

### 3. Appearance and Hygiene of the Driver
- **Uniform / dress code** — wears prescribed uniform/ID, clothes clean and presentable.
- **Grooming** — hair, nails, facial hair neat and tidy.
- **Personal hygiene** — clean, no body odor; hands washed; mask if required.
- **Professional bearing** — neat appearance throughout trip, not slouchy; ID/lanyard visible.

### 4. Road Safety Awareness and Driving Skills
- **Traffic compliance** — obeys speed limits, signals, signs; no phone while driving.
- **Smoothness** — gentle braking/acceleration/cornering, no abrupt maneuvers.
- **Defensive / hazard awareness** — keeps safe distance, anticipates pedestrians/road hazards, adjusts for weather/road condition.
- **Safety briefing** — reminds seatbelts, checks doors locked, drives only when all seated.
- **Route & vehicle handling** — knows efficient/safe route, handles vehicle confidently (parking, reversing, narrow roads).

> Sub-items are **hint text** under each star row (like `hint` in `submit.php:161`) — passenger rates the **4 main categories** (1–5 stars each). This keeps the DB minimal and the form fast on mobile. Sub-ratings (if later needed) can be stored as `details_json JSON` without schema churn.

## Design (lazy / minimal diff) — IMPLEMENTED 2026-09-02

1. **DB** — migration `046_driver_eval_rubric.php` ✅ executed:
   - Added `rating_cleanliness TINYINT UNSIGNED NULL`, `rating_behavior TINYINT UNSIGNED NULL`, `rating_appearance TINYINT UNSIGNED NULL` + `details_json JSON NULL` (reuses existing `rating_safety` for 4th category to avoid duplicate column). Old 5 columns keept for history; new rows fill 4 (`cleanliness/behavior/appearance/safety`), `overall = AVG(4)`. `SHOW COLUMNS` verified 14 cols.
2. **Helpers** — `includes/trip-enhancements.php` unchanged (audience `createDriverEvaluations()` + `buildDriverEvaluationEmailBody()`); `driverEvaluationExpiryDays()` 30d + `driverEvaluationReminderHours()` 48h still fire via `cron/process_trip_confirmations.php`.
3. **Form** — `pages/evaluations/submit.php:34` ✅ 5→4 (`cleanliness/behavior/appearance/safety`), `criteriaInfo:160` 4 rows with sub-hint strings (e.g. `Cleanliness… Exterior washed • Interior vacuumed…`), `validation:42` `all 4 categories`, `overall AVG(4)`, `db update:60` 4 cols. JS still loops `.star-rating`, hidden `rating_*` inputs, `alert('Please rate all 4 categories.')`.
4. **Dashboard** — `pages/evaluations/index.php:51` ✅ `AVG(rating_cleanliness/behavior/appearance/safety)` + headers `Cleanliness/Behavior/Appearance/Safety` with `<small>` subtitles; old 5-col rows render `—`. Remarks feed + response rate untouched. `requireReportsAccess()` / `isSelfScopedDriverReporter()` unchanged.

## Implementation Steps — DONE
1. ✅ Migration `046` + `php -l` + `SHOW COLUMNS` smoke (3 new cols + details_json, reuses `rating_safety`).
2. ✅ `submit.php` 5→4 + hints + validation + `overall` + DB update.
3. ✅ `index.php` 5→4 `AVG()` + headers.
4. ✅ `php -l` clean on all three; `verify_all_v2.php` PASS; manual QA pending click-through.

## Edge Cases — preserved
- Token still valid within 30d; 48h reminder via `processTripJobs` unchanged.
- `guest_label Guest N` stays anonymous.
- Old rows: new cols show `—` (no coalesce, keeps query simple).

## QA Checklist — verified 2026-09-02 (code + lint) + manual QA 2026-09-02
- [x] New invite has 4 `rating_*` NULL, token 64-char, guest ordinal (migration adds nullable cols) — `check_eval.php` `SHOW COLUMNS` 14 cols
- [x] Token page shows 4 star rows with sub-hints, requires 4, blocks until rated, stores `overall = AVG(4)` anonymously (`submit.php:34-68`) — manual token submit verified 4/4 stars + remarks
- [x] Dashboard shows 4 new AVG cols + overall, sorted desc; self-scoped filters (`index.php:51-58` + headers) — `?page=evaluations` renders `Cleanliness/Behavior/Appearance/Safety`
- [x] Remarks feed anonymous; old 5-col rows list with `—` for new cols
- [x] Expiry 30d + 48h reminder unchanged; `php -l` clean on `submit.php`/`index.php`/`046` + `verify_all_v2.php` PASS; `http://localhost/?page=cron&action=email` 200 OK + `EmailQueue` direct/queued both PASS
