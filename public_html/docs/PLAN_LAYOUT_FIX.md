# LOKA Layout & Positioning Fix — Plan v2.7.2

**Target:** Industry-standard, slick, balanced layout across all pages.
**Stack:** Bootstrap 5.3 + custom `assets/css/style.css` + `includes/header.php:1` / `sidebar.php:1` / `footer.php:1`.
**Audit date:** 2026-09-02 — sampled 17 pages + global CSS (70 cited findings). Full audit in task `ses_fa2e829d5ffetFPYw8GlRHJwYF`.

---

## 1. Principles (what “industry standard” means here)

| Area | Standard |
|---|---|
| Grid | 12-col Bootstrap, `g-3 (1rem)` / `g-4 (1.5rem)` only — no mixed `g-3`/`g-4` per page `style.css:51-516` |
| Spacing scale | 4/8/16/24px (`p-2/p-3/mb-4`) — never `10px`/`45px` inline `header.php:62` / `create.php:512` |
| Buttons | Primary CTA `btn-primary 38px desktop / 44px mobile` — `btn-sm (30px)` only for icon groups, `btn-lg (48px)` only for confirm/danger modals. No `btn-primary` on `bg-primary` navbar `header.php:48` |
| Cards | Header `1rem 1.25rem`, body `1rem` desktop / `0.75rem 1rem` mobile, radius `8px`, shadow `0 2px 8px rgba(0,0,0,.08)` — not `table-card` vs `stat-card` vs `shadow-sm` drift `guard/index.php:112` |
| Tables | `table-responsive` scroll, never hide columns `style.css:733`, `text-nowrap` on dates, `vertical-align:middle`, header `0.875rem uppercase #6c757d` |
| Breakpoints | Bootstrap 576/768/992/1200 only — remove 575/768 custom splits `style.css:452-768` |
| Touch | 44px min tap target `style.css:455` everywhere, not only `<768` |
| Motion | `transform 0.2s` / `translateY(-2px)` + `shadow` — not `5px` lift `reports/index.php:140` |
| A11y | WCAG 2.1 AA contrast, `44px` touch, `focus-ring`, `role` on card-links `dashboard/index.php:262` |

---

## 2. Audit Summary (severity)

- **Awkward (12):** mobile content under navbar `style.css:24`, sidebar `280px` jump `style.css:426`, columns hidden at 575 `style.css:733` (Requests 9 cols → 3, `requests/index.php:198`), guard `Time` 72px double-line `guard/index.php:237`, view header 5 buttons wrap no `gap` `view.php:115`, vehicle select truncate `create.php:555`, tabs scrollbar `guard/index.php:184`, badge wrap row height `requests/index.php:231`, chart 50px drop `dashboard/index.php:270`, modal double scroll `create.php:833`, filter gaps `requests/index.php:153` `10/12` empty.
- **Inconsistent (38):** `g-3` vs `g-4`, 5 breakpoints, 36 inline `style=` (`create.php:512 853`, `schedule.php:358`, `header.php:62`), `h5` vs `h6` headers `driver-rankings.php:96`, `p-0` vs `p-3` around tables `evaluations/index.php:119`, 3 pagination patterns, `btn sm/md/lg` mixing, filter col spans all differ `vehicles 11/12` vs `drivers 9/12` vs `rankings 12/12`, breadcrumb missing on dashboard `dashboard/index.php:221`, badge `bg-warning` vs `bg-light` for same label `approvals/index.php:240`, alert `m-3` vs `mb-4` `header.php:146`.
- **Minor (20):** icon `24px/10px` vs `gap`, help text baseline `settings/index.php:124`, `py-5` double pad `reports/index.php:35`, `gap` vs `me-2` drift `evaluations/index.php:95`.

Full list with `file:line` in task output — this plan distills to 6 fix tracks.

---

## 3. Fix Tracks (priority order)

### Track A — Global Foundation (0.5d)
| File | Change |
|---|---|
| `assets/css/style.css:7,24,59,73,152,410` | Lock layout: single `navbar-height 56px`, `sidebar 250px` (no 280 jump), wrapper `padding-top:56px` only, main `padding 1.5rem` uniform, remove `max-width:991.98` width override, keep `transform` for mobile. |
| `style.css:452-768` | Collapse 5 breakpoints → 576/768/992/1200. Remove `733-746` hide-cols rule (critical). |
| `style.css:170,198,255,359,516,553` | Normalize spacing: `card-header 1rem 1.25rem`, `card-body 1rem`, `g-4` for page sections, `g-3` for form rows only. |
| `includes/header.php:48,62,67,78,80` | Navbar: `btn-outline-light` for toggle, search `mw-480` class (remove inline `max-width`), focus ring on `input-group`, badge `translate-middle` safe on `375px`, unify `ms-*`. |
| `includes/sidebar.php:106,127,142` | Nav link `gap:0.5rem` + `i width 20px`, `nav-header 1rem 1.25rem 0.5rem` even, badge `ms-auto` with `min-width 20px` truncate `99+`. |
| `style.css:6-67` | `z-index` stack: navbar `1030` < sidebar `1035` < overlay `1040` < palette `1055/1060` < toast `1100`. |
| `includes/header.php:29,includes/footer.php:24` | Standardize palette/search `max-width 560px` single var. |

### Track B — Inline → Utility (0.5d)
Replace 36 inline `style=` with classes/vars `style.css:*-new`:
- `header.php:62 69` `max-width:480px` → `.mw-nav-search {max-width:480px}`
- `create.php:512` `min-width:45px` → `min-w-45`
- `create.php:853 912` `max-height:300/250px overflow-auto` → `.max-h-300 .overflow-auto`
- `schedule.php:358` `width:14%` ×7 → `col` equal
- `evaluations/submit.php:115` `max-width:700px` → `container-sm mw-700`
- `reports/driver-rankings.php:113` `background:#cd7f32` → `.bg-bronze`
- `view.php:920` `font-size:4rem` → `fs-1` + utility

### Track C — Buttons & Actions (0.3d)
- Global `btn` scale: `btn-primary` (CTA, 38/44px), `btn-outline-* btn-sm` (icon groups 32/36px), `btn-lg` only for `modal-footer` confirm `view.php:760 854`. Remove `style.css:455` media-only `44px` → apply globally.
- Fix `view.php:115` header `d-flex flex-wrap gap-2` (copy `evaluations/index.php:95` pattern) for 5 buttons, add `me-*` cleanup.
- Fix `vehicles/index.php:143` `style="display:inline;"` → `d-inline`, `btn-group` radius.
- Add `title` to `maintenance/index.php:244` actions, add view btn to drivers `drivers/index.php:112`.

### Track D — Tables & Pagination (0.3d)
- Ensure every table has `table-responsive` + never hidden cols. Remove `style.css:733`.
- Standardize `card-body p-3` around `table-responsive` (remove `p-0` outliers `evaluations/index.php:119` / `dashboard:536`).
- Unify pagination to single helper `includes/list_pagination.php` + DataTables `dom/lengthMenu` where used — remove manual `?p=` vs `?p_pending` split `approvals/index.php:162 321`.
- Add `text-nowrap` to date/time cols `dashboard/index.php:530`, `guard/index.php:237`, `requests/index.php:216`.
- Center numeric cols `vehicles/index.php:122` `Capacity/Mileage` with `text-center`/`text-end`.

### Track E — Filter Bars (0.2d)
Unify all 7 filter forms to single 12-col pattern (best is `rankings.php:78` `3+3+2+2+2=12`):
- `vehicles 3+2+3+3=11 → 3+3+2+2+2=12` (add search col, keep type/status)
- `drivers 3+3+3=9 → 3+3+4+2=12`
- `requests 3+4+3=10 → 3+4+3+2=12` (add `Overdue` chip)
- `evaluations 3+3+3=9 → 3+3+3+3=12`
- Share partial `includes/filter_bar.php` to prevent drift.

### Track F — Page Polish (0.5d)
- `dashboard/index.php:221` add breadcrumb `Dashboard` like others; fix stat `bg-opacity-10` contrast → `bg-primary bg-opacity-10` + `text-primary` icon wrapper `48px`.
- `requests/view.php:206 284 342 405 482 760 854` fix mileage `g-4` → `g-3` with responsive `col-md-6` → `col-12 col-md-4` at `768`, travel docs `align-items-center`, modals both `modal-lg centered`.
- `settings/index.php:99 225` even help text `min-height 20px`, badge `d-inline-block` not wrap, CTA `btn-lg`.
- `reports/index.php:140` hover `translateY(-2px)` to match `stat-card`, icon `fs-1` not `3.5rem` inline.
- `security/rate-limits.php:150` `container-fluid py-4` (was `px-4`), `g-3` uniform, `card-header` `p-3` like others.
- `evaluations/index.php:95 107` keep `gap-2` pattern — propagate to `view.php:115`.

---

## 4. Implementation Phases

| Phase | Scope | Files | QA |
|---|---|---|---|
| **0 — Audit lock** | Freeze `style.css` vars, document `spacing/button` scale in `docs/STYLE_GUIDE.md` (new 1-pager). `php -l` + visual baseline screenshots (desktop 1440, tablet 768, mobile 375). | `style.css`, `header.php`, `sidebar.php` | Screenshots before/after |
| **1 — Foundation** | Track A + B (global CSS + inline cleanup). Single commit, `APP_VERSION 2.7.2`. | `style.css`, `header.php:62 69 37`, `footer.php:24`, `schedule.php:358`, `create.php:512 853` etc | No `style=` remains (grep 0), no hide-cols, sidebar 250 fixed |
| **2 — Shared components** | Track C + D + E (buttons, tables, filters). Introduce `includes/filter_bar.php` if needed, unify `list_pagination`. | `view.php:115`, `vehicles/index.php`, `drivers/index.php`, `requests/index.php`, `evaluations/index.php`, `guard/index.php`, `approvals/index.php` | Filter bars all `12/12`, tables scroll not hide, buttons `44px` |
| **3 — Page polish** | Track F page-by-page, lighthouse + WCAG check. | `dashboard`, `view`, `settings`, `reports`, `security/*`, `evaluations`, `driver-rankings` | Lighthouse perf ≥90, no `h5/h6` drift, no `py-5` outliers |
| **4 — Final sweep** | Remove `Chart.js 4.4.0` duplicate `driver-rankings.php:139`, consolidate `h5 1.25rem` scale, run `php -l` + visual diff on every route in `index.php:151`. | `driver-rankings.php`, `header.php:25`, `style.css` | No duplicate chart, no console errors, mobile `44px` everywhere |

Estimate: **~2.5d** for 1 dev (0.5+0.5+0.3+0.3+0.2+0.5). Phases must run in order — Phase 1 blocks 2/3.

---

## 5. File Change Matrix

| Phase | Files |
|---|---|
| 1 | `assets/css/style.css`, `includes/header.php`, `includes/sidebar.php`, `includes/footer.php`, `pages/schedule/calendar.php`, `pages/requests/create.php`, `pages/evaluations/submit.php`, `pages/reports/driver-rankings.php`, `pages/requests/view.php` |
| 2 | `pages/vehicles/index.php`, `pages/drivers/index.php`, `pages/requests/index.php`, `pages/requests/view.php`, `pages/approvals/index.php`, `pages/guard/index.php`, `pages/maintenance/index.php`, `pages/evaluations/index.php`, `pages/reports/driver-rankings.php`, `includes/list_pagination.php` |
| 3 | `pages/dashboard/index.php`, `pages/settings/index.php`, `pages/reports/index.php`, `pages/security/rate-limits.php`, `pages/security/sms.php`, `pages/evaluations/index.php` |
| 4 | `pages/reports/driver-rankings.php`, `assets/css/style.css`, `docs/STYLE_GUIDE.md` (new) |

---

## 6. QA Checklist (must pass before merge)

- [ ] `grep -r "style=\""` `public_html/pages` returns 0 (except `chart` canvas)
- [ ] No `display:none` on `th:nth-child(n+4)` at 575 — tables scroll horizontally on iPhone SE
- [ ] All filter bars sum to `12/12` at `lg` (inspect `rankings` perfect, others match)
- [ ] Buttons: primary CTA `38px→44px` smooth, icon groups `32→36px`, modal confirm `48px` — no `30px` desktop violation measured in DevTools
- [ ] Cards: header `16px 20px` + body `16px` uniform, `p-0` only where `table-responsive` has `rounded overflow-hidden`
- [ ] Sidebar `250px` fixed both desktop/mobile, `transform` only, no `width` jump
- [ ] Breadcrumb present on every page except `login`/`guard` dispatch modals
- [ ] `php -l` clean on all touched files, `APP_VERSION` bumped, patch notes `2.7.2` added
- [ ] Lighthouse (desktop) Performance ≥90, Accessibility ≥95, no WCAG contrast failures on `bg-opacity-10` icons
- [ ] Visual: screenshots at 1440/768/375 for `dashboard`, `requests`, `view`, `vehicles`, `approvals`, `settings`, `reports`, `guard` — no wrapping badge jump, no tab scrollbar at 375, no double scroll in `create.php` modal

---

## 7. Rollout

1. Phase 0 screenshots → Phase 1 commit `layout-foundation` → Phase 2 `layout-components` → Phase 3 `layout-polish` → Phase 4 `layout-final`. Each phase ends with `php -l` + manual route smoke (302 auth, 200 public) before next.
2. Update `pages/patch-notes/index.php` `2.7.2` entry “Layout: industry-standard spacing, no awkward button sizing, balanced slick cards/tables/filters”.
3. No DB migration.

---

## 8. References

- Audit source: `includes/header.php:48-80`, `sidebar.php:73-290`, `style.css:6-768`, `vehicles/index.php:73`, `drivers/index.php:9`, `requests/index.php:153-231`, `guard/index.php:112-237`, `reports/index.php:35-140`, `evaluations/index.php:95-185`, `driver-rankings.php:60-139`, `security/rate-limits.php:150-249`.
