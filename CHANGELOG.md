# Changelog

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
