# SnackCheck — Hinweise zur Hochverfügbarkeit

> **Zielgruppe:** Plattform / Ops mit mehr als einem Nextcloud-App-Server.  
> **Bezug:** Zeus SF-01 / Argus SF-A01.

## Was über Knoten geteilt werden muss

| Thema | Mechanismus | Ohne Shared Cache |
|---|---|---|
| Unlock-Sessions (120s) | `createDistributed('snackcheck_unlock')` | Session auf Knoten A unsichtbar auf B |
| Rate-Limits | Distributed Cache + `ILockingProvider` | Limits nur pro Knoten |
| Unlock-Lockout | Cache + Exclusive Lock | Schwächerer Schutz |
| Pepper / License-Apply | `ILockingProvider` | Seltene Doppel-Races |

**Pflicht bei HA:** Shared Distributed Cache (typisch **Redis**) in der Nextcloud-`config.php`.

## Was ohne Redis korrekt bleibt

| Thema | Mechanismus |
|---|---|
| Offene Periode / createLog | `snk_locks.open_period` + `FOR UPDATE` |
| Terminal-Kapazität | `snk_locks.terminal_capacity` (Zeus MF-03) |
| Ledger-Idempotenz | Unique `idempotency_key` |
| License-Singleton | `singleton_guard` UNIQUE |

Einzelknoten-Installationen brauchen nichts Extra. Details auf Englisch: `Admin-HA.en.md`.
