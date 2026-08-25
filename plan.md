# LOKA Plan #4: Port Advanced Features from prod-loka — ✅ IMPLEMENTED (Phases 0–5; Phase 6 skipped by design)

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

### Implementation status (2026-08-24)
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
  Observation partials COPIED but NOT wired into guard flow (deferred — changes UX;
  see agent notes: guard/actions.php + requests/view.php wiring steps documented).
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
  no dashboard partialization.
- Migrations 030–040 executed against old_loka_db; all tables/settings verified.
- All PHP files lint clean; public routes smoke-tested (302 auth redirects correct,
  verify-voucher public 200, QR PNG renders).

### Deferred / known gaps
- [x] Guard observation + odometer field wiring into dispatch/arrival flow (DONE 2026-08-24:
      actions.php resolves odometer readings w/ broken-odometer skip, saves observations,
      notifies damage; modals embed odometer_fields/observation_fields partials with
      multipart forms; requests/view.php shows observation card)
- [ ] Assign a user role='all_father' to actually use System Control
- [ ] SMS gateway needs android-sms-gateway server config in System Control → SMS
- [ ] Schedule cron via Windows Task Scheduler or hit ?page=cron URLs periodically
- [ ] Manual QA of each module lifecycle (vouchers approve chain, care schedules, lockout unlock)

---

# LOKA Plan #3: Booking Rules Cleanup & Return-Confirmation Enforcement — ✅ IMPLEMENTED (pending manual QA)

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

## QA Checklist

- [ ] Toggle = Yes: completing an approved, un-returned trip from requests view is blocked with clear error
- [ ] Toggle = Yes: guard records arrival → trip completes normally, vehicle/driver released
- [ ] Toggle = No: old direct-complete path works as before
- [ ] Create/edit still enforce min notice / max advance / max duration per settings
- [ ] Changing settings reflects immediately in pickers and validation
- [ ] Out-of-range settings input shows which fields were corrected
- [ ] No remaining references to assets/js/api anywhere

---

# LOKA Feature Plan: Admin Workflow Rollback — ✅ IMPLEMENTED (commits 63c71ff, a2729f5)

## Goal

Allow **admins** to roll back a vehicle request to an earlier phase in the approval
workflow (e.g., send an approved request back to motorpool review, or back to the
department approver), with full audit trail and automatic reversal of side effects.
Admin rollback also **reverses guard transactions** (clears dispatch/arrival records).

---

# LOKA Plan #2: Prevent Duplicate Request Submissions

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

## QA Checklist
- [ ] Submit takes < 1s and redirects immediately; emails arrive via cron within ~2 min
- [ ] Double-click / reload / back-button resubmit does NOT create a second request
- [ ] Identical re-submission within 10 min is blocked with a clear message linking to the original
- [ ] Legitimate similar requests (different date/destination) still go through
- [ ] No `[SYNC-EMAIL]` entries in logs during submission; queue processes via cron


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

## QA Checklist
- [ ] "Request Rollback" appears in the main menu for admins only, with correct eligibility count badge
- [ ] Hub page lists exactly the roll-backable requests; filters and search work
- [ ] Rollback approved → pending_motorpool: vehicle/driver released, ticket soft-deleted, motorpool sees it in their queue
- [ ] Rollback completed → approved: vehicle released, stats/reports still consistent
- [ ] Non-admin cannot see menu item, cannot open hub page or POST directly (403)
- [ ] Reason enforced; appears in audit log and approval timeline
- [ ] Notification received by requester + target approver
- [ ] Trip-in-progress rollback is blocked with clear error
- [ ] Workflow timeline shows the rollback event between the original decisions
