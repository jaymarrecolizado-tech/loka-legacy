# LOKA Trip Enhancements v2 — Detailed Implementation Plan

**Target version:** 2.7.0
**Date:** 2026-09-01
**Author:** Plan for user request — 5 features (passenger mgmt + TO/OB toggle + confirmatory email + overdue alerts + anonymous driver evaluation)
**Codebase:** `public_html/` PHP 8.2 + MySQL, no framework. Router `index.php` → `pages/*`. Migrations `migrations/*.php`. Mail queue `classes/EmailQueue.php`.

> This plan is **execution-ready**. It inventories what is already implemented vs what is still missing/truncated, and defines every file, schema, and QA step. No code is changed in this plan step.

---

## 0. Terminology

| Term | Definition |
|---|---|
| On routing | `requests.status` in `pending` (dept step) or `pending_motorpool` (motorpool step) — `config/constants.php:61-62`, `pages/approvals/process.php:249` |
| Approved | `status='approved'` after both steps pass. Guard has not yet dispatched (`actual_dispatch_datetime IS NULL`) unless noted |
| Dispatched | `actual_dispatch_datetime` set by `pages/guard/actions.php:113` |
| Completed | `status='completed'` after `actual_arrival_datetime` set or `pages/requests/complete.php:64` |
| Passenger | Row in `request_passengers` — either `user_id` (system user) or `guest_name` (free text) — `pages/requests/create.php:299` |
| TO/OB | Travel Order / Official Business Slip |

---

## 1. Verified Current State (audit 2026-09-01)

### 1.1 Already implemented and wired

| Area | File | Status |
|---|---|---|
| Constants/roles | `config/constants.php:39-68` — `ROLE_MOTORPOOL=4, ROLE_ADMIN=5, ROLE_ALL_FATHER=99`, statuses `STATUS_*`, labels | Done |
| Helpers | `includes/functions.php:618-750` `notifyPassengers/Batch`, `1425 notifyRoleUsers`, `1498 countRequestPassengers`, `1513 normalizeRequestPassengerIdsFromPost` | Done |
| Trip helpers | `includes/trip-enhancements.php:1-494` — `requireTravelOrderUpload()`, `tripConfirmationEnabled/LeadHours/SameDayLeadMinutes/WindowMinutes`, `tripOverdueRenotifyHours`, `driverEvaluation*`, `createTripConfirmation`, `findTripConfirmationByToken`, `notifyMotorpoolHeads`, `createDriverEvaluations`, `build*EmailBody` — loaded in `config/bootstrap.php:59` + `index.php:82` | Done |
| File upload | `classes/FileUpload.php:297 handleTravelOrderFileUpload via createTravelOrderHandler` | Done |
| Request create TO enforcement | `pages/requests/create.php:205-296` — checks `requireTravelOrderUpload()` and calls `handleTravelOrderFileUpload()` in tx | Done |
| File download display | `pages/requests/view.php:407-426` + `includes/functions.php:1254 fileDownloadLink` + `pages/file-view.php` | Done |
| Router — passenger mgmt + confirm | `index.php:207-211` dispatches `requests/confirm` → `pages/requests/confirm.php` and `manage-passengers` | Done |
| Router — evaluations + driver-rankings | `index.php:339-342,497-505` routes `evaluations/submit`, `reports/driver-rankings` etc | Done (routes exist, targets missing — see gaps) |
| Confirm page (public token) | `pages/requests/confirm.php:1-346` — proceed / decline → cancel or reschedule (back to `pending_motorpool` + `reschedule_requested=1`) with audit + `notifyMotorpoolHeads` + release vehicle/driver on cancel | Done |
| Cron processor | `cron/process_trip_confirmations.php:1-271` `processTripJobs()` handles 4 jobs: SEND (re-issue raw token), EXPIRE (default proceed), OVERDUE (renotify every `tripOverdueRenotifyHours`), REMINDERS (driver eval) | Done |
| HTTP cron | `pages/cron/index.php:58-74` `action=trips` → `processTripJobs()` secured by `cron_secret` | Done |
| Migrations | `migrations/042` (travel_order + overdue + 2 settings), `043` (trip_confirmations + reschedule flags + 4 settings), `044` (driver_evaluations + 2 settings) — idempotent INSERT ... WHERE NOT EXISTS | Done — files present, need `php migrations/04x` run + verify |
| Manage-passengers — first half | `pages/requests/manage-passengers.php:1-150` — GET gate (allowed statuses `pending/pending_motorpool/approved` + `actual_dispatch IS NULL`), auth (`isAdmin/isAllFather` or `isMotorpool` + assignment), POST `add_user` with capacity/duplicate/driver checks + audit + `notify()` | Partial — see gaps |

### 1.2 Gaps — files truncated or missing

| Gap | File | Evidence |
|---|---|---|
| Manage-passengers truncated | `pages/requests/manage-passengers.php:150` marker `/* __MP_POST2__ */` then jumps to `/* __MP_UI__ */` — missing handlers for `add_guest`, `remove`, commit/rollback error paths, and the full HTML UI (current file ends at `require footer` with no form) | `manage-passengers.php:150-171` |
| No TO toggle UI | `pages/settings/index.php:39-45` only handles `system_name, max_advance_booking_days, min_advance_booking_hours, max_trip_duration_hours, require_return_confirmation` | `settings/index.php` |
| No confirmation creation hook | No call to `createTripConfirmation()` observed in `pages/approvals/process.php` after final `STATUS_APPROVED` commit | `approvals/process.php:469-560` (approval finalization) |
| No evaluation creation hook | No call to `createDriverEvaluations()` in `pages/guard/actions.php:record_arrival` (primary completion) nor `pages/requests/complete.php` | `guard/actions.php:260-347`, `requests/complete.php:64-176` |
| Evaluation pages missing | `pages/evaluations/` directory does not exist; glob returned empty yet `index.php:497` routes `evaluations/submit` + `evaluations/index` | `index.php:497-505` |
| Driver rankings report missing | `pages/reports/driver-rankings.php` + `export-driver-rankings-csv.php` routed `index.php:339-342` but not in `pages/reports/*` listing | `index.php:339-342`, `pages/reports/` glob |
| Sidebar entries missing | `includes/sidebar.php:198-307` has no Driver Rankings / Evaluation entries | `sidebar.php` |
| Overdue UI missing | No red "Overdue" badge/filter in `pages/requests/index.php` or schedule calendar; plan calls for it in `docs/TRIP_ENHANCEMENTS_PLAN.md:125` | `requests/index.php`, `schedule/calendar.php` |
| Cron not in deploy | `setup.sh` only installs `process_queue.php` (every 2m); no `process_trip_confirmations.php` (every 5m) | `setup.sh`, `README.md` cron section |
| Mail templates split | `config/mail.php:80-248` `MAIL_TEMPLATES` has no `trip_confirmation*`, `trip_overdue_alert`, `driver_evaluation_*`; builders in `trip-enhancements.php:424,453` use direct HTML queue instead — works but inconsistent; should add keys or document intent | `config/mail.php`, `trip-enhancements.php` |
| Settings gate | `pages/settings/index.php:6` uses `requireRole(ROLE_ADMIN)` — all_father (99) passes via level but semantics unclear for toggle ownership; needs explicit "admin + all_father" wording | `settings/index.php:6`, `functions.php:140 isAdmin` |

### 1.3 Decisions to lock before build

1. On-routing definition = `pending` + `pending_motorpool` — **confirmed** above.
2. Guests have no email → evaluation tokens still created (useful for shareable link) but no email sent — current `createDriverEvaluations:355-371` does exactly this.
3. Reschedule re-approval = only motorpool step (`pending_motorpool`), not full dept re-review — `confirm.php:139` sets `STATUS_PENDING_MOTORPOOL`.
4. Confirmatory email recipient = request **creator** only — `process_trip_confirmations.php:36-43` fetches `r.user_id` email.
5. `require_travel_order_upload` default = OFF (`0`) per `042:86`; confirmation + overdue defaults = ON per `043:102,044:88`.

---

## 2. Feature 1 — Motorpool Head add/delete passengers on APPROVED and ON-ROUTING

### 2.1 Rules (server-enforced, never just UI)

- Allowed when `status IN ('pending','pending_motorpool','approved')` AND `actual_dispatch_datetime IS NULL`. Code already checks this `manage-passengers.php:40-47` and re-checks inside `FOR UPDATE` tx `88-91`.
- Who: `isAdmin() || isAllFather()` OR `isMotorpool()` where `motorpool_head_id == userId()` or no head assigned (mirrors `requests/complete.php:50-61`).
- Add supports both system users (autocomplete via `getEmployees()` excluding requester) and guest names (same flow as `create.php:299` / `normalizeRequestPassengerIdsFromPost`).
- Constraints inside transaction:
  - No duplicate `user_id` or `guest_name` on that request.
  - `COUNT(passengers)+1 <= vt.passenger_capacity` when vehicle assigned (else no cap).
  - Driver's user never listed as passenger (`drivers.user_id` check `manage-passengers.php:108-119`).
  - Always re-sync `requests.passenger_count = countRequestPassengers(requestId)`.
- Every mutation in DB transaction + `auditLog('passenger_added'/'passenger_removed'/'passenger_updated', 'request', id, old, new)` and `notify()` to added/removed user (`added_to_request` / `removed_from_request` mail keys exist in `config/mail.php`).
- Concurrent edits: `SELECT ... FOR UPDATE` already in file `manage-passengers.php:78-84`.

### 2.2 Changes to complete Feature 1

| File | Action | Detail |
|---|---|---|
| `pages/requests/manage-passengers.php` | **Complete** | Fill `/* __MP_POST2__ */` block: handlers for `add_guest` (normalize, dedupe, capacity check, insert `guest_name`), `remove` / `remove_guest` (delete `request_passengers` row by `id`, re-sync count, audit, `notify` removed user if system user). Add missing `else` + `commit/rollback` branches, error accumulation, success flash. Fill `/* __MP_UI__ */` with full Bootstrap UI: header/breadcrumb + request summary card (destination, dates, vehicle capacity, current count) + passengers table (name/dept + remove button per row with CSRF + confirm) + add-user select2/autocomplete + add-guest input + capacity warning. Reuse `header.php`/`footer.php` pattern. Lint with `php -l`. |
| `pages/requests/view.php` | **Edit** | Ensure "Manage Passengers" button visible per same gate (`view.php:175-198` already does this — verify label/icon `bi-people`) and link `?page=requests&action=manage-passengers&id=X`. Add small "passenger updated" line from latest audit log if present. |
| `pages/approvals/view.php` | **Edit** | Same button for on-routing requests under review so approver can see it (optional — low priority). |
| `includes/functions.php` | Reuse | `countRequestPassengers` + `normalizeRequestPassengerIdsFromPost` already exist — no change. |
| `config/mail.php` | Verify | Templates `added_to_request` / `removed_from_request` already present — no change. |
| `pages/requests/manage-passengers.php` auth | Keep | `requireAuth()` at top + gate pattern already correct; no new role needed. |

### 2.3 Edge cases

- Removing the last companion → `passenger_count` still =1 (requester himself).
- Guest removal: no notification (no user_id) — audit only.
- Trip dispatched between page load and POST → `FOR UPDATE` check aborts with "can no longer be modified".
- Vehicle unassigned yet → skip capacity check (only when `passenger_capacity > 0`).

---

## 3. Feature 2 — TO / OB Slip upload enforcement toggle (Admin + All Father)

### 3.1 Spec

- New setting key `require_travel_order_upload` `bool` `booking` — already seeded default `0` in `042:86`.
- Helper `requireTravelOrderUpload()` reads `tripSetting('require_travel_order_upload','0')==='1'` with static cache `trip-enhancements.php:72-79`.
- When **ON**: `pages/requests/create.php:205` blocks submit if `$_FILES['travel_order_file']['name']` empty; transaction also validates via `handleTravelOrderFileUpload` `276-296`. Same check should be added to `pages/requests/edit.php` if that file allows resubmit.
- File types: `pdf,jpg,jpeg,png` 5 MB via `FileUpload::createTravelOrderHandler(requestId)` — already defined `FileUpload.php:297-309`; stored `uploads/travel_orders/<requestId>/to_<uniqid>.ext` with `.htaccess Deny from all`; served via `pages/file-view.php`.
- Display: `pages/requests/view.php:407` card already renders download link when file present.
- Toggle ownership: **Admin and All Father** — respects existing `requireRole(ROLE_ADMIN)` (all_father passes via level 99) but make it explicit in the UI copy. Alternatively change gate to `requireAnyRole([ROLE_ADMIN, ROLE_ALL_FATHER])` for clarity.

### 3.2 Changes

| File | Action | Detail |
|---|---|---|
| `pages/settings/index.php` | **Edit** | Add new card "Travel Documents" (or extend Booking Rules) with `<select name="require_travel_order_upload">` Yes/No, value from `$settings['require_travel_order_upload']`. Add to `$settingsToUpdate` with `'require_travel_order_upload' => post('require_travel_order_upload','0')==='1'?'1':'0'`. Extend `$corrected` tracking if needed. Audit already logs `settings_updated`. Gate: keep `requireRole(ROLE_ADMIN)` (all_father passes) OR change to `requireAnyRole([ROLE_ADMIN, ROLE_ALL_FATHER])` and add comment. Label must state "Enforce Travel Order / Official Business Slip upload during application" and note "Toggleable by Admin and All Father only". |
| `pages/requests/create.php` | **Keep** | Already enforces — verify same check exists in `edit.php` if edits can replace the file; add if missing. |
| `pages/requests/view.php` | **Keep** | Already shows required badge `710-729` when `requireTravelOrderUpload()` true — verify visible. |
| `migrations/042` | **Keep** | Already defines columns + setting default — run on deploy. |

---

## 4. Feature 3 — Pre-trip confirmatory email (GRAB-style Proceed / Don't Proceed)

### 4.1 Behavior (final)

- Every **approved** request gets one confirmatory email to the **creator**:
  - **Trip on later day** than creation: send `trip_confirmation_lead_hours` (default **24h**) before `start_datetime`.
  - **Trip same calendar day** as creation: send `trip_confirmation_same_day_lead_minutes` (default **60m**) before `start_datetime`. Applies when `start_datetime->format('Y-m-d') === created_at->format('Y-m-d')` — `trip-enhancements.php:181`.
  - Email contains two links (secure single-use token, no login): **Proceed** → confirms; **Don't Proceed** → landing offers **Cancel** or **Request Reschedule** (see §4.3).
  - Default when no response before `deadline_at = start_datetime - trip_confirmation_window_minutes` (default **60m**, min 15 `trip-enhancements.php:101`): mark `expired` ("no response — proceeding"), notify motorpool head + requester that trip proceeds — `process_trip_confirmations.php:112-162`.
- Scheduling math in `createTripConfirmation:175-214`: computes `scheduled_send_at`, `deadline_at`, clamps `sendAt < now => now`, aborts if deadline already passed (trip too soon to confirm). Never duplicates active confirmation (`status IN pending,confirmed`) and increments `cycle`.
- Creation trigger: on approval finalization where `requests.status` becomes `approved` — hook in `pages/approvals/process.php` after the approving transaction commits (new). Also re-create on reschedule re-approval with `cycle+1` (same helper handles cycle automatically).

### 4.2 Schema

`migrations/043` already creates `trip_confirmations` (`id, request_id, token_hash CHAR(64) UNIQUE, cycle, status ENUM pending/confirmed/declined_cancel/declined_reschedule/expired/cancelled, scheduled_send_at, sent_at, deadline_at, responded_at, reschedule_note, created_at, updated_at`) with keys `uq_token_hash`, `uq_request_cycle`, `idx_status_send`, `idx_deadline`; plus `requests.reschedule_requested/reschedule_note` and 4 `settings` defaults. No change.

### 4.3 Don't-Proceed outcomes (in `pages/requests/confirm.php` — already fully coded)

- **Cancel** `POST decline_action=cancel`: `trip_confirmations.status='declined_cancel'`, release `vehicles.status='available'` and `drivers.status='available'` if assigned, set `requests.status='cancelled'`, `auditLog('trip_declined_cancelled')`, notify passengers + driver + `notifyMotorpoolHeads('trip_cancelled_by_requester')`. `confirm.php:72-125`.
- **Reschedule** `POST decline_action=reschedule` with required note: `trip_confirmations.status='declined_reschedule' + reschedule_note`, set `requests.status='pending_motorpool', reschedule_requested=1, reschedule_note=note`, `auditLog('trip_reschedule_requested')`, `notifyMotorpoolHeads('trip_reschedule_requested')` — motorpool must approve again via normal `pages/approvals/process.php` path. New confirmation cycle auto-created on that re-approval via helper in §4.4. `confirm.php:127-165`.
- **Proceed** `GET choice=proceed`: set `status='confirmed'`, `auditLog('trip_confirmed')`, `notifyMotorpoolHeads('trip_confirmation_response')`.

### 4.4 Cron

`cron/process_trip_confirmations.php:72` `processTripJobs()` every 5m:
1. SEND: `status='pending' AND sent_at IS NULL AND scheduled_send_at <= NOW()` → re-issue `bin2hex(random_bytes(32))` + `token_hash=sha256`, `queue()` via `buildTripConfirmationEmailBody`, mark `sent_at`.
2. EXPIRE: `status='pending' AND deadline_at < NOW()` → `expired`.
3. OVERDUE: see §5. 4. REMINDERS: see §6.

Wired as HTTP fallback `pages/cron/index.php:58 action=trips` with `cron_secret`.

### 4.5 Changes to finish Feature 3

| File | Action | Detail |
|---|---|---|
| `pages/approvals/process.php` | **Edit — required** | After the transaction that sets `requests.status='approved'` commits (around `process.php:560-620` approval finalization), call `createTripConfirmation((int)$requestId)` (best-effort, inside try/catch, `error_log` on failure). Also when a rescheduled request is re-approved (same commit path, but `reschedule_requested` flag is set) — same helper will create next cycle automatically (creates `cycle+1`). Do not block approval on confirmation creation failure. |
| `pages/settings/index.php` | **Edit** | Add "Trip Confirmations" card with 4 inputs: `trip_confirmation_enabled` (bool select), `trip_confirmation_lead_hours` (int 1-168), `trip_confirmation_same_day_lead_minutes` (int 5-1440), `trip_confirmation_window_minutes` (int 15-720). Clamp with `trackClamp` same pattern as booking rules. Wire into `$settingsToUpdate`. Visible to Admin + All Father (same gate). |
| `config/mail.php` | **Optional** | Add `MAIL_TEMPLATES` keys `trip_confirmation`, `trip_confirmation_response`, `trip_cancelled_by_requester`, `trip_reschedule_requested` for consistency, OR explicitly document that confirmations use direct `EmailQueue::queue()` with HTML bodies (current approach) — avoid drift. Current code uses direct queue, so this is not a blocker. |
| `cron/process_trip_confirmations.php` | Keep | Already correct — ensure `processTripJobs` is called from both CLI and HTTP path. |
| `pages/cron/index.php` | Keep | Already handles `action=trips`. |
| `setup.sh` / `README.md` | **Edit** | Add crontab line `*/5 * * * * php /path/to/cron/process_trip_confirmations.php >> logs/cron.log 2>&1` and document `?page=cron&action=trips&key=SECRET`. |

### 4.6 Security

- Token: `bin2hex(random_bytes(32))` → store `sha256`, compare via `hash_equals` in `findTripConfirmationByToken` / `confirm.php:21`. Single-use via status guard (`pending` only) + `FOR UPDATE` lock on decline path. Public page is token-gated, `robots noindex`. CSRF not needed for GET proceed/decline (capability is the token); POST decline actions already use `csrfField()` in form `confirm.php:294,305` but page is unauthenticated — CSRF check should be lenient or skipped for token-gated public form (confirm.php currently does not call `requireCsrf()` — correct).

---

## 5. Feature 4 — Overdue-trip alerts to Motorpool Head

### 5.1 Definition

Overdue = `status='approved'` AND `deleted_at IS NULL` AND `actual_arrival_datetime IS NULL` AND `end_datetime < NOW()` — `process_trip_confirmations.php:168-177`. Renotify every `trip_overdue_renotify_hours` (default 24) while still overdue: `WHERE overdue_notified_at IS NULL OR overdue_notified_at < DATE_SUB(NOW, INTERVAL {hours} HOUR)`.

### 5.2 Behavior

- Inside `processTripJobs` job 3: set `requests.overdue_notified_at = NOW()`, `notifyMotorpoolHeads('trip_overdue_alert', 'Trip Exceeded Designated End Time', "Request #ID exceeded {end_datetime} ...", link)`. Falls back to all `role=motorpool_head` if `motorpool_head_id` is NULL — `trip-enhancements.php:244`.
- Clears automatically when guard records arrival (`actual_arrival_datetime` set) or trip is completed/cancelled (condition no longer matches).

### 5.3 Changes

| File | Action | Detail |
|---|---|---|
| `cron/process_trip_confirmations.php` | Keep | Job 3 already correct. |
| `pages/requests/index.php` | **Edit** | Compute `isOverdue = $row->status===STATUS_APPROVED && $row->actual_arrival_datetime===null && strtotime($row->end_datetime) < time()`. When true, add red badge `<span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Overdue</span>` next to status, and sort overdue rows first or highlight row `table-danger`. |
| `pages/schedule/calendar.php` | **Edit** | Same overdue check for calendar events; render overdue trips with red border/badge and tooltip "Exceeded designated end". |
| `includes/functions.php` or new helper | **Optional** | Add `isTripOverdue(object $request): bool` helper to avoid duplication. |
| `pages/settings/index.php` | **Edit** | Add input `trip_overdue_renotify_hours` (int 1-168, default 24) in Trips settings card, clamped. |
| Database | Keep | `requests.overdue_notified_at` already in `042:80`. |

---

## 6. Feature 5 — Post-trip anonymous driver evaluation (GRAB-like)

### 6.1 Flow

1. Trip marked **completed** (`guard/actions.php:record_arrival` is the primary path; `requests/complete.php` is the secondary motorpool direct-complete path which is already gated by `requireReturnConfirmation`). After the existing commit that frees vehicle/driver and audits `vehicle_arrived` / `request_completed`, call `createDriverEvaluations(requestId)` — best-effort.
2. `createDriverEvaluations:286` creates one row per passenger (system users AND guests, driver excluded). For users: sets `evaluator_user_id`, null `guest_label`; for guests: `evaluator_user_id=NULL, guest_label='Guest N'` (ordinal, never real `guest_name`). Idempotent — skips if `(request_id, evaluator_user_id)` already exists. Queues email via `EmailQueue::queue()` with `buildDriverEvaluationEmailBody` (link `/?page=evaluations&action=submit&token=...`, single-use, expires `driverEvaluationExpiryDays` default 30). Guests get no email but row/token created so requester can forward a shareable link if needed. Returns count.
3. Passenger opens token link → `pages/evaluations/submit.php` (public, no login) — star-rating form (1-5) for 5 criteria (`rating_punctuality/safety/courtesy/driving/vehicle`), computed `overall = AVG(5)`, plus free-text `remarks`. On submit stores ratings + `submitted_at`, burns token semantics via `submitted_at IS NOT NULL` guard. Identity **never rendered**.
4. Reminder cron: job 4 in `process_trip_confirmations.php:196-254` — once per pending evaluation where `submitted_at IS NULL AND reminder_sent_at IS NULL AND created_at <= NOW() - reminderHours (default 48)` and `evaluator_user_id IS NOT NULL` and `r.status='completed'` → re-issue raw token, set `reminder_sent_at`, queue reminder email.
5. Reports: per-driver averages + ranking, period filter, min-evaluations threshold, Chart.js bar; CSV export. Remarks shown **anonymously** (no attribution). Response-rate stats per trip shown but not who said what.

### 6.2 Schema

`migrations/044` already defines `driver_evaluations` (`id, request_id, driver_id, evaluator_user_id NULL, guest_label VARCHAR(50) NULL, token_hash CHAR(64) UNIQUE, rating_* TINYINT NULL 1-5, overall DECIMAL(3,2) NULL, remarks TEXT NULL, submitted_at DATETIME NULL, reminder_sent_at DATETIME NULL, created_at DATETIME`) with `uq_token_hash`, `uq_request_evaluator (request_id, evaluator_user_id)` (note: this UNIQUE disallows two guest rows for same request because both have NULL — see known issue §8), indexes `idx_driver, idx_request, idx_reminder`. Plus 2 settings defaults `driver_evaluation_reminder_hours=48`, `driver_evaluation_expiry_days=30`.

### 6.3 Changes

| File | Action | Detail |
|---|---|---|
| `pages/guard/actions.php` | **Edit — required** | In `record_arrival` after `db()->commit()` that marks completed and notifies `vehicle_arrived` / `trip_completed` (around line 310-347), add `try { createDriverEvaluations((int)$requestId); } catch (Throwable $e) { error_log(...) }` — do not fail the arrival on evaluation error. |
| `pages/requests/complete.php` | **Edit — required** | After same commit that completes the trip (around `complete.php:140-176` after `notifyPassengers`/`notifyDriver`), add same `createDriverEvaluations` hook (guarded so double-complete does not duplicate — helper is idempotent). |
| `pages/evaluations/submit.php` | **Create** | Public token page: GET `?page=evaluations&action=submit&token=RAW` → `findDriverEvaluationByToken` → if not found / `submitted_at IS NOT NULL` → "already submitted" card; else if `created_at + expiryDays < NOW()` → "link expired"; else render Bootstrap star-rating form (5 criteria × 5 stars via `bi-star-fill`, JS hover/click) + remarks textarea (maxlength 1000) + CSRF. POST validates 1-5 ints, computes `overall = round(avg(5),2)`, updates row `rating_*`, `overall`, `remarks`, `submitted_at=NOW()`, audit `evaluation_submitted`. Success card "Thank you — your feedback is anonymous". Standalone HTML like `confirm.php:208` (no login header) but include `APP_NAME`. |
| `pages/evaluations/index.php` | **Create** | Auth `requireReportsAccess()` (approver+ or tagged driver — same as reports). Query `driver_evaluations JOIN requests JOIN drivers JOIN users` aggregated. Show cards: response rate per trip (`submitted/completed`), per-driver averages, remarks list **without attribution** (render remarks with trip date + driver only). For `isSelfScopedDriverReporter()` scope to own `driver_id` only. Include period filter `?from=&to=` and min-evaluations threshold. |
| `pages/reports/driver-rankings.php` | **Create** | `requireReportsAccess()` + `denied if isSelfScopedDriverReporter`? (plan says full ops report is admin/all_father only — `canAccessOpsReports()` — but routing currently uses `canAccessReports()`; decide: keep `requireReportsAccess()` and scope self drivers to own row). SQL `SELECT driver_id, AVG(overall) avg_overall, AVG(rating_punctuality)... , COUNT(*) eval_count FROM driver_evaluations WHERE submitted_at IS NOT NULL AND created_at BETWEEN :from AND :to GROUP BY driver_id HAVING eval_count >= :min ORDER BY avg_overall DESC`. Render table (rank, driver name, avg overall, per-criterion avgs, count) + Chart.js horizontal bar of averages. Filters: date range, min evaluations (default 3). Button to export CSV. |
| `pages/reports/export-driver-rankings-csv.php` | **Create** | Same query as rankings; stream CSV `driver,avg_overall,avg_punctuality,avg_safety,avg_courtesy,avg_driving,avg_vehicle,eval_count`; headers `Content-Disposition`. |
| `includes/sidebar.php` | **Edit** | Under Reports header `sidebar.php:198-208`, add entry "Driver Rankings" icon `bi-star-half` gated by `canAccessReports()` (or `canAccessOpsReports()` if restricting to admin) with link `?page=reports&action=driver-rankings`; add entry "Evaluations" link `?page=evaluations` if keeping the index page in nav. |
| `pages/settings/index.php` | **Edit** | Add inputs `driver_evaluation_reminder_hours` (1-720) and `driver_evaluation_expiry_days` (1-90) clamped, in Trips/Evaluations card. |
| `config/mail.php` | Optional | Add `MAIL_TEMPLATES` keys `driver_evaluation_request`, `driver_evaluation_reminder` for consistency — current direct queue works, so optional. |

### 6.4 Anonymity guarantees (must stay true)

- Reports SELECT only `request_id, driver_id, AVG(overall), remarks` — **never** `evaluator_user_id` or `guest_label` for display. Remarks render joined only to driver/trip, never to evaluator.
- `guest_label` is ordinal `"Guest 1"` — never the real `guest_name`.
- Motorpool can see *whether* a passenger responded (response-rate stats `COUNT(submitted_at IS NOT NULL)`) but not which remarks belong to whom.
- No audit log links evaluator identity to remarks.

---

## 7. Cross-cutting work

### 7.1 Migrations

New files `migrations/042,043,044` already exist following existing `.env`-parsing pattern and idempotent inserts. No renumbering needed. Runner note: project `classes/Migration.php:50` globs `*.sql` but these are `*.php` runnable directly — deploy runs `php migrations/042_travel_order_and_overdue.php && php migrations/043_trip_confirmations.php && php migrations/044_driver_evaluations.php` and verifies `SHOW TABLES LIKE 'trip_confirmations'` etc. Do not add duplicate migration via `migrate.php`.

### 7.2 Settings

All new keys are already seeded by migrations — settings page only needs to **expose** them:

| Key | Default | Card |
|---|---|---|
| `require_travel_order_upload` | `0` | Travel Documents |
| `trip_confirmation_enabled` | `1` | Trip Confirmations |
| `trip_confirmation_lead_hours` | `24` | Trip Confirmations |
| `trip_confirmation_same_day_lead_minutes` | `60` | Trip Confirmations |
| `trip_confirmation_window_minutes` | `60` | Trip Confirmations |
| `trip_overdue_renotify_hours` | `24` | Trip Monitoring |
| `driver_evaluation_reminder_hours` | `48` | Evaluations |
| `driver_evaluation_expiry_days` | `30` | Evaluations |

Each uses `trackClamp` bounds and `auditLog('settings_updated')`.

### 7.3 Cron deployment

- Update `setup.sh`: add `*/5 * * * * php /var/www/html/cron/process_trip_confirmations.php >> /var/www/html/logs/cron.log 2>&1` (or `C:\xampp\...` path for Windows Task Scheduler equivalent).
- Document in `README.md` cron section and `health.php` if present.
- Keep existing `*/2 * * * * process_queue.php` and HTTP fallback `?page=cron&action=trips&key=SECRET` (already in `pages/cron/index.php:58`).
- Consider merging reminder job into same 5m cadence — already done.

### 7.4 Router

Already registered in `index.php` — no new routes needed:
- `requests/manage-passengers`, `requests/confirm`, `evaluations/submit`, `evaluations/index`, `reports/driver-rankings`, `reports/export-driver-rankings-csv`, `cron action=trips`.

Adding any new `action` (e.g. `export-driver-rankings-pdf`) would need a new `elseif` in `index.php:338-346,497-505`.

### 7.5 Audit

Every mutation calls `auditLog()`:
- `passenger_added` / `passenger_removed` (new) — `manage-passengers.php`
- `settings_updated` — `settings/index.php` (already)
- `trip_confirmed`, `trip_declined_cancelled`, `trip_reschedule_requested` — `confirm.php`
- `trip_overdue` is in-app only (no audit — optional to add)
- `evaluation_submitted` / `evaluation_reminder_sent` — `evaluations/submit.php` + `process_trip_confirmations.php`

---

## 8. Implementation Phases (dependency order — do not skip)

### Phase 0 — Validate foundations (0.5 day)

1. Run `php -l` on all touched files.
2. Start MySQL (XAMPP Control Panel) and run `php migrations/042_travel_order_and_overdue.php`, `043`, `044` against `DB_DATABASE` from `.env`; verify tables/columns/settings with
   ```sql
   SHOW COLUMNS FROM requests LIKE 'travel_order_%';
   SHOW COLUMNS FROM requests LIKE 'overdue_notified_at';
   SELECT * FROM settings WHERE `key` LIKE 'trip_%' OR `key` LIKE 'driver_evaluation%' OR `key`='require_travel_order_upload';
   SELECT * FROM trip_confirmations LIMIT 1; SELECT * FROM driver_evaluations LIMIT 1;
   ```
3. Smoke-test existing routes: `?page=requests&action=confirm&token=invalid` renders error card; `?page=cron&action=trips&key=SECRET` returns JSON `{sent,...}`.

### Phase 1 — Complete Feature 1 (passenger mgmt) — 1 day

- Fix `pages/requests/manage-passengers.php` (add_guest + remove handlers + full UI) — see §2.2.
- No DB change.
- QA: see §10 checklist.

### Phase 2 — Feature 2 + Settings wiring (TO toggle + trip/evaluation settings) — 0.5 day

- Edit `pages/settings/index.php` to expose all 8 keys in 3 cards (Travel Documents, Trip Confirmations, Trip Monitoring/Evaluations) with clamping.
- Verify copy states "Toggleable by Admin and All Father only" and gate still passes both roles.
- QA: toggle ON → `create.php` blocks submit without file; toggle OFF → submit passes.

### Phase 3 — Wire Features 3 & 4 triggers (confirmation creation + overdue is already live) — 0.5 day

- Add `createTripConfirmation()` call in `pages/approvals/process.php` after `approved` commit (and re-approval path).
- No new UI.
- QA: approve a request → row appears in `trip_confirmations` with correct `scheduled_send_at` (24h vs 60m same-day) and `deadline_at`.

### Phase 4 — Feature 5 hooks + Evaluation pages — 1.5 days

- Add `createDriverEvaluations()` calls in `pages/guard/actions.php:record_arrival` and `pages/requests/complete.php` after commit.
- Create `pages/evaluations/submit.php` (public token form) and `pages/evaluations/index.php` (response-rate dashboard).
- Create `pages/reports/driver-rankings.php` + `export-driver-rankings-csv.php` (Chart.js + CSV).
- Edit `includes/sidebar.php` entries.
- QA: see §10.

### Phase 5 — Polish (badges, routing, cron deploy, patch notes) — 0.5 day

- Overdue badge in `pages/requests/index.php` + `schedule/calendar.php`.
- Optional helper `isTripOverdue()` in `includes/trip-enhancements.php` or `functions.php`.
- Update `setup.sh`, `README.md`, `pages/patch-notes/index.php` entry "v2.7.0 — Trip Enhancements".
- Run `php -l` on every touched file + `php -S` smoke of all routes.

**Total estimate:** ~4 days (1 dev). Phases must run in order; Phase 0 blocks all others.

---

## 9. File Change Matrix (full)

| File | Phase | Type | Lines / Notes |
|---|---|---|---|
| `pages/requests/manage-passengers.php` | 1 | **Complete** | Fill `__MP_POST2__` + `__MP_UI__` — largest change |
| `pages/settings/index.php` | 2,3,5 | Edit | Add 8 inputs + 3 cards + clamp wiring |
| `pages/approvals/process.php` | 3 | Edit | Add `createTripConfirmation()` after approved commit |
| `pages/guard/actions.php` | 4 | Edit | Add `createDriverEvaluations()` after arrival commit |
| `pages/requests/complete.php` | 4 | Edit | Add `createDriverEvaluations()` after manual complete commit |
| `pages/evaluations/submit.php` | 4 | **New** | Public token form, ~250 lines, standalone HTML |
| `pages/evaluations/index.php` | 4 | **New** | Dashboard, ~180 lines |
| `pages/reports/driver-rankings.php` | 4 | **New** | Ranking report + Chart.js, ~250 lines |
| `pages/reports/export-driver-rankings-csv.php` | 4 | **New** | CSV export, ~80 lines |
| `includes/sidebar.php` | 4 | Edit | 2 new entries |
| `pages/requests/index.php` | 5 | Edit | Overdue badge + row highlight |
| `pages/schedule/calendar.php` | 5 | Edit | Overdue event styling |
| `setup.sh` | 5 | Edit | Add 5m cron line |
| `README.md` | 5 | Edit | Cron docs |
| `pages/patch-notes/index.php` | 5 | Edit | v2.7.0 notes |
| `migrations/042-044` | 0 | Keep | Run, not edit |
| `cron/process_trip_confirmations.php` | — | Keep | No edit needed |
| `pages/cron/index.php` | — | Keep | No edit needed |
| `pages/requests/confirm.php` | — | Keep | No edit needed |
| `includes/trip-enhancements.php` | — | Keep | Helpers already complete |

---

## 10. QA Checklist (run before marking done)

### Feature 1
- [ ] Motorpool head assigned to request can add system user passenger on `pending` and `pending_motorpool` — count increments, audit logged, added user gets email+in-app
- [ ] Same on `approved` (undispatched) — works
- [ ] Admin and all_father can do the same (even if not assigned)
- [ ] Add guest name works (guest row visible, no email sent)
- [ ] Duplicate user/guest blocked with clear error
- [ ] Capacity overflow blocked with message listing `passenger_capacity`
- [ ] Driver cannot be added as passenger
- [ ] Remove user passenger: row deleted, count re-synced, audit + `removed_from_request` notify
- [ ] Remove guest: row deleted, no notify, audit logged
- [ ] Dispatched trip (`actual_dispatch_datetime` set) — Manage button hidden, POST returns "can no longer be modified"
- [ ] Non-motorpool requester cannot open `manage-passengers` (redirect 403)

### Feature 2
- [ ] Settings shows "Require Travel Order / OB Slip" toggle, visible to admin and all_father, hidden from others
- [ ] Toggle ON → `pages/requests/create.php` rejects submit without file with message
- [ ] Toggle OFF → submit passes without file
- [ ] Audit log `settings_updated` records the change
- [ ] Uploaded file appears in view page with download link; replaces correctly on edit

### Feature 3
- [ ] Approve a request whose `start_datetime` is tomorrow → `trip_confirmations` row created with `scheduled_send_at = start -24h`, `deadline_at = start -60m`
- [ ] Approve a same-day trip (created today, start today in 3h) → `scheduled_send_at = start -60m`
- [ ] Cron SEND (or `?page=cron&action=trips&key=SECRET`) queues email to requester, marks `sent_at`
- [ ] Email link Proceed → `status='confirmed'`, motorpool notified
- [ ] Email link Don't Proceed → Cancel → request becomes `cancelled`, vehicle/driver released, passengers+driver notified
- [ ] Don't Proceed → Reschedule + note → request becomes `pending_motorpool` with `reschedule_requested=1`, motorpool notified
- [ ] No response by deadline → cron EXPIRE marks `expired`, both parties notified "proceeding"
- [ ] Re-approve a rescheduled request → new `cycle+1` confirmation created
- [ ] Expired/cancelled confirmations cannot be re-used (already-processed card)

### Feature 4
- [ ] Approved trip past `end_datetime` with no arrival → next cron run sets `overdue_notified_at`, motorpool head gets in-app + email `trip_overdue_alert`
- [ ] Renotify fires again after `trip_overdue_renotify_hours` while still overdue
- [ ] Overdue badge shown in requests list + calendar for motorpool/admin/all_father
- [ ] After guard records arrival, badge clears and no further renotify

### Feature 5
- [ ] Complete a trip via guard arrival (and via direct complete) → one `driver_evaluations` row per passenger (users + `Guest N`), driver excluded
- [ ] Each user passenger receives evaluation email with token link
- [ ] Guest rows have no email but shareable token conceptually exists
- [ ] Token page anonymous: star form (1-5) + remarks, validates token, single-use, respects `driver_evaluation_expiry_days`
- [ ] Submit stores `overall = AVG(5)`, `remarks`, `submitted_at`; success card confirms anonymity
- [ ] Reminder cron after `driver_evaluation_reminder_hours` queues one reminder per pending evaluation
- [ ] Reports → Driver Rankings: computed averages sorted best→worst, period + min-evaluations filters, Chart.js bar, CSV export
- [ ] Remarks displayed anonymously (no evaluator identity anywhere in SQL SELECT for display)
- [ ] `isSelfScopedDriverReporter()` — driver sees only own ranking (or no access if restricted)

### Cross-cutting
- [ ] `php -l` passes on all touched files
- [ ] Public routes return 200/302 correctly (unauth redirects to login, token pages work without login)
- [ ] `audit_logs` has entries for all mutations
- [ ] `setup.sh` cron + `README.md` updated; `health.php` still returns healthy

---

## 11. Known Issues to Fix During Build

1. **`driver_evaluations` UNIQUE on NULL:** `uq_request_evaluator (request_id, evaluator_user_id)` with both guest rows having `NULL` → second guest violates uniqueness in MySQL (NULL != NULL actually does NOT violate UNIQUE in InnoDB — MySQL allows multiple NULLs in UNIQUE — so this is safe). If later changed to include `guest_label`, revisit.
2. **Migration runner mismatch:** `classes/Migration.php` expects `*.sql` but new migrations are `*.php` — deploy must run PHP files directly (see Phase 0).
3. **`confirm.php` CSRF:** public POST forms include `csrfField()` but there is no session — `verifyCsrf()` would fail. Current code does not call `requireCsrf()` on public confirm page — keep it that way.
4. **Evaluation idempotency:** re-completing a trip must not duplicate rows — `createDriverEvaluations` already checks `existingUserIds`/`existingGuests`.

---

## 12. Rollout steps (deploy)

1. Pull code, `composer install --no-dev` if needed.
2. Ensure MySQL running, `php migrations/042_travel_order_and_overdue.php && php migrations/043_trip_confirmations.php && php migrations/044_driver_evaluations.php`.
3. Verify settings seeded and `Admin → Settings` shows new cards.
4. Install cron: add `process_trip_confirmations.php` every 5m (or call `?page=cron&action=trips&key=SECRET` via Windows Task Scheduler every 5m).
5. Smoke-test each checklist group on XAMPP with two user accounts (requester + motorpool head) and one guest passenger.
6. Check `logs/cron.log` + Settings → Email Queue for queued/sent counts.

---

## 13. References

- Existing plan doc: `docs/TRIP_ENHANCEMENTS_PLAN.md:1-205`
- Gap analysis: `plan.md` (phases 0-6 prod-loka port, now v2.6.0)
- Key source lines cited inline as `file:line` throughout this document.
