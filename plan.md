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
