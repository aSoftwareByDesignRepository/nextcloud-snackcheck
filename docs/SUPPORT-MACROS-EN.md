# SnackCheck support macros (EN)

Ops pasteables for LOI / first-line support. Keep short; never paste secrets.

## License 402 on kitchen tablet

SnackCheck web stays free. The wall tablet needs an SNK2 device licence with `terminalDevices ≥ 1`.

1. Settings → License → paste SNK2 key → Apply  
2. Register the tablet (pick Site when multi-site is on) → copy `snkterm_…` once  
3. On the tablet: server URL + token → Pair  

If the tablet still shows Licence required: renew/expand `terminalDevices`, re-Apply (trims over-cap), revoke stolen devices.

## Offline “Pending sync”

Pending is honest: the tap is not on the ledger until the server returns 2xx.

1. Confirm Wi‑Fi / server reachability  
2. Tap Retry sync  
3. If unlock expired: Unlock again (same person) — queue retries  
4. Only Discard blocked taps for true conflicts  

## Export / payroll mismatch

Payroll is cent-reconciled personal lines only (hospitality and Free excluded).

1. Period must be **closed** before Hand to HR  
2. Reconcile must be OK (no download if not)  
3. Site filter on payroll/hospitality exports when multi-site is on  
4. Reopen only with reason ≥ 3 chars (clears Handed to HR)

## Unlock lockout

3 wrong PIN/QR attempts → soft lockout starting at **30s**, then **60s → 5m → 15m** on repeat trips without a successful unlock. Wait for the countdown (tablet shows remaining seconds). A correct unlock resets escalation. Admins reset PIN/QR under Settings → Unlock.
