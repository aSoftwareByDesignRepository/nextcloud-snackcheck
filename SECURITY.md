# Security Policy

## Supported versions

We support the latest published SnackCheck release on currently supported Nextcloud majors listed in `appinfo/info.xml`.

## Reporting a vulnerability

Please email **info@software-by-design.de** (or use GitHub Security Advisories on this repository) with a description and steps to reproduce. Do not open a public GitHub issue for security reports.

We aim to acknowledge reports within a few business days.

## Operator notes

- **Payroll exports** (CSV/XLSX/PDF) leave the Nextcloud instance under admin/manager control. Treat downloads as HR-sensitive PII and protect the browser session and storage accordingly.
- **Proxy / hospitality logging** always records a short reason and the acting user. Reasons are visible to kitchen managers and in audit views — do not put secrets in the reason field.
- **Kitchen Tablet** (`SNK2`) device licences gate the companion only. The web app is never licence-gated. Device pairing tokens are bearer secrets — revoke lost tablets immediately in Settings.
- Unlock PIN / QR lockout uses Nextcloud distributed cache + locking. On more than one app node you **must** share Redis (or equivalent) for cache and file locking; otherwise lockouts and unlock sessions split per node.
- Never set `SNK_ALLOW_VENDOR_KEY_OVERRIDE=1` in production. That flag exists only for tests / emergency key rotation drills.
