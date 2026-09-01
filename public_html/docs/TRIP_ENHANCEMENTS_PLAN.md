# LOKA Fleet Management — Trip Enhancements Implementation Plan

**Target version:** 2.7.0
**Status:** Proposed (plan only — no code changes yet)
**Codebase:** PHP 8.2 + MySQL, no framework. Router: `index.php` → `pages/*`. Migrations: `migrations/*.php` (latest: 041). Mail: `classes/EmailQueue.php` + templates in `config/mail.php` (`MAIL_TEMPLATES`) and `config/notifications.php` (`NotificationTemplate`). Cron: `cron/*.php` (CLI) + HTTP fallback `pages/cron/index.php` (secured by `cron_secret` setting). Uploads: `classes/FileUpload.php` (has `createPdfHandler`, `createTripTicketHandler` patterns).

---

## 0. Verified current-state facts (these drive the design)

| Area | Fact |
|---|---|
| Request lifecycle | `STATUS_DRAFT → STATUS_PENDING → STATUS_PENDING_MOTORPOOL → STATUS_APPROVED → STATUS_COMPLETED`, plus `REVISION / REJECTED / CANCELLED / MODIFIED` (`config/constants.php`) |
| "On routing" | Requests still inside the approval pipeline: `pending` (department routing) and `pending_motorpool` (motorpool routing step in `approval_workflow`) |
| Passengers | Table `request_passengers` (`request_id`, `user_id` nullable, `guest_name`); `requests.passenger_count` denormalized counter |
| Trip windows | `requests.start_datetime`, `end_datetime`, `actual_dispatch_datetime`, `actual_arrival_datetime` (set by guard dispatch/arrival) |
| Email | Queue-only pattern: app **queues** (with optional `$scheduledAt`), `cron/process_queue.php` sends every 2 min. `queueTemplate($toEmail,$templateKey,$data,$toName,$priority,$requestId)` |
| In-app notifications | `notify()`, `notifyPassengers()`, `notifyDriver()`, `notifyRoleUsers()` helpers in `includes/functions.php` |
| Audit | `auditLog($action,$entityType,$entityId,$old,$new)` → `audit_logs` (JSON old/new values) |
| Settings | `settings` key–value table; admin UI in `pages/settings/index.php`; all_father has `canAccessSystemControl()` |
| Roles | `motorpool_head` (4), `admin` (5), `all_father` (99) — `isMotorpool()`, `isAdmin()`, `isAllFather()` |
| Secrets/tokens | Existing pattern for signed public URLs: `gasVoucherVerifySecret()/VerifyHash()` — reuse for confirmation & evaluation tokens |
| Cron install | `setup.sh` writes crontab (currently only `process_queue.php`); HTTP cron actions `email|sms|care` in `pages/cron/index.php` |

---

## 1. Feature 1 — Motorpool Head adds/deletes passengers on APPROVED and ON-ROUTING requests

### Scope / rules
- Allowed when `status IN ('pending','pending_motorpool','approved')` **and** `actual_dispatch_datetime IS NULL` (guard has not dispatched the vehicle yet).
- Who: `motorpool_head` assigned to the request (or any motorpool head if none assigned — mirrors `complete.php` authorization logic), plus `admin` / `all_father`.
- Add supports **system users** (autocomplete from `getEmployees()`) and **guest names** (same as requester flow: `normalizeRequestPassengerIdsFromPost()`).
- Constraints enforced server-side:
  - No duplicate passenger (same user_id or same guest_name on the request).
  - `COUNT(passengers) <= vehicle_types.passenger_capacity` of the assigned vehicle (if vehicle assigned).
  - Driver is never listed as passenger.
  - Always re-sync `requests.passenger_count = COUNT(request_passengers)`.
- Everything inside a DB transaction + `auditLog('passengers_updated','request',…,old,new)`.

### Changes
| Type | File | What |
|---|---|---|
| New | `pages/requests/manage-passengers.php` | GET shows current passengers + add form; POST performs add/remove ops (CSRF, `requireAnyRole([ROLE_MOTORPOOL, ROLE_ADMIN, ROLE_ALL_FATHER])`) |
| Edit | `index.php` | `case 'requests':` add `elseif ($action === 'manage-passengers')` |
| Edit | `pages/requests/view.php` | "Manage Passengers" button (visible per rules above) opening a modal or linking to the page; show change history line from audit log |
| Edit | `pages/approvals/view.php` | Same button for requests being reviewed (on-routing) |
| Reuse | `includes/functions.php` | `normalizeRequestPassengerIdsFromPost()`, `countRequestPassengers()` |
| Reuse | templates already exist | `added_to_request` / `removed_from_request` mail keys + `notifyPassengers()` — notify added/removed users automatically |

### Edge cases
- Removing a passenger who is a **guest** (no user row) → remove row + audit only.
- Trip already dispatched (`actual_dispatch_datetime` set) → UI hides button; POST returns error.
- Concurrent edits → wrap in transaction, re-check capacity inside it.

---

## 3. Feature 3 — Confirmatory pre-trip email ("GRAB-style" Proceed / Don't Proceed)

### Behavior spec
- Every **approved** request gets one confirmatory email to the **request creator**:
  - **Trip on a later day:** email sent **1 day (24 h) before `start_datetime`**.
  - **Trip same calendar day as request creation:** email sent **N hours/minutes before `start_datetime`** (configurable).
- The email contains two links (secure single-use token, no login required):
  **"Proceed"** → confirms the trip (motorpool head gets an in-app + queued email note).
  **"Don't Proceed"** → landing page offers two actions:
    1. **Cancel trip** → `status = 'cancelled'` (motorpool head notified; vehicle/driver freed if not yet dispatched — same release logic as `cancel.php`).
    2. **Request reschedule** → status returns to `pending_motorpool` flagged `reschedule_requested = 1` + requester's note; motorpool head must approve again (same approval path as today); once re-approved with a new `start_datetime`, a **new confirmation cycle** starts automatically.
- **Default when no response:** the trip **proceeds**. Deadline = `start_datetime − response_window` (default 60 min). On deadline, the pending confirmation is marked `expired` ("no response — proceeding") and the motorpool head is notified.

### Settings (new keys)
| Key | Default | Meaning |
|---|---|---|
| `trip_confirmation_enabled` | `1` | Master switch |
| `trip_confirmation_lead_hours` | `24` | Lead time for multi-day-ahead trips |
| `trip_confirmation_same_day_lead_minutes` | `60` | Lead time when trip is same-day as creation |
| `trip_confirmation_window_minutes` | `60` | Response window before start (min 15) |

### Schema (Migration 043)
```sql
CREATE TABLE trip_confirmations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,          -- sha256 of raw token (raw token only ever emailed)
  cycle TINYINT UNSIGNED NOT NULL DEFAULT 1,    -- increments after each reschedule re-approval
  status ENUM('pending','confirmed','declined_cancel','declined_reschedule','expired','cancelled') NOT NULL DEFAULT 'pending',
  scheduled_send_at DATETIME NULL,              -- when the email should go out
  sent_at DATETIME NULL,
  deadline_at DATETIME NOT NULL,
  responded_at DATETIME NULL,
  reschedule_note VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_request_cycle (request_id, cycle),
  KEY idx_status_send (status, scheduled_send_at),
  KEY idx_deadline (status, deadline_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Cron — new `cron/process_trip_confirmations.php` (every 5 min)
1. **Send:** `status='pending' AND sent_at IS NULL AND scheduled_send_at <= NOW()` → queue email via `EmailQueue::queue()` (raw token links), mark `sent_at`.
2. **Expire:** `status='pending' AND deadline_at < NOW()` → `status='expired'` (default = proceed), notify motorpool head ("No response — trip proceeding").
3. **Overdue check (Feature 4) runs in the same file.**
- Also wired as HTTP cron action `trips` in `pages/cron/index.php` (`/?page=cron&action=trips&key=SECRET`), and added to `setup.sh` crontab.
- Guard conditions for creating a confirmation row: `status='approved'`, `end_datetime` in future, `actual_dispatch_datetime IS NULL`, `trip_confirmation_enabled=1`. Created on approval (hook in `pages/approvals/process.php` where the approval finalizes) and re-created on reschedule re-approval with `cycle+1`.

### New pages
| File | Route | Purpose |
|---|---|---|
| `pages/requests/confirm.php` | `?page=requests&action=confirm&token=…` | Public token landing: trip summary + Proceed / Don't Proceed; on "Don't Proceed" → Cancel or Request Reschedule (+ note). `hash_equals()` token check; single-use |

### New email templates (add to `MAIL_TEMPLATES` in `config/mail.php`)
- `trip_confirmation` — "Will you proceed with your trip?" + Proceed / Don't-Proceed buttons.
- `trip_confirmation_response` — to motorpool head on each response/expiry.
- `trip_cancelled_by_requester`, `trip_reschedule_requested` — outcomes.

---

## 4. Feature 4 — Overdue-trip alerts to the Motorpool Head

- **Definition of overdue:** `status='approved'` (trip ongoing) AND `actual_arrival_datetime IS NULL` AND `NOW() > end_datetime`.
- **Schema (Migration 042, shared):** `ALTER TABLE requests ADD COLUMN overdue_notified_at DATETIME NULL;`
- Runs inside `cron/process_trip_confirmations.php`:
  - On first detection → `notifyRoleUsers([ROLE_MOTORPOOL], 'trip_overdue', …)` (in-app) + queued email to motorpool head users ("Request #ID exceeded its designated end — {end_datetime}"). Set `overdue_notified_at = NOW()` (dedupe).
  - Optional re-reminder every `trip_overdue_renotify_hours` (default 24) while still overdue.
- **UI:** red "Overdue" badge on `pages/requests/index.php` rows and the schedule calendar for motorpool/admin/all_father; new filter chip "Overdue trips". Cleared automatically when the guard records arrival (`actual_arrival_datetime` set) or the trip is completed/cancelled.
- Template added: `trip_overdue_alert`.

---

## 5. Feature 5 — Post-trip anonymous driver evaluation (GRAB-like)

### Flow
1. When a trip is marked **completed** (`pages/requests/complete.php`, after the existing commit), the system creates one evaluation invitation per **passenger** (system users **and** guests, excluding the driver) and queues an email per passenger with a **secure single-use token link** (no login needed — guests have no accounts). "Completed — evaluate your driver" also lands in each passenger's in-app notifications via the existing `notifyPassengers()`.
2. Passenger opens the link → star-rating form (Bootstrap icon stars) + free-text **remarks/suggestions**.
3. Submission stores ratings; the raw token is burned; identity is **never rendered anywhere** — reports show "Anonymous passenger".

### Schema (Migration 044)
```sql
CREATE TABLE driver_evaluations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  driver_id INT UNSIGNED NOT NULL,
  evaluator_user_id INT UNSIGNED NULL,          -- NULL for guests
  guest_label VARCHAR(50) NULL,                 -- only 'Guest 1', never a name
  token_hash CHAR(64) NOT NULL UNIQUE,
  rating_punctuality  TINYINT UNSIGNED NULL,    -- 1..5
  rating_safety       TINYINT UNSIGNED NULL,
  rating_courtesy     TINYINT UNSIGNED NULL,
  rating_driving      TINYINT UNSIGNED NULL,
  rating_vehicle      TINYINT UNSIGNED NULL,
  overall DECIMAL(3,2) NULL,                    -- computed avg on submit
  remarks TEXT NULL,
  submitted_at DATETIME NULL,
  reminder_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_request_evaluator (request_id, evaluator_user_id, token_hash),
  KEY idx_driver (driver_id), KEY idx_request (request_id),
  KEY idx_pending (submitted_at, reminder_sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Components
| Type | File | What |
|---|---|---|
| New | `pages/evaluations/submit.php` | Public token page: star form + remarks; validates token, single-use, expires 30 days after trip end |
| New | `pages/evaluations/index.php` | Motorpool/admin dashboard: response rate per trip, per-driver averages (identities hidden) |
| New | `pages/reports/driver-rankings.php` | **Ranking report**: computed average `overall` per driver (+ per-criterion averages, evaluation count, period filter, min-evaluations threshold), sorted best→worst, Chart.js bar of averages; export CSV via `pages/reports/export-driver-rankings-csv.php` |
| Edit | `pages/requests/complete.php` | After commit: create evaluation rows + queue emails (new helper `createDriverEvaluations($requestId)` in `includes/functions.php`) |
| Edit | `includes/sidebar.php` | "Driver Rankings" entry under Reports (gated by `requireReportsAccess()` roles) |
| Cron | `cron/process_trip_confirmations.php` | Reminder pass: email passengers whose `submitted_at IS NULL AND reminder_sent_at IS NULL AND created_at < NOW() - 48h` (once each) |
| Templates | `config/mail.php` | `driver_evaluation_request`, `driver_evaluation_reminder` |

### Anonymity guarantees
- Reports and dashboards join only on `request_id`/`driver_id`; evaluator identity columns are never selected for display; remarks render without attribution.
- `guest_label` is a generic ordinal ("Guest 1"), never the actual `guest_name`.
- Motorpool can see *whether* a passenger responded (response-rate stats) but not *which* remarks belong to whom.

---

## 6. Cross-cutting work

1. **Migrations** (new files following the existing `.env`-parsing pattern, runnable via `php migrate.php`):
   - `042_travel_order_and_overdue.php` — travel-order columns + `overdue_notified_at` + settings defaults.
   - `043_trip_confirmations.php` — `trip_confirmations` table + settings defaults.
   - `044_driver_evaluations.php` — `driver_evaluations` table + settings defaults.
2. **Cron deployment:** update `setup.sh` (add `*/5 * * * * … process_trip_confirmations.php`) + `README.md` cron section + `pages/cron/index.php` `trips` action (HTTP fallback uses the existing `cron_secret`).
3. **Router:** register new actions in `index.php` (`manage-passengers`, `confirm`, `evaluations`, `driver-rankings`).
4. **Audit:** every mutation (passenger change, enforcement toggle, confirmation response, reschedule request) calls `auditLog()`.
5. **Patch notes:** add entry to `pages/patch-notes/index.php` (project convention).
6. **Testing:**
   - Unit tests (PHPUnit is in `vendor/`) for: confirmation send/expire scheduler math (same-day vs next-day lead), ranking computation, passenger capacity validation.
   - Manual QA on XAMPP. ⚠️ **Note:** the local MySQL service is currently **not running** on this dev machine (port 3306 refused; DB `old_loka_db` per `.env`) — start MySQL from the XAMPP Control Panel before testing.
   - Test emails: verify via Settings → Email Queue (`?page=settings&action=email-queue`); delivery is queue-only by design.
7. **Rollout order:** migrations → settings/toggles (Travel Order enforcement default **OFF**; confirmations + overdue alerts default **ON**) → cron installed → UI pages → patch notes.

### Assumptions (please confirm before implementation)
- "On routing" = `pending` + `pending_motorpool` (requests still moving through the approval pipeline).
- Guests have no email on file → guest passengers receive evaluations via a shareable token link the requester can forward (or are included in the requester's link). System-user passengers get individual emails.
- Reschedule re-approval reuses only the motorpool approval step (vehicle/driver may change), not the full department→motorpool chain.
- Confirmation emails go to the request **creator** only (not every passenger).





