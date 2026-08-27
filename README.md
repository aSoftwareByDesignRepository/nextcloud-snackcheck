# SnackCheck

Honor kitchen snack/drink ledger for Nextcloud (AGPL). Kitchen Tablet companion uses `SNK2` device licences — web is never gated.

App Store listing screenshots (1920×1040) live in [`screenshots/`](screenshots/). Regenerate:

```bash
npx playwright test e2e/capture-store-screenshots.spec.js --project=chromium-store
```

Release archives: `make release` / `make release-signed` (see the App Store developer guide). Security reports: [SECURITY.md](SECURITY.md).

## Dev

```bash
composer install
./vendor/bin/phpunit --testsuite unit
php tests/Mutation/run-critical-mutations.php
php tests/Mutation/run-subsidy-mutations.php
```

Integration tests (Docker Nextcloud service):

```bash
cd ../../  # nextcloud/
docker compose exec -u www-data nextcloud \
  php /var/www/html/custom_apps/snackcheck/vendor/bin/phpunit \
  --configuration /var/www/html/custom_apps/snackcheck/phpunit.xml \
  --testsuite integration
```

Enable: `occ app:enable snackcheck`

## Companion

`mobile/snackcheck-kiosk/` + `mobile/shared/snackcheck-licensing/`

## Security / ops (read before HA or key overrides)

### HA / multi-node

Unlock tokens, PIN lockout, and API rate limits use Nextcloud **distributed** cache + `ILockingProvider`. On more than one app node you **must** configure shared Redis (or equivalent) for cache and locking in Nextcloud. Without it, unlock sessions and lockouts split per node.

### Vendor public key override

`SNK_ALLOW_VENDOR_KEY_OVERRIDE=1` lets PHPUnit / emergency ops replace the SNK2 verify key via env. **Never set this in production.** Production tablets and servers must use the baked vendor public key only.

### Dependency audit (release checklist)

Before tagging a release:

```bash
composer audit
```

Track Critical/High findings to patch SLA (Critical &lt;7d, High &lt;30d). Formal SBOM CI is still backlog.

### Unlock lockout

3 wrong PIN/QR attempts → soft lockout that **escalates** on repeat trips without a successful unlock: **30s → 60s → 5m → 15m**. A successful unlock clears the escalation tier. Device API returns accurate `Retry-After` / `retryAfter` for the kiosk countdown.
