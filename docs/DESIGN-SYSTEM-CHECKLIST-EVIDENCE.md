# SnackCheck — design-system checklist evidence (MS-W0)

Primary flows reviewed against `planning/design-system/checklist.md` with prefix `snk`.

## Theme & colour

- [x] Feature CSS uses `--snk-*` derived from Nextcloud `--color-*` (hex only as `var()` fallbacks)
- [x] Surfaces/borders/tints mix into `--color-main-background` (not raw brand hex)
- [x] Warn/ok callouts use `--snk-tint-warning` / `--snk-tint-info` + left border ink (not colour alone)
- [x] Dialog scrim uses `--snk-scrim` (theme-aware), not raw black
- [x] Dark theme danger CTA fill override for AA (`data-theme-dark`)
- [x] Spot-check intended for light/dark/high-contrast via NC theme variables + `prefers-contrast: more`

## Layout & spacing

- [x] Spacing scale `--snk-space-1`…`8` (4–64 px)
- [x] Radii `sm` / `md` / `lg` / pill
- [x] Shared chrome: skip links (content + navigation), polite + assertive live regions, `#app-navigation` sidebar with brand + role badge + grouped Me/Kitchen/Money/Admin links (`aria-current="page"`), page header (breadcrumb, 56px icon well, h1, lead), site scope strip, `#app-content` `lang`/`data-snk-locale`, `#app-content-wrapper.snk-shell` full-width, `<main tabindex="-1">`, single `#snk-toast`, mobile nav toggle (<1024px)
- [x] Reading measure on leads (`max-width: 60ch`); page shell is full width (not 72rem constrained)
- [x] Sections visually separated; cards only where interactive grouping needs them
- [x] Responsive: collapse at **768 px** (DS breakpoint-md); single-column tiles ≤480 px; safe-area insets
- [x] Tables card-stack on catalog/hospitality/sites/audit/users/mymonth ≤768 px (no page horizontal scroll)

## Components & access

- [x] `.snk-btn` / primary / secondary / danger / ghost; controls ≥ 44 px; primary/danger page actions 48 px
- [x] Native selects / inputs with focus rings; selects use `appearance: none` + chevron room
- [x] Settings On/Off use `.snk-switch-field` (`role="switch"`) with hidden `0` + checkbox `1`
- [x] Hospitality sync is **checked-only** (`!!en.checked`) — never `en.value === '1'` (unchecked checkbox still has value `1`)
- [x] Settings FormData last-wins (`fd.forEach`); multi-site confirm never uses native `HTMLFormElement.submit()`; per-dialog focus restore via `WeakMap`
- [x] Directory pickers are UI-only — APIs/services reject ghost UIDs (settings allowlists, site managers, proxy target, hospitality company, unlock PIN/QR)
- [x] Checkboxes `.snk-check` ≥ 44 px row hit target + `accent-color`
- [x] Native `<dialog class="snk-dialog">` with `dialog:not([open]) { display: none !important }` (NC core override)
- [x] Destructive confirms use `.snk-btn--danger`; cancels use `--secondary`
- [x] Create **and** edit catalog dialogs: name/price primary; extras under closed **More options**
- [x] All data tables wrapped in `.snk-table-wrap`
- [x] Empty states use titled dashed well + optional CTA
- [x] **No raw UID typing** — directory search + chip targets on proxy, unlock, access, hospitality, site managers
- [x] People search is WAI-ARIA **combobox** (`role=combobox` / `listbox` / `option`, Arrow/Enter/Escape, `aria-activedescendant`, inflight race guard) — portfolio: `planning/design-system/DESIGN-SYSTEM.md` §3.6.7 + `ACCESS-AND-DIRECTORY-PICKERS.md` §5
- [x] Settings/sites/unlock search use `scope=directory` (listed-mode chicken-egg fixed); Log/Users proxy stay `scope=access` — ACCESS §4
- [x] Form submits use FormData last-wins + `data-snk-busy` double-submit guard — DESIGN-SYSTEM §3.6.6 / §3.18 + ACCESS §6
- [x] **Removable chips** for multi-select allowlists (DESIGN-SYSTEM §3.13 / ACCESS §1) — `.snk-chip-list` + ≥44px remove; hidden committed ids via `templates/parts/snk-chip-field.php`
- [x] Settings are `/settings/{section}` pages with in-page nav chips
- [x] Period reopen / close-warning use native `<dialog>` with focus restore
- [x] Pulse category filter (All / Drinks / Snacks / Alcohol / Other)
- [x] Log tiles show category + diet tags
- [x] Period closed callout + disabled tiles; Open next period CTA
- [x] Toast + polite `#snk-live-region` + assertive `#snk-alert-region` (errors via `toast(..., true)`)
- [x] Badges use pill + leading status dot (not colour alone)
- [x] Callouts use 5 px accent border (DS §3.4)
- [x] App icon: white-stroke 24×24 fridge (`img/app.svg`) + black `app-dark.svg` (Check-family invert contract)
- [x] Free lines show Free/Gratis (not €0,00) in My month
- [x] Hospitality Save disabled until company + allowlist complete
- [x] DE/EN l10n coverage for primary template + toast strings
- [x] Settings Periods / Sites sections link to the live ops pages
- [x] Shopping CSV / hospitality CSV respect shell site scope
- [x] Weekly top-up digests scoped per site manager (no cross-site stock leak)
- [x] Personal digest skip-€0 setting (AC-OPP-B4, default ON)
- [x] Catalog edit + par/onHand + copy-to-site UI (US-003 / AC-OPP-Y10)
- [x] About links DEVICE-SHORTLIST via `public/docs` (WP-HW1 / MS-P1); support/partner macros stay in private `docs/`
- [x] Empty states with CTA on Log / Pulse / Catalog
- [x] Tables use `scope="col"`
- [x] UX simplify: Pulse one-click Restock; catalog Edit+Restock primary; restock dialog (no `prompt`)
- [x] UX simplify: Log period-closed admin CTA; colleague/company details label
- [x] UX simplify: settings nav lean + labeled; chip picker requires field focus
- [x] UX simplify: kiosk PIN-first lock; scan disclosure; tile selection feedback; hold-for-qty hint

## Accessibility

- [x] `:focus-visible` outline on tiles/links/buttons
- [x] Skip link → `#snk-main-content` (primary fill + on-fill text on focus)
- [x] Live regions updated for async success/error
- [x] `lang` + locale attributes on `#app-content`
- [x] `prefers-reduced-motion` disables transitions
- [x] Free badge text + price Free/Gratis (not colour-only)
- [x] Automated WCAG contrast proof: `tests/Unit/A11y/WcagContrastFallbacksTest.php` + `ThemeAndResponsiveContractTest.php` + kiosk `contrast.test.ts`
- [x] Table horizontal scroll wrapper / card-stack ≤768px; ≥44/48px touch; focus outline-offset 2px
- [x] Core flow UX contracts: `tests/Unit/Ux/CoreFlowUxContractTest.php` + kiosk `uxJourney.test.ts`
- [x] **Browser axe / Playwright against live Nextcloud shell** — `e2e/a11y-smoke.spec.js`, `e2e/theme-responsive-a11y.spec.js`, `e2e/ux-journeys.spec.js` (`npm run e2e:a11y`)

## Primary flows covered

Log · My month · Pulse · Catalog · Periods · Users/totals · BR report · Settings (access/benefits/privacy/pulse/digests/periods/sites/unlock/license/support) · Hospitality · Sites · Audit · Kitchen Tablet companion screens

## Scope notes / accepted deviations

- Full `css/common/` token split still deferred (tokens live in `css/app.css`); Lucide fridge geometry matches `img/app.svg` via `IconCatalog`.
- Shell stays full-width (no 72rem constrained default). AZC parity: soft `--snk-bg-soft`, flex shell stack, 3px focus, `snk-page-stack`, card header/body, empty-state icon wells, compact nav 240→280.
- Pulse category filters are chip-style, not full `*-filter-panel` chrome (thinner than TicketCheck).

Automated proof: PHPUnit unit+integration, custom mutation MSI 100% (UX-30, ghost-UID, combobox/directory-scope, removable chips), Jest kiosk+licensing, WCAG contrast unit tests, `DirectoryPickerComboboxContractTest`, `SearchUsersDirectoryScopeTest`, Playwright+axe live-shell suite (`npm run e2e:a11y`).
Stop-ship contracts: NN-01 on_hand unchanged, NN-13 device createLog session attribution, UX-30 hospitality Save lock (checked-only), ghost UID rejection, directory search scope, removable chips, companion Undo = 60s window.

Portfolio SoT (2026-08-10): `planning/check-productivity-suite/ACCESS-AND-DIRECTORY-PICKERS.md`, `SETTINGS-PAGES-STANDARD.md`, `planning/design-system/DESIGN-SYSTEM.md` (§3.6.6–§3.6.7, §3.13, §3.18), `checklist.md`, `style-guide.html`.
