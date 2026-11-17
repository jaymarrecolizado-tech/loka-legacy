---
name: Institutional Authority
colors:
  surface: '#f9f9ff'
  surface-dim: '#d1daef'
  surface-bright: '#f9f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f0f3ff'
  surface-container: '#e7eeff'
  surface-container-high: '#dfe8fd'
  surface-container-highest: '#dae3f7'
  on-surface: '#131c2a'
  on-surface-variant: '#44474d'
  inverse-surface: '#283140'
  inverse-on-surface: '#ecf1ff'
  outline: '#75777e'
  outline-variant: '#c4c6ce'
  surface-tint: '#4d5f7d'
  primary: '#000615'
  on-primary: '#ffffff'
  primary-container: '#0b1f3a'
  on-primary-container: '#7587a7'
  inverse-primary: '#b5c7ea'
  secondary: '#3d5f94'
  on-secondary: '#ffffff'
  secondary-container: '#a1c2fd'
  on-secondary-container: '#2c4f83'
  tertiary: '#170001'
  on-tertiary: '#ffffff'
  tertiary-container: '#470009'
  on-tertiary-container: '#fa3c4c'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d6e3ff'
  primary-fixed-dim: '#b5c7ea'
  on-primary-fixed: '#071c36'
  on-primary-fixed-variant: '#364764'
  secondary-fixed: '#d6e3ff'
  secondary-fixed-dim: '#a9c7ff'
  on-secondary-fixed: '#001b3d'
  on-secondary-fixed-variant: '#22477a'
  tertiary-fixed: '#ffdad8'
  tertiary-fixed-dim: '#ffb3b1'
  on-tertiary-fixed: '#410007'
  on-tertiary-fixed-variant: '#92001c'
  background: '#f9f9ff'
  on-background: '#131c2a'
  surface-variant: '#dae3f7'
typography:
  headline-xl:
    fontFamily: Public Sans
    fontSize: 40px
    fontWeight: '800'
    lineHeight: 48px
    letterSpacing: -0.02em
  headline-xl-mobile:
    fontFamily: Public Sans
    fontSize: 30px
    fontWeight: '800'
    lineHeight: 36px
    letterSpacing: -0.01em
  headline-lg:
    fontFamily: Public Sans
    fontSize: 32px
    fontWeight: '700'
    lineHeight: 40px
    letterSpacing: -0.015em
  headline-lg-mobile:
    fontFamily: Public Sans
    fontSize: 24px
    fontWeight: '700'
    lineHeight: 32px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Public Sans
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  headline-sm:
    fontFamily: Public Sans
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  title-lg:
    fontFamily: Public Sans
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  title-md:
    fontFamily: Public Sans
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
  body-lg:
    fontFamily: Public Sans
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Public Sans
    fontSize: 15px
    fontWeight: '400'
    lineHeight: 22px
  body-sm:
    fontFamily: Public Sans
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-lg:
    fontFamily: IBM Plex Sans
    fontSize: 14px
    fontWeight: '600'
    lineHeight: 20px
    letterSpacing: 0.02em
  label-md:
    fontFamily: IBM Plex Sans
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.04em
  label-sm:
    fontFamily: IBM Plex Sans
    fontSize: 10px
    fontWeight: '700'
    lineHeight: 14px
    letterSpacing: 0.06em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 1.5rem
  gutter-sm: 1rem
  gutter-lg: 2rem
  margin: 2rem
  margin-sm: 1rem
  margin-lg: 3rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

This design system embodies official civic gravitas, institutional trust, and bureaucratic clarity. Designed for Philippine government departments, national portals, and statutory commissions, the visual atmosphere channels the dignity of executive documentation, state gazettes, and legal administrative desks.

The aesthetic philosophy rejects ephemeral web trends—no ambient diffusion, no transluscent glassmorphism, and no rounded pill containers. Instead, it relies on crisp structural grids, rigorous typographical hierarchies, and physical paper-record metaphors. Every screen communicates sovereign integrity, statutory reliability, and non-negotiable accessibility for all citizens.

Visual tone attributes:
- **Authoritative & Legitimate:** Every element feels codified, ratified, and durable.
- **Utilitarian & Precise:** High data density, crisp contrast, and functional spatial distribution.
- **Document Desk Sensibility:** Surfaces emulate official vellum, parchment, and archival folders with tactile, hairline-ruled boundaries.

## Colors

The palette is restricted to sovereign state tones, designed for maximum optical legibility and statutory formality.

- **Primary (`#0B1F3A` - Deep Sovereign Navy):** Used for mastheads, primary headers, authoritative typography, and sovereign insignias.
- **Secondary (`#123A6D` - Action Navy):** Applied strictly to interactive controls, primary buttons, actionable links, and focused operational states.
- **Tertiary (`#C8102E` - Signal Red):** Reserved for urgent state notifications, executive decrees, destructive actions, and active statutory alerts. Used with disciplined restraint.
- **Neutral Dark (`#1A2332` - Archival Body):** Primary body text color delivering optimal reading contrast against paper surfaces.
- **Muted Neutral (`#5C6B7A` - Slate Caption):** Applied to metadata, field captions, form hints, and archival timestamps.
- **Border / Rule Line (`#D6DCE5` - Hairline Rule):** Strict 1px hairline boundary color for dividing tables, bounding panels, and field outlines.
- **Paper Canvas (`#F7F8FA` - Administrative Ground):** Base desk canvas tone providing contrast for floating document sheets.
- **Surface Pure (`#FFFFFF` - Crisp Parchment):** High-priority work surfaces, form fields, cards, and data ledgers.

## Typography

Typographic scale is structured around **Public Sans** for executive display, gazette sectioning, and long-form legal prose, while **IBM Plex Sans** is deployed for dense systemic data labels, table headings, reference codes, and status trackers.

Rules for application:
- **Uppercase Tracking:** Section eyebrows and institutional indices (`label-sm`, `label-md`) must render in full uppercase with tracking (`letter-spacing: 0.04em - 0.06em`).
- **Tabular Numerics:** All currency figures (Philippine Peso ₱), reference numbers, dates, and citizen registry counters must use tabular figures (`font-variant-numeric: tabular-nums`).
- **Strict Hierarchy:** Sectional titles require definitive top and bottom borders or clear dimensional contrast rather than relying solely on large font variations.

## Layout & Spacing

The layout model operates as a rigid 12-column administrative ledger designed to maximize scanning efficiency across dense municipal documents and application registers.

- **Desktop (1200px+):** 12-column grid, `margin-lg` (3rem), `gutter-lg` (2rem). Content max-width is pinned to 1280px to prevent excessive line length in long-form legal texts.
- **Tablet (768px - 1199px):** 8-column grid, `margin` (2rem), `gutter` (1.5rem). Secondary side rails collapse into top action modules or tabbed shelves.
- **Mobile (Under 768px):** 4-column grid, `margin-sm` (1rem), `gutter-sm` (1rem). Dense list views replace multidimensional tables; horizontal scrolls are explicitly prohibited except within bounded data-matrix tables.

Spacing follows an uncompromising 4px/8px vertical rhythm. White space is deliberate and organized like official stationery margins, avoiding unnecessary decorative gaps.

## Elevation & Depth

This system avoids decorative blur effects, colored drop shadows, and soft ambient glows. Visual hierarchy and elevation are constructed purely through tonal layering, crisp borders, and subtle structural offsets.

- **Level 0 (Desk Canvas):** `#F7F8FA`. The base layer representing the physical desk.
- **Level 1 (Paper Sheet / Document Card):** `#FFFFFF` surface bounded by a continuous 1px solid hairline border (`#D6DCE5`).
- **Level 2 (Active Pane / Floating Docket):** `#FFFFFF` surface, 1px solid `#D6DCE5` border, paired with an offset structural shadow: `0 1px 3px rgba(11, 31, 58, 0.08), 0 4px 12px rgba(11, 31, 58, 0.04)`.
- **Level 3 (Modal Ledger / Statutory Dialog):** `#FFFFFF` surface, 1px solid `#0B1F3A` border, paired with `0 8px 24px rgba(11, 31, 58, 0.16)`. No backdrop blur on overlays; modal backdrops use flat `#0B1F3A` at 65% opacity.

## Shapes

The shape system enforces restraint. All UI components use sharp or near-sharp geometries to preserve institutional formality.

- **Corner Radii:** Standard components (inputs, cards, banners, buttons) carry a maximum radius of `4px` (`0.25rem`). Micro-elements such as checkboxes and step tags use `2px` (`0.125rem`).
- **Prohibitions:** Pill badges, circular avatar buttons, and rounded-full containers are strictly forbidden. All contextual status chips, tags, and markers must remain rectangular with crisp 2px to 4px corners.
- **Document Notch (Optional Motif):** Archival panels and official memo containers may utilize a sharp 45-degree folded corner or a recessed hairline corner bracket to signify codified documentation.

## Components

### Buttons
- **Primary Action:** Solid Action Navy (`#123A6D`), white text, 4px border radius, 1px solid `#0B1F3A`. Hover: Deep Sovereign Navy (`#0B1F3A`). Active: Pressed inset offset.
- **Secondary (Statutory Document):** White background, `#0B1F3A` text, 1px solid `#D6DCE5` border. Hover: `#F7F8FA` background with `#0B1F3A` border.
- **Urgent / Destructive:** Solid Signal Red (`#C8102E`), white text, 4px radius. Hover: `#A50D25`.
- **Dimensions:** High-density 36px and 44px heights; no oversized rounded buttons.

### Chips & Administrative Tags
- Rectangular badge, 2px corner radius. Uppercase `label-sm` font.
- **Pending/Review:** Pale gray background `#EDEFF2`, `#1A2332` text, 1px solid `#D6DCE5`.
- **Ratified/Approved:** Navy tint background `#E8EEF5`, `#123A6D` text, 1px solid `#123A6D`.
- **Executive Notice/Urgent:** Red tint background `#FDF2F4`, `#C8102E` text, 1px solid `#C8102E`.

### Form Fields & Inputs
- **Text Inputs & Selects:** 40px height, `#FFFFFF` background, 1px hairline border (`#D6DCE5`), 4px border radius. Text in `#1A2332`.
- **Focus State:** 2px solid `#123A6D` border without soft ambient outer glow rings. Focus indicator is sharp and immediate.
- **Labels:** Positioned strictly above fields in `IBM Plex Sans` (`label-md`), accompanied by mandatory indicator asterisks in `#C8102E`.

### Checkboxes & Radios
- **Checkboxes:** 18px square, 2px radius, 1.5px solid `#0B1F3A` border. Checked: Solid `#0B1F3A` fill with white checkmark.
- **Radios:** 18px circle, 1.5px solid `#0B1F3A` border. Checked: White center with `#0B1F3A` solid bullseye dot.

### Lists & Data Ledgers
- Row items separated by 1px solid `#D6DCE5` dividing hairlines.
- Zebra striping uses alternating `#FFFFFF` and `#F7F8FA`.
- Table header row: Flat `#F7F8FA` background, bottom border 2px solid `#0B1F3A`, column labels set in uppercase `label-md` (`#5C6B7A`).

### Document Cards & Panels
- Pure white `#FFFFFF` surface with continuous 1px `#D6DCE5` outline.
- Header region partitioned by a horizontal 1px hairline rule.
- Optional 3px solid `#0B1F3A` or `#C8102E` accent line running flush along the top boundary for immediate document prioritization.

### Official Gazette Masthead (Domain Specific)
- Sovereign Navy (`#0B1F3A`) band featuring the official Republic seals, institutional department logotype, Republic flag bar (subtle 2px horizontal rule in `#C8102E`), and current Philippine Standard Time (PST) ticker.