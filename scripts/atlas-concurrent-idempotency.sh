#!/usr/bin/env bash
# Atlas Lens 1 — fire N concurrent creates with the same idempotency key; assert DB count = 1.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_DIR="$(cd "$ROOT/../.." && pwd)"
OUT_DIR="${ATLAS_OUT:-/home/alex/Development/nextcloud-dev/documentation/snackcheck/qa-report/artifacts}"
mkdir -p "$OUT_DIR"
RUN_ID="atlas-l1-$(date +%Y%m%d-%H%M%S)"
WORKERS="${WORKERS:-8}"

cd "$COMPOSE_DIR"

# Seed item + key via one-shot PHP
SEED_JSON=$(docker compose exec -T -u www-data nextcloud php -r '
require "/var/www/html/lib/base.php";
$user = OC::$server->get(OCP\IUserManager::class)->get("admin");
OC::$server->get(OCP\IUserSession::class)->setUser($user);
$sites = OC::$server->get(OCA\SnackCheck\Service\SiteService::class);
$catalog = OC::$server->get(OCA\SnackCheck\Service\CatalogService::class);
$periods = OC::$server->get(OCA\SnackCheck\Service\PeriodService::class);
$site = $sites->ensureDefaultSite();
$periods->ensureOpenPeriod();
$item = $catalog->create((int)$site->getId(), "Atlas Conc ".uniqid("", true), 125, "admin", "drink");
$key = "atlas-conc-".bin2hex(random_bytes(12));
echo json_encode(["itemId"=>(int)$item->getId(),"siteId"=>(int)$site->getId(),"key"=>$key]);
')

ITEM_ID=$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["itemId"];' <<<"$SEED_JSON")
SITE_ID=$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["siteId"];' <<<"$SEED_JSON")
KEY=$(php -r 'echo json_decode(file_get_contents("php://stdin"), true)["key"];' <<<"$SEED_JSON")

echo "seed: item=$ITEM_ID site=$SITE_ID key=$KEY workers=$WORKERS"

WORKER=/var/www/html/custom_apps/snackcheck/scripts/atlas-concurrent-idempotency-worker.php
TMP=$(mktemp -d)
for i in $(seq 1 "$WORKERS"); do
  docker compose exec -T -u www-data nextcloud php "$WORKER" "$ITEM_ID" "$SITE_ID" "$KEY" \
    >"$TMP/w$i.json" 2>"$TMP/w$i.err" &
done
wait

# DB ground truth
COUNT=$(docker compose exec -T mariadb mysql -N -unextcloud -pnextcloud_password nextcloud \
  -e "SELECT COUNT(*) FROM oc_snk_consumption_logs WHERE idempotency_key='${KEY}';" 2>/dev/null | tr -d '\r')

{
  echo "runId=$RUN_ID"
  echo "key=$KEY"
  echo "itemId=$ITEM_ID"
  echo "workers=$WORKERS"
  echo "dbCount=$COUNT"
  echo "--- worker results ---"
  cat "$TMP"/w*.json
  echo "--- worker stderr ---"
  cat "$TMP"/w*.err 2>/dev/null || true
} | tee "$OUT_DIR/${RUN_ID}-concurrent-idempotency.txt"

# cleanup item
docker compose exec -T -u www-data nextcloud php -r "
require '/var/www/html/lib/base.php';
\$u = OC::\$server->get(OCP\IUserManager::class)->get('admin');
OC::\$server->get(OCP\IUserSession::class)->setUser(\$u);
OC::\$server->get(OCA\SnackCheck\Service\CatalogService::class)->softDelete($ITEM_ID, 'admin');
" >/dev/null

rm -rf "$TMP"
if [[ "$COUNT" == "1" ]]; then
  echo "PASS: dbCount=1"
  exit 0
fi
echo "FAIL: dbCount=$COUNT expected 1"
exit 2
