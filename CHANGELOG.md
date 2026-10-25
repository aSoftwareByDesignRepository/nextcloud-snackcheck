# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.4 - 2026-10-25

### Fixed

- nb/sv lexicon bleed — annullér→annuller, storna→annullera, reviewed allowlists
- armor snk-btn variant paint on <button> against NC core repaint
- media-boundary ownership, table wrap, free-item plural, screenshot-matrix
- dark-theme accent-ink token + e2e user-state reset

### Changed

- baseline-green guard — kill-test must pass unmutated
- canonical en/de regen (free-item plural + tablet seats) — shared-pack resync leftover
- assert intro substitutes %s, never literal (shared pack re-sync)
- normalize tarball modes + host-side signature audit (GH #47)
- shared e2e instance-lock harness
- Atlas farm v3.5.15: snackcheck r2 seal — dual critic ACCEPT
- add UpgradeBackupStateGuard test support; sync contract tests + l10n catalogs
- fleet sync — drop deprecated clip: rect(), eslint-clean app-feedback regex

## 1.1.3 - 2026-10-10

### Changed

- shared app-feedback render (focus-ring + toast-dedup SSOT)
- Atlas farm v3.5.14: l10n corruption sweep + aria-invalid error border + spec fixes

## 1.1.2 - 2026-10-04

### Fixed

- Dialogs now close on Escape even when another Nextcloud app suppresses the native dialog cancel event (central fix covering all app dialogs).
- Modal focus restoration hardened; form errors marked aria-invalid on reject paths.
- Localization fixes.

### Changed

- Atlas v3.5.14 verification pass: expanded contract coverage; store screenshot URLs corrected.

- Regenerate `l10n/*.js` without CSP-breaking `pluralForm` third args (shared regenerator).

### Fixed

- “Close anyway?” mistranslations (ES/DA/NL/SV/PT-BR/PL/FR) that rendered as “near/close” instead of dismiss.
- Device API `vendorPublicKeyB64` now follows `VendorPublicKey::publicKeyB64()` (lab TEST key when configured).

## 1.1.0 - 2026-09-04

### Changed
- Nextcloud **35** support (`max-version` 35). Symfony Console 7–ready command signatures verified.

## 1.0.9 - 2026-08-27

### Added

- Optional catalog item pictures (JPEG/PNG/WebP, AppData-backed) on Log tiles and Catalog.
- Log browse: category groups, instant find, category filter chips, and category icons.
- App Store: seven 1920×1040 screenshots, `SECURITY.md`, release `Makefile`, and `info.xml` listing URLs (`organization` + `tools`).

### Changed

- Log: Colleague/Company fields sit above the snack grid (quantity stays under More options).
- Integration tests deactivate temporary catalog items so kitchens stay tidy.
- Filter / mode panels aligned with Check-family recipes (filter panel, quick pills, embedded mode).

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.8 - 2026-08-27

### Removed

- Drop unused `snk_unlock_tokens` table (unlock sessions remain cache-only; Absolute No-Go dual-SoT hygiene).

## 1.0.7 - 2026-08-13

### Changed

- Packaging release: version bump for ready4upload / production archive (local install already at 1.0.6).

## 1.0.6 - 2026-08-13

### Changed

- Align `appinfo/version` with `info.xml` and prepare first ready4upload production archive.
