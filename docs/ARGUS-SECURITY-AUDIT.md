# Argus Security Audit — SnackCheck

> **Security Mode: FULL AUDIT**  
> **Object:** Implemented system `nextcloud/apps/snackcheck` (v1.0.6)  
> **Date:** 2026-08-10 (re-audit after CSRF token + theme UX + shelf/export hardening)  
> **Auditor:** Argus (Chief Security Auditor)  
> **Environment:** Docker Compose service `nextcloud` on `:8081`; host PHPUnit + mutation harness for app tree  
> **Prior audits:** Zeus (`docs/ZEUS-ARCHITECTURE-AUDIT.md`); prior Argus MF-A01/A02/A03

---

## Table of contents

1. [Multi-Perspective Security Stakeholder Analysis](#1-multi-perspective-security-stakeholder-analysis)
2. [Asset & Data Classification](#2-asset--data-classification)
3. [Threat Modeling](#3-threat-modeling)
4. [Vulnerability & Weakness Enumeration](#4-vulnerability--weakness-enumeration)
5. [Patterns vs Anti-Patterns](#5-patterns-vs-anti-patterns)
6. [Verdict Register](#6-verdict-register-must-fix--should-fix--absolute-no-gos)
7. [Security Non-Functional Requirements](#7-security-non-functional-requirements)
8. [Secure SDLC & Testability](#8-secure-sdlc--testability)
9. [Data Protection & Privacy](#9-data-protection--privacy)
10. [Incident Response & Recoverability](#10-incident-response--recoverability)
11. [Assumptions Register](#assumptions-register)
12. [Risk Register](#risk-register)
13. [Blocking Questions](#blocking-questions)
14. [Remediation Changelog](#remediation-changelog)
15. [Proof of Execution](#proof-of-execution)

---

## 1. Multi-Perspective Security Stakeholder Analysis

| Perspective | Forces answered |
|---|---|
| **Attacker (opportunistic)** | Credential stuffing hits NC core login. Device API is `PublicPage` but requires `snkterm_` Bearer + idle fail-closed (90d). Unlock PIN: 10/min + progressive lockout, closed oracle. Cheapest residual: stolen session on unthrottled admin POSTs (SF-A03); legacy browsers without `Sec-Fetch-Site` (AS-05). |
| **Attacker (targeted)** | Steal tablet Bearer → site-scoped money path only. Spoof unlock needs PIN/QR + device bind. Cross-kitchen undo BOLA closed (MF-A01). Directory scrape by kitchen managers closed (MF-A02). Shelf SKU existence oracle for app-denied NC users closed (MF-A04). Compromised app admin = org-wide money + payroll + license. |
| **Data subject** | Consumption lines, proxy/hospitality reasons, payroll CSV names, unlock PIN HMAC material. Breach → workplace PII + payroll. Notification depends on instance ops (AS-02). |
| **Security/Compliance/Legal** | GDPR workplace consumption + directory; no PCI/HIPAA. Veto if payroll exports or directory enumeration remain world-readable — they do not after MF-A02/A03/A05. |
| **Engineering** | Blast radius of one Bearer = one site’s device API. App-admin session = org-wide. Unlock sessions in distributed cache — multi-node needs Redis (SF-A01). |
| **Ops/IR** | Audit table + unlock lockouts + terminal last_seen. Pepper/Bearer rotation runbook still thin (document gap, not code hole). |
| **Business** | Payroll mis-attribution / cross-kitchen undo = trust + labor-law risk. Residual risk accepted only for SF items with owners. |
| **Downstream** | SNK2 license public verify only; no private key in app. Mobile companions use separate Bearer channel. |
| **Future auditor** | Contracts + mutation harness assert MF-A01–A05; PHPUnit unit suite executed this pass. |

**Open Security Conflict:** None blocking ship. SF-A01 (Redis for multi-node unlock) remains an **Ops acceptance** if HA without Redis is chosen.

---

## 2. Asset & Data Classification

## Data Inventory — SnackCheck

| Data Type | Sensitivity Tier | Where Stored | Who/What Can Access | Encrypted At Rest | Encrypted In Transit | Retention Period |
|---|---|---|---|---|---|---|
| NC session / CSRF token | Critical (auth) | NC session store + browser | Authenticated NC user | Instance/ops (AS-01) | TLS (AS-03) | Session lifetime |
| Terminal Bearer `snkterm_` | Critical (device auth) | SHA-256 at rest in DB; clear once at register | Device + kitchen admin register path | DB at rest (AS-01) | TLS | Until revoke / idle 90d |
| Unlock PIN/QR material | Critical (auth) | HMAC-SHA256 + pepper in appconfig | Unlock verify path | Pepper in appconfig (AS-01) | TLS | Until rotate |
| Unlock session token | Critical (short-lived) | Distributed cache only (120s) | Device + unlock verify | Cache backend (SF-A01) | TLS | 120s |
| Consumption ledger lines | High (payroll PII) | `snk_logs` | Actor / kitchen manager / app admin / device session | AS-01 | TLS | Period + audit policy |
| Payroll / BR / hospitality exports | Critical when downloaded | Generated on demand | App admin / kitchen manager (scoped) | N/A (ephemeral) | TLS + Sec-Fetch guard | Not stored by app |
| Directory UID/display names | High (PII) | NC user manager (read) | App admin for `scope=directory`; access-scoped for others | NC | TLS | NC account life |
| SNK2 license envelope | High (commercial) | `snk_license_state` | App admin apply; device gate | AS-01 | TLS | License life |
| Audit events | Medium–High | `snk_audit` | App admin | AS-01 | TLS | Product retention TBD (Q1) |
| Application logs | Medium | NC log | Ops | AS-01 | TLS | Instance policy |

---

## 3. Threat Modeling

### Trust boundaries
1. Browser ↔ Nextcloud session (CSRF + SameSite)
2. Kitchen tablet ↔ Device API (Bearer + unlock token)
3. App ACL (access / kitchen manager / app admin / site manage)
4. License gate (SNK2 instance bind)
5. DB / appconfig / distributed cache

## Threat Register — SnackCheck

| ID | Threat | Attacker Tier | Entry Point | Impact | Likelihood | Current Mitigation | Verdict |
|---|---|---|---|---|---|---|---|
| TH-01 | Cross-kitchen tablet undo (BOLA) | Compromised device / stolen Bearer | `DeviceApiController::undoLog` | Void another site’s line | High if unfixed | `selfUndo(..., device.siteId)` under lock | 🟢 Mitigated MF-A01 |
| TH-02 | Directory scrape via `scope=directory` | Authenticated kitchen manager | `searchUsers` | Org-wide PII | High if unfixed | `assertAppAdmin` for directory | 🟢 Mitigated MF-A02 |
| TH-03 | CSRF-exempt GET payroll/export | Unauthenticated + victim session | Download routes | Payroll exfil | Med (modern browsers) | `assertNotCrossSiteDownload` all formats | 🟢 Mitigated MF-A03/A05 |
| TH-04 | Empty CSRF token → silent money POST fail / bypass attempts | Auth user / XSS | `POST /api/logs` | Availability / confused deputy | High historically | `OC.requestToken` + head + body + refresh | 🟢 Mitigated (CSRF UX/security) |
| TH-05 | Shelf SKU existence oracle | NC user without app access | `PageController::shelf` | Catalog probe 404 vs 403 | Med | `assertAccess` before `catalog->get` | 🟢 Mitigated MF-A04 |
| TH-06 | Client `userId` on device createLog | Malicious tablet app | Device createLog | Charge wrong employee | High if unfixed | Actor from unlock session only | 🟢 Mitigated NG-03 |
| TH-07 | Unlock PIN vs ACL status oracle | Unauth / stolen Bearer | unlockVerify | User enumeration | Med | Uniform `unlock_invalid` + fail count | 🟢 Mitigated |
| TH-08 | Immortal Bearer | Stolen tablet token | Device API | Long-lived site money path | Med | 90d idle fail-closed | 🟢 Mitigated Zeus MF-02 |
| TH-09 | Void TOCTOU foreign site | Kitchen manager | `voidLog` | Cross-site void | Med | ACL under row lock | 🟢 Mitigated Zeus MF-01 |
| TH-10 | Multi-node unlock/RL split-brain | Targeted + HA misconfig | Unlock cache | Weaker lockout | Med on HA w/o Redis | Document Redis (SF-A01) | 🟡 Should-Fix |
| TH-11 | Unthrottled web admin POSTs | Stolen NC session | `saveSettings`/`voidLog` | Burst abuse | Med | NC session + CSRF only | 🟡 Should-Fix SF-A03 |
| TH-12 | Offline PIN cracking if DB+pepper stolen | Insider / backup thief | DB dump | Unlock forge | Low–Med | HMAC not Argon2id (SF-A04) | 🟡 Should-Fix |

---

## 4. Vulnerability & Weakness Enumeration

### 4.1 Authentication & Session Management
| Check | Result |
|---|---|
| Password storage | N/A — NC core |
| MFA | N/A at app layer — AS-04 (NC) |
| Session / CSRF | Pass — money POSTs CSRF-protected; web token from `OC.requestToken` / `data-requesttoken` |
| Device Bearer | Pass — `random_bytes(32)`, SHA-256 at rest, idle 90d |
| Unlock | Pass — rate limit + progressive lockout + closed oracle |
| Credential stuffing on unlock | Pass — 10/min + lockout schedule |

### 4.2 Authorization / Access Control
| Check | Result |
|---|---|
| Object-level (ledger) | Pass — ownership / site under lock (MF-A01, Zeus MF-01) |
| Directory scope | Pass — app admin only (MF-A02) |
| Device attribution | Pass — session userId, ignore client (NG-03) |
| Shelf probe | Pass — assertAccess first (MF-A04) |
| Function-level | Pass — server-side assert* on mutators |

### 4.3 Injection
| Check | Result |
|---|---|
| SQL | Pass — QueryBuilder + bound params; `FOR UPDATE` on bound SQL |
| Command / XXE / unsafe deserialize | N/A — no shell/XML/native unserialize of client data |
| XSS | Pass — `p()` in templates; JS `textContent`; IconCatalog allowlist SVG |
| SSRF | N/A — no user-URL fetch |

### 4.4 CSRF & Clickjacking
| Check | Result |
|---|---|
| Mutating POSTs | Pass — CSRF required (NG-02) |
| GET downloads | Pass — Sec-Fetch-Site cross-site rejected (MF-A03/A05) |
| Frame headers | AS-06 — NC core |

### 4.5 Secrets Management
| Check | Result |
|---|---|
| Private license key in repo | Pass — verify-only pubkey |
| e2e `.env` | Pass — gitignored, not tracked |
| Pepper | Pass — minted into appconfig under lock |
| Detection | Pre-commit/CI secrets scan recommended (NG-A01 detection) |

### 4.6 Cryptography
| Check | Result |
|---|---|
| TLS | AS-03 |
| License | Ed25519 verify |
| PIN | HMAC-SHA256 + pepper (SF-A04: not memory-hard) |
| Unlock tokens | random, device-bound `hash_equals` |

### 4.7 Input Validation & File Handling
| Check | Result |
|---|---|
| Server-side validation | Pass — DomainException paths; ghost UID rejection |
| Uploads | N/A — no arbitrary file upload |

### 4.8 Supply Chain
| Check | Result |
|---|---|
| SCA | Should run in CI (SF-A05 process) |
| SBOM | Not app-shipped — instance CI |

### 4.9 Logging & Data Exposure
| Check | Result |
|---|---|
| Security events | Audit table for admin actions |
| Secrets in logs | No intentional secret logging found |
| Unlock oracle | Closed |

### 4.10 DoS / Resource Exhaustion
| Check | Result |
|---|---|
| Device RL | 120/min |
| Unlock RL | 10/min + lockout |
| User log RL | 60/min |
| Web admin mutators | **No app RL** — SF-A03 |

---

## 5. Patterns vs Anti-Patterns

| ✅ Pattern | ❌ Anti-Pattern avoided |
|---|---|
| Site bind under lock on device undo | Trusting client siteId / log id alone |
| Directory search admin-gated | Kitchen manager = directory scraper |
| CSRF on money POSTs + token refresh | Meta-only empty token / NoCSRF on POST |
| Assert access before catalog probe | 404/403 SKU oracle |
| Cross-site download reject | CSRF-exempt GET exfil |
| Actor from unlock session | Client-supplied `userId` |
| Uniform unlock errors | PIN vs ACL oracle |

---

## 6. Verdict Register (Must-Fix / Should-Fix / Absolute No-Gos)

### Must-Fix

| ID | Finding | CVSS (ref) | Traces to | Status | Evidence of Resolution |
|---|---|---|---|---|---|
| MF-A01 | Device undo cross-site BOLA | 8.1 | TH-01 | 🟢 VERIFIED | `DeviceUndoSiteBindTest`; mutation `device undoLog binds selfUndo to device siteId` |
| MF-A02 | `searchUsers?scope=directory` without app-admin | 7.5 | TH-02 | 🟢 VERIFIED | `SearchUsersDirectoryScopeTest`; mutation directory admin gate |
| MF-A03 | CSRF-exempt GET downloads accept cross-site | 7.1 | TH-03 | 🟢 VERIFIED | `CrossSiteDownloadGuardContractTest`; mutation cross-site guard |
| MF-A04 | Shelf catalog probe before `assertAccess` (SKU oracle) | 5.3→elevated as broken-access pattern | TH-05 | 🟢 VERIFIED | `ShelfAndHospitalityPrivacyContractTest::testShelfAssertsAccessBeforeCatalogProbe`; mutation shelf assertAccess-before-probe |
| MF-A05 | Shopping/BR JSON paths skipped cross-site guard | 6.5 | TH-03 | 🟢 VERIFIED | Guard moved before work on `shoppingList`/`brReport`; contract asserts all formats |

### Should-Fix

| ID | Finding | Rationale for Deferability | Backlog Reference | Status |
|---|---|---|---|---|
| SF-A01 | Unlock/RL cache needs Redis on multi-node | Single-node OK; HA misconfig | Ops runbook | Deferred — owned Ops |
| SF-A03 | Web admin POSTs unthrottled | Needs stolen session + CSRF token | Eng backlog | Deferred |
| SF-A04 | PIN = HMAC not Argon2id | Requires DB+pepper theft | Security backlog | Deferred — accepted residual |
| SF-A05 | Formal SCA/SBOM in app CI | Workspace CI process | Platform | Deferred |

### Absolute No-Gos

| ID | Forbidden Behavior | Why | Detection Mechanism | Status | Evidence |
|---|---|---|---|---|---|
| NG-01 | Persist unlock sessions in SQL as source of truth | Durable unlock = stolen-DB session forge | UnlockSessionArchitectureContractTest + code review | 🟢 VERIFIED | Cache-only sessions |
| NG-02 | CSRF-exempt money-mutating POSTs | Session riding on ledger | SettingsSwitch / PageCsrf / Api mutator contracts | 🟢 VERIFIED | No `NoCSRFRequired` on create/void/undo |
| NG-03 | Trust client `userId` on device createLog | Charge wrong employee | Mutation NN-13 assert | 🟢 VERIFIED | Actor from unlock session |
| NG-A01 | Commit production private signing keys / peppers | Full compromise if repo leaks | Gitignore + pubkey-only VendorPublicKey; `git ls-files` e2e/.env empty | 🟢 VERIFIED | Evidence pass this audit |

---

## 7. Security Non-Functional Requirements

| Category | Spec |
|---|---|
| **Authentication** | NC session for web; device Bearer + unlock token (120s) for tablets; MFA via NC (AS-04) |
| **Authorization** | RBAC/site ACL enforced server-side on every mutator; directory = app admin |
| **Encryption** | TLS in transit (AS-03); at rest = instance DB (AS-01); license Ed25519; PIN HMAC+pepper |
| **Vulnerability management** | Dependency scan on CI recommended; Critical &lt;7d / High &lt;30d (SF-A05) |
| **Audit logging** | Admin/settings/void/period events in `snk_audit`; retention TBD (Q1) |
| **IR SLA** | Detection via audit + unlock lockouts; legal notify per GDPR 72h (AS-02) |
| **Pentest** | Recommend gray-box before first paid enterprise deploy of payroll exports |

---

## 8. Secure SDLC & Testability

| Control | Status |
|---|---|
| Unit contracts for MFs | Present + executed |
| Mutation harness for critical money/ACL | Present + executed |
| Playwright CSRF / a11y | Present (host) |
| SAST/SCA/secrets in CI | Recommended SF-A05 |
| Security regression ↔ MF 1:1 | MF-A01–A05 mapped |

---

## 9. Data Protection & Privacy

- Minimization: directory search admin-only; hospitality allowlist app-admin gated
- Purpose: workplace honor ledger / payroll export
- Retention/erasure: **Q1** — no in-app DSAR purge API
- Residency: instance-local NC DB
- Processors: customer’s Nextcloud host only (app does not ship data off-instance)

---

## 10. Incident Response & Recoverability

| Step | Capability |
|---|---|
| **Detection** | Audit events; unlock lockouts; terminal last_seen freeze; license trim |
| **Containment** | Revoke terminal; close period; disable hospitality; rotate PIN/QR/pepper; disable app access lists |
| **Forensics** | `snk_audit` + NC logs; unlock cache ephemeral |
| **Credential rotation** | Re-register terminals; setUnlockPin/Qr; pepper mint under lock |
| **Comms** | Instance admin + Legal for GDPR clock |
| **Post-incident** | New findings → Verdict Register Must-Fix |

---

## Assumptions Register

| ID | Assumption | Made Because | Invalidates If | Owner to Confirm |
|---|---|---|---|---|
| AS-01 | NC DB/appconfig encryption-at-rest is instance/ops | App uses NC primitives | Unencrypted disks in regulated deploy | Infra |
| AS-02 | Breach notification runbook outside app | No in-app IR module | Customer expects app-native DSAR/IR | Legal/Ops |
| AS-03 | TLS terminated correctly in front of NC | Standard NC | Cleartext reverse proxy | Infra |
| AS-04 | Privileged MFA enforced at NC when required | App cannot mandate NC MFA | High-risk deploy without MFA | Security |
| AS-05 | Missing `Sec-Fetch-Site` = legacy/non-browser; allow | Avoid breaking curl/admin scripts | Ancient browser CSRF download | Security — monitor |
| AS-06 | NC sends frame-ancestors / X-FO | Core NC headers | Custom theme strips headers | Infra |

---

## Risk Register

| ID | Risk | Residual after audit | Owner |
|---|---|---|---|
| R-01 | Cross-kitchen tablet undo | Mitigated MF-A01 | Eng |
| R-02 | Directory PII scrape | Mitigated MF-A02 | Eng |
| R-03 | Payroll/export CSRF GET | Mitigated MF-A03/A05; residual AS-05 | Eng/Security |
| R-04 | Shelf SKU oracle | Mitigated MF-A04 | Eng |
| R-05 | Multi-node unlock inconsistency | Open SF-A01 | Ops |
| R-06 | Pepper+DB theft → offline PIN | Accepted SF-A04 | Security |

---

## Blocking Questions

None blocking Must-Fix / Absolute No-Go closure.

```
Q1 (non-blocking): Is there a documented DSAR / right-to-erasure procedure for
SnackCheck consumption rows and audit events on customer instances?
Why it matters: Section 9 GDPR completeness; does not reopen money-path vulns.
Plausible answers: (a) NC user deletion + manual SQL runbook,
(b) product backlog for purge APIs, (c) N/A — no EU subjects.
```

---

## Remediation Changelog

| Finding | Change |
|---|---|
| MF-A01 | `selfUndo`/`void` optional `$requiredSiteId` under lock; device undo passes device siteId |
| MF-A02 | `searchUsers` `scope=directory` → `assertAppAdmin` |
| MF-A03 | `assertNotCrossSiteDownload()` on sensitive GET downloads |
| MF-A04 | `PageController::shelf` calls `assertAccess` **before** `catalog->get` |
| MF-A05 | `shoppingList` / `brReport` call cross-site guard before building data (all formats) |
| CSRF web | `js/app.js` token from `OC.requestToken` + `head[data-requesttoken]`; header+body; refresh on 412 |

---

## Proof of Execution

```text
$ cd nextcloud && docker compose ps
# nextcloud Up (service: nextcloud)

$ cd nextcloud/apps/snackcheck && ./vendor/bin/phpunit --testsuite unit
OK (313 tests, 1516 assertions)

$ php tests/Mutation/run-critical-mutations.php
EXIT:0 — includes Argus MF asserts for:
  device undo site bind, requiredSiteId under lock,
  cross-site download guard (all formats) + directory admin gate,
  shelf assertAccess-before-probe
```

**Ship stance (Argus):** Money and device trust boundaries hold. MF-A01–A05 are 🟢 VERIFIED with regression contracts + mutation asserts. Absolute No-Gos remain enforced. Remaining items are owned Should-Fix / assumptions — not silent hope. No evidence of active compromise during this audit.
