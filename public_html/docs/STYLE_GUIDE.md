# LOKA Style Guide — Industry Standard

**Version:** 2.7.2
**Framework:** Bootstrap 5.3

## Spacing Scale (8px system)
- `p-2 (0.5rem 8px)` tight inside badges
- `p-3 (1rem 16px)` card body, filter bar
- `mb-4 (1.5rem 24px)` page sections, card stack
- `g-3 (1rem)` form rows, `g-4 (1.5rem)` page sections
- Never use `10px`/`45px` inline — use `.min-w-*` / `.mw-*` utilities

## Breakpoints (only)
576 / 768 / 992 / 1200 — no 575/768 custom

## Buttons
- Primary CTA: `btn-primary` (38px desktop → 44px mobile), `fw-500`
- Icon groups: `btn-outline-* btn-sm` (32px → 36px mobile)
- Confirm/danger modals only: `btn-lg` (48px)
- Navbar toggle: `btn-outline-light` on `bg-primary`

## Cards
- Header: `1rem 1.25rem`, `h5 mb-0` or `h6 mb-0` (choose one, use `h5` for page, `h6` for sub)
- Body: `1rem` (tables use `p-3` wrapper, `p-0` only with `rounded overflow-hidden`)
- Radius `10px`, shadow `0 2px 8px rgba(0,0,0,.06)` — unified via `.stat-card` / `.table-card`

## Tables
- Wrap with `table-responsive` (scroll, never hide cols)
- `thead th: 0.875rem uppercase #6c757d, border-bottom 1px`
- `td: vertical-align middle`, `text-nowrap` on dates
- Numeric cols: `text-center` or `text-end`

## Filters
- Unified 12-col pattern: `3+3+2+2+2` or `3+3+3+3` — always sum 12
- `align-items-end`, `form-control`/`form-select` 38px → 44px mobile

## Sidebar
- Width `250px` both desktop/mobile, `gap:0.625rem` for links, `i 20px` centered, `nav-header 1rem 1.25rem 0.5rem`

## Motion
- Hover `translateY(-2px) + shadow` only

## Z-Index
- Navbar 1030 < overlay 1035 < sidebar 1040 < search palette 1055/1060 < toast 1100

## Utilities to replace inline styles
- `.mw-nav-search 480px`, `.mw-palette 560px`, `.min-w-45 45px`, `.max-h-300/250`, `.mw-700 700px`, `.bg-bronze`
