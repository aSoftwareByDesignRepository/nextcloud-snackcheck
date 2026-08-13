# SnackCheck — High Availability notes

> **Audience:** Platform / Ops running Nextcloud with more than one app server.  
> **Related:** Zeus SF-01 / Argus SF-A01 (`docs/ZEUS-ARCHITECTURE-AUDIT.md`).

## What must be shared across nodes

| Concern | Mechanism | Without shared backend |
|---|---|---|
| Unlock sessions (120s) | `ICacheFactory::createDistributed('snackcheck_unlock')` | Unlock on node A invisible on node B; lockout counters split |
| Device / unlock / log rate limits | `createDistributed('snackcheck_rl')` + `ILockingProvider` | Per-node soft limits (under-limit risk) |
| Unlock fail lockout counters | Distributed cache + exclusive lock | Weaker progressive lockout |
| Pepper / license apply locks | `ILockingProvider` | Rare dual-mint / dual-apply races |

**Requirement:** Configure Nextcloud with a **shared distributed cache** (typically **Redis**) when running multiple PHP-FPM / app nodes. Single-node deployments are fine with the default local cache.

## What does *not* need Redis

| Concern | Mechanism |
|---|---|
| Open period uniqueness / createLog serialization | DB `snk_locks.open_period` + period row `FOR UPDATE` |
| Terminal seat capacity (register / trim) | DB `snk_locks.terminal_capacity` `FOR UPDATE` (Zeus MF-03) |
| Ledger idempotency | Unique `idempotency_key` + fingerprints |
| License singleton | `singleton_guard` UNIQUE |

## Ops checklist

1. Confirm `memcache.distributed` (or equivalent) points at Redis (or another shared store) in `config.php` for HA.
2. After Redis outage: expect unlock/rate-limit fail-closed behaviour (429 / unlock unavailable) — money writes still go through DB locks.
3. Seasonal tablets idle > 90 days must re-register (Bearer idle expiry).

## Deutsch (Kurz)

Bei mehreren App-Servern braucht SnackCheck einen **gemeinsamen Distributed Cache (Redis)** für Unlock-Sessions und Rate-Limits. Perioden- und Terminal-Kapazitätssperren laufen über die Datenbank (`snk_locks`) und sind ohne Redis korrekt.
