#!/usr/bin/env bash
# Atlas Lens 1 — concurrent openNextPeriod must produce exactly one open period.
set -euo pipefail
COMPOSE_DIR=/home/alex/Development/nextcloud-dev/nextcloud
OUT=/home/alex/Development/nextcloud-dev/documentation/snackcheck/qa-report/artifacts
mkdir -p "$OUT"
RUN_ID="atlas-l1-open-$(date +%Y%m%d-%H%M%S)"
cd "$COMPOSE_DIR"

# Ensure we are in a state with NO open period (close current if open)
docker compose exec -T -u www-data nextcloud php -r '
require "/var/www/html/lib/base.php";
$u = OC::$server->get(OCP\IUserManager::class)->get("admin");
OC::$server->get(OCP\IUserSession::class)->setUser($u);
$p = OC::$server->get(OCA\SnackCheck\Service\PeriodService::class);
$open = $p->findOpen();
if ($open !== null) {
  $p->close((int)$open->getId(), "admin", true);
  echo "closed ".(int)$open->getId()."\n";
} else {
  echo "already closed\n";
}
'

WORKER_PHP='
require "/var/www/html/lib/base.php";
$u = OC::$server->get(OCP\IUserManager::class)->get("admin");
OC::$server->get(OCP\IUserSession::class)->setUser($u);
$p = OC::$server->get(OCA\SnackCheck\Service\PeriodService::class);
try {
  $period = $p->openNextPeriod("admin");
  echo json_encode(["ok"=>true,"id"=>(int)$period->getId(),"label"=>$period->getLabel()])."\n";
} catch (Throwable $e) {
  $code = $e instanceof OCA\SnackCheck\Exception\DomainException ? $e->errorCode : null;
  echo json_encode(["ok"=>false,"error"=>$e->getMessage(),"code"=>$code])."\n";
}
'
TMP=$(mktemp -d)
for i in 1 2 3 4; do
  docker compose exec -T -u www-data nextcloud php -r "$WORKER_PHP" >"$TMP/w$i.json" 2>"$TMP/w$i.err" &
done
wait

OPEN_COUNT=$(docker compose exec -T mariadb mysql -N -unextcloud -pnextcloud_password nextcloud \
  -e "SELECT COUNT(*) FROM oc_snk_periods WHERE state='open';" 2>/dev/null | tr -d '\r')

{
  echo "runId=$RUN_ID"
  echo "openCount=$OPEN_COUNT"
  echo "--- workers ---"
  cat "$TMP"/w*.json
} | tee "$OUT/${RUN_ID}-open-next-race.txt"

rm -rf "$TMP"
if [[ "$OPEN_COUNT" == "1" ]]; then
  echo "PASS: exactly one open period"
  exit 0
fi
echo "FAIL: openCount=$OPEN_COUNT"
exit 2
