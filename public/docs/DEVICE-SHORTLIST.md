# SnackCheck kitchen tablet shortlist (WP-HW1)

**Stand:** 2026-08-10 · Recommend-only — we do not RMA hardware.

## DE (P0)

| Model class | Notes | Kiosk | Approx. street |
|-------------|-------|-------|----------------|
| 10″ Android tablet (Wi‑Fi), Android 10+ | Prefer models with wall-mount kits on Amazon.de / MediaMarkt | Lock-task / dedicated device preferred | €120–€250 |
| Industrial wall tablet 8–10″ | Look for “kiosk tablet” / PoE variants from B2B (e.g. Advantech-class) | Often ships with kiosk firmware | €300–€700 |
| Refurbished business tablet + VESA mount | Cost-effective LOI path | Requires MDM / pin-to-home | €80–€180 |

## AT / CH (P1)

Same class as DE; verify local retail/VAT and power plugs (CH Type J). Prefer devices already used for AZC Terminal / Desk Room Display at the customer.

## NFC (optional NH)

Only if LOI asks — USB/CCID readers or on-device NFC. PIN/QR remain primary unlock in V1.

## Explicit non-goals

Fire tablets without managed lockdown; devices we warranty; card readers (DG-P2).

## Kiosk lockdown (WP-K5 / MS-P1)

Recommend customers harden the tablet before go-live (we do not RMA or MDM-manage devices):

1. **Dedicated device / lock-task** — Android enterprise lock-task or OEM kiosk mode so SnackCheck Kitchen is the only foreground app.
2. **No personal Google account** — avoid Play Store browsing; sideload or managed Play only.
3. **Screen always-on when plugged** — keep awake while charging; wall power preferred.
4. **Disable notification shade / status bar** where OEM allows; PIN/QR remain primary unlock (NFC optional).
5. **Rotate device token** on staff change; revoke lost tablets in Settings → Terminals immediately.

DE/EN ops: same steps; document the chosen MDM/OEM profile in the customer runbook.
