# LOKA Fleet Management System — Drivers' Briefing Overview

**Audience:** DICT Region 2 drivers · **Companion deck:** `LOKA_Drivers_Briefing.pptx` (15 slides, speaker notes included)
**App:** `lokafleet.dictr2.cloud` — one login per person, works on phone and computer.

---

## Big picture

LOKA Fleet replaces the paper trail for four daily processes — **Vehicle Requests, OB Pass Slips, Gas Vouchers, and Trip Records** — with one digital system. Every approval, gate stamp, and rating is logged under the account that did it. Nothing gets lost, and every step notifies the right people.

**How alerts reach you (4 channels):**
1. **In-app bell** — instant, with badge counts on the sidebar.
2. **Email** — full details, threaded by control number (Vehicle = blue, Gas Voucher = orange, OB = teal).
3. **SMS** — short text to your registered mobile.
4. **Telegram** — push to your phone app, after a one-time connect (see last section).

Alerts are queued and delivered within about 2 minutes of the event — they never block the app.

**Events a driver receives:** "You Have Been Requested as Driver", "You Have Been Assigned as Driver", "Trip Started", "Trip Completed", plus any change or cancellation of your trips.

> ⚠️ "Requested as driver" is **not** a confirmed trip — wait for the **assignment** alert before preparing the vehicle.

---

## 1) Vehicle Request (trip request → dispatch → completion)

**The workflow:**

```
Request filed → Pending (dept head approval) → Motorpool review
  → Approved → Requester confirms by email → Guard: dispatch + odometer
  → On the road → Guard: arrival stamp → Trip completed
```

- The **requester** files online (dates, destinations, passengers, preferred driver).
- **Department head** approves first, then **motorpool** assigns the vehicle and driver — the system warns them of schedule conflicts before assigning.
- The requester gets a **confirmation email** (Proceed / Don't Proceed) before the trip is final.
- **The guard records dispatch and arrival** with odometer readings at the gate. Dispatch flips the vehicle to *In Use* and the driver to *On Trip*; arrival completes the trip and frees both instantly.

**Booking rules enforced by the app:** file within the advance booking window, respect the minimum lead time, and trips cap at **72 hours** (longer trips are split).

### Walkthrough — the driver's part
1. **Assignment alert arrives** — bell, email, SMS (and Telegram once connected).
2. **Open My Trips** (sidebar, driver-only) — your schedule: upcoming trips, dates, destinations, passengers, assigned vehicle.
3. **At the gate:** the guard stamps dispatch + odometer start.
4. **Drive the trip** — stick to the approved destination and schedule; changes go through motorpool.
5. **Return:** the guard stamps arrival + odometer — trip flips to *Completed*.
6. **Get rated** — riders answer an anonymous evaluation (cleanliness, behavior, appearance, safety).

---

## 2) OB Pass Slip (Official Business gate pass + Certificate of Appearance)

**The workflow:**

```
Apply (4 short sections) → Supervisor approves
  → Motorpool approves * → Guard: DEPART stamp → Guard: ARRIVE stamp
  → Client confirms CoA → Requester finalizes → Completed
```

\* *Official DICT vehicle only — a private-vehicle slip skips Motorpool entirely.*

- The form is guided into **4 sections**: ① Trip details (purpose + date) ② Vehicle (official plate or private) ③ Approvers (supervisor always; motorpool head for official vehicles) ④ Participants (optional companions).
- Approvals are **one-click buttons** with comments when returned for revision; Cancel is final.
- **Guard stamps DEPART and ARRIVE** with their logged-in identity and timestamp — no more paper logbook.
- The office you visited confirms the visit through a **one-time link**: they tick a proof-of-service box and leave their name plus a mobile number and/or official email. Motorpool spot-checks a few contacts monthly.
- Every slip is numbered and printable, and its **QR code** opens a public verify page proving it is genuine.
- **Bound to a trip?** If the slip is tied to a vehicle request, the guard's dispatch/arrival stamps the slip automatically — one event, both records.

### Walkthrough — the driver's part
When driving for someone's OB: the slip, the gate stamps, and the client confirmation form **one connected record**. The employee prints the approved slip; you both pass the gate where the guard stamps DEPART/ARRIVE; the visited office confirms online afterwards. Nothing to carry, nothing to lose.

---

## 3) Gas Voucher (fuel authorization with 3 approval gates + QR)

**The workflow:**

```
File (draft or submit) → GATE 1: Motorpool review
  → GATE 2: Budget Officer certifies funds
  → GATE 3: Chief Admin & Finance approves
  → Approved — print the QR voucher → Fuel up at an accredited station
  → Payment tracked: unpaid → paid
```

- The voucher states the **vehicle, fuel type, liters, and accredited station** (chosen from the official list).
- Any gate can **reject with a reason** — fix and file again.
- Only after Gate 3 can the voucher be printed; it carries **four signatory lines** and a **QR code**.
- **At the pump:** station staff scan the QR — a public page (no login) proves the voucher is approved and genuine. This is the anti-fake check.
- Finance tracks payment until the station is paid — no mystery balances.

### Walkthrough — the driver's part
1. **File the voucher** — vehicle, fuel, liters, station; save as draft or submit.
2. **Track the 3 gates** — status moves Motorpool → Budget → final approval.
3. **Print once approved** — with the QR.
4. **Present at the pump** — station scans to verify.
5. **Payment status** — visible until paid.

Drivers with gas-voucher access can file for fuel they purchased; the driver name field suggests names from the driver roster.

---

## 4) Trip Records (trip ticket + driver trip extract)

### The trip ticket — one trip's official record
```
Trip completed → My Trips → Create ticket → Pre-filled — check, save, print
```
- Created by **the driver** right after arrival (admins can assist); the dashboard nags until a completed trip has its ticket.
- Pre-filled from the actual trip: control no., plate, route, passengers, dispatch/arrival times, odometer start and end. Check, save, print — no handwriting.

### The driver trip extract — all your trips in a period
```
Reports → Driver Trip Extract → Pick From / To → Every trip + ratings → Export CSV / PDF
```
- As a driver you **automatically see only your own trips**.
- **Summary on top:** trips driven, evaluations received, your fair rank score.
- **One row per trip:** date, control no., destination, overall rating, and the 4 rating categories.
- Column toggles (plate, comments, etc.) — the screen is the export; CSV for encoding, PDF for printing.
- Ratings are **anonymous** — comments always print as "Anonymous passenger". Trips with no ratings show a dash. Cancelled trips never appear.

---

## 5) Telegram Integration (opt-in push alerts)

**Why:** your assignment ping reaches your phone even away from a desk. Same alerts as the bell/inbox, tagged per process (`[Vehicle]` blue, `[OB]` teal, `[Gas Voucher]` orange). Private and opt-in: no link = no messages, nothing is broadcast. (Viber was dropped — commercial paywall; Telegram is the supported channel.)

### Walkthrough — one-time connect (~2 minutes)
1. **Install Telegram** — free app, register with your mobile number.
2. **Log in to LOKA Fleet** → click your name → **Profile** → **Messenger Alerts** card.
3. Click **Connect** next to Telegram — the system shows a one-time link and a short code.
4. **Open the link** (`https://t.me/<bot>?start=<code>`) — Telegram opens with the LOKA Fleet bot; press **START** (or send the `/start <code>` to the bot).
5. Profile shows a green **Connected** badge — you're done; alerts flow automatically.
6. **Anytime: Disconnect** in the same card (or an admin can link your chat ID for you).

> The link is **one-time and expires after 30 minutes** — if it lapses, click Connect again for a fresh one. Alerts arrive within ~2 minutes of each event, queued like email/SMS — never blocking the app.

---

## Workshop exercises (one per topic, ~15 minutes each)

Hands-on blocks placed after each topic in the deck. Format: **Scenario → Your tasks (3) → You're done when (checkpoint)**. Run them solo or in pairs; one phone/laptop per pair; practice account on the projector for those without a login. Facilitator tips are in each slide's speaker notes.

### Workshop 1 — Vehicle Request: "read your own schedule"
**Scenario:** Motorpool just assigned you to drive to Bayombong tomorrow, 8:00 AM. Nobody has called you — everything is in the app.
1. Open **My Trips → All Trips** and find the Bayombong trip.
2. Read the trip row — say the date, time, destination, vehicle and plate out loud.
3. Tap **Export PDF** — your trips list opens ready to print.

**Checkpoint:** you can state tomorrow's schedule — time, destination, vehicle — without asking anyone.

### Workshop 2 — OB Pass Slip: "encode a paper pass slip"
**Scenario:** You're handed yesterday's paper pass slip: employee J. Cruz, Santiago City Hall meeting, this Friday, official DICT vehicle, one companion.
1. **Apply**, Steps 1–2: encode purpose and Friday's date; pick **Official DICT vehicle**.
2. Steps 3–4: choose the supervisor, add the companion, check the **"How this prints"** preview.
3. Open an approved sample slip — find the guard **DEPART** stamp and the **QR**.

**Checkpoint:** you can name the 4 sections and who approves — official = supervisor + motorpool, private = supervisor only.

*Facilitator: bring 2–3 real old paper slips for the encode drill; one live submission is enough.*

### Workshop 3 — Gas Voucher: "file it, then prove it"
**Scenario:** The patrol vehicle needs 20 liters of diesel before a long trip; the station is on the accredited list.
1. **Gas Vouchers → New**: vehicle, diesel, 20 liters, station → **Save as draft**.
2. Open the draft → **Submit** — watch the status become *Pending Review*.
3. Open the approved sample voucher on screen and **scan its QR with your phone**.

**Checkpoint:** you can explain why a pending voucher cannot fuel a trip, and what the QR proves at the pump.

### Workshop 4 — Trip Records: "ticket it, then extract it"
**Scenario:** You've just returned from a completed trip; the office needs the record today.
1. **My Trips** → a completed trip → **Create Ticket**; check times and odometer.
2. Save, then view the ticket — control no., route, passengers all pre-filled.
3. **Reports → Driver Trip Extract** → set this month → **Export PDF**.

**Checkpoint:** you created a ticket and exported your own extract — no logbook, no handwriting.

### Workshop 5 — Telegram: "connect before you leave"
**Scenario:** Your next assignment could come while you're on the road. Set up the alert channel now, not later.
1. Install **Telegram** (Play Store / App Store); register your mobile number.
2. **Profile → Messenger Alerts → Connect** → open the one-time link.
3. Press **START** in the LOKA Fleet bot — your profile shows **Connected**.

**Checkpoint:** your profile shows a green Connected badge — the next assignment will ring on your phone.

*Facilitator: this is the take-home action of the whole briefing — leave real time for it. Links expire after 30 minutes; stuck participants can be linked by an admin instead.*

## 6) Experimental features (what's next)

Four new tools are built and QA'd (Plans #38–41, branch `plans-38-41-experimental`). Each ships **switched OFF** behind a System Control flag until the region signs off — production only after that. Today's briefing is awareness only.

| Feature | Who it's for | What it does |
|---|---|---|
| **GPS Trip Tracking** (#41) | drivers + motorpool | Tracks active fleet trips from the **driver's phone** — auto-starts at guard dispatch, auto-stops at arrival. One-time consent box; pings ~every 45 s; page must stay open (installable PWA, no app store). Only **Motorpool / Admin / All Father** ever see the live position and trail; points auto-delete after 30 days. No third-party map tiles (coordinates never leave the server). |
| **AI Assistant** (#40) | everyone (role-scoped) | In-app chat bubble: ask *“What is the status of my current trip?”* — it runs a **visible trace line** for every lookup, answers, and links to the record (“Open in LOKA”). 9 tools, 8 read-only; the only write creates a care item an approver must still approve. It **cannot click buttons** — approvals stay human. Writes require Confirm; per-user rate limits; every call audited. |
| **Vehicle Repair History** (#38) | motorpool | Per-plate repair records with dated events, parts and labor line items and cost roll-up — matching the old Excel workbooks, imported one-time from `Reference/Repair History/`. Completed repair tickets and care jobs write history automatically; maintenance reminders now escalate (due-day + overdue daily, no cap) to a wider audience. |
| **Deeper Rollback View** (#39) | admins | Rolling a request back now shows/uses the **full workflow stage map**, with side effects (guard stamps, vehicle/driver holds) reversed explicitly and the trail kept. |

**Driver takeaways:** GPS tracking and the AI assistant are the two you will actually touch — both opt-in/flag-gated, and you'll get a live walkthrough when the region switches them on.

> Workflow note: the three workflow diagrams in the deck now show the **exact status tokens** the app displays (`pending`, `pending_motorpool`, `approved`, `dispatched`, `completed`, `pending_supervisor` → `coa_received`, `draft` → `pending_budget` → `pending_approval`, etc.) so drivers can match the slides to what they see on screen.

## House rules (closing slide)
1. **File early** — booking windows and lead times are enforced automatically.
2. **Mind the gate** — departure/arrival stamps make the official record; no stamp, no completed trip. Report a missed stamp to motorpool immediately.
3. **Odometer honest** — readings are checked against vehicle history.
4. **Ticket right away** — create the trip ticket while details are fresh.
5. **Your login = your name** — every action is logged under your account; never share it.
6. **Connect Telegram** — so the next assignment finds you on your phone.

---
*Prepared from the implemented workflows (Plans #2–#37 in `Plan.md`). Note: the revised OB flow (button approvals, CoA acknowledgment + QR) is deployed to staging; production still shows the earlier e-signature variant until rollout is signed off.*
