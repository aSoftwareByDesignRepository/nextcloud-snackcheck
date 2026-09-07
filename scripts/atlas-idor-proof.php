<?php

declare(strict_types=1);

/**
 * Atlas Lens 2 — live AuthZ / IDOR proofs against real MariaDB services.
 * Run: docker compose exec -u www-data nextcloud php .../atlas-idor-proof.php
 */
require '/var/www/html/lib/base.php';

use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCP\IUserManager;

$um = \OC::$server->get(IUserManager::class);
$session = \OC::$server->get(\OCP\IUserSession::class);
$access = \OC::$server->get(AccessControlService::class);
$sites = \OC::$server->get(SiteService::class);
$catalog = \OC::$server->get(CatalogService::class);
$logs = \OC::$server->get(ConsumptionLogService::class);
$periods = \OC::$server->get(PeriodService::class);
$settings = \OC::$server->get(SettingsService::class);

$results = [];
$fail = 0;

function expectCode(callable $fn, string $code, string $label, array &$results, int &$fail): void
{
	try {
		$fn();
		$results[] = ['label' => $label, 'pass' => false, 'detail' => 'expected DomainException ' . $code . ' but succeeded'];
		$fail++;
	} catch (DomainException $e) {
		$ok = $e->errorCode === $code || in_array($e->httpStatus, [403, 404], true);
		$results[] = [
			'label' => $label,
			'pass' => $ok,
			'detail' => $e->errorCode . '/' . $e->httpStatus . ': ' . $e->getMessage(),
		];
		if (!$ok) {
			$fail++;
		}
	} catch (Throwable $e) {
		$results[] = ['label' => $label, 'pass' => false, 'detail' => $e::class . ': ' . $e->getMessage()];
		$fail++;
	}
}

// Ensure multi-site + two kitchens with distinct managers
$admin = $um->get('admin');
$session->setUser($admin);
$settings->setMultiSiteEnabled(true);

$siteA = null;
$siteB = null;
foreach ($sites->listActive() as $s) {
	if ($s->getCode() === 'ATLAS-A') {
		$siteA = $s;
	}
	if ($s->getCode() === 'ATLAS-B') {
		$siteB = $s;
	}
}
if ($siteA === null) {
	$siteA = $sites->create('Atlas Kitchen A', 'ATLAS-A', ['alice_atlas']);
} else {
	$sites->update((int)$siteA->getId(), null, ['alice_atlas'], null);
	$siteA = $sites->get((int)$siteA->getId());
}
if ($siteB === null) {
	$siteB = $sites->create('Atlas Kitchen B', 'ATLAS-B', ['bob_atlas']);
} else {
	$sites->update((int)$siteB->getId(), null, ['bob_atlas'], null);
	$siteB = $sites->get((int)$siteB->getId());
}

$itemB = $catalog->create((int)$siteB->getId(), 'Atlas IDOR Bait ' . uniqid('', true), 99, 'admin', 'snack');
$periods->ensureOpenPeriod();

// alice (manager A) must NOT manage site B
expectCode(
	static fn () => $access->assertCanManageSite('alice_atlas', (int)$siteB->getId()),
	'foreign_site',
	'SEC-IDOR-01 alice cannot manage site B',
	$results,
	$fail
);

// bob cannot manage site A
expectCode(
	static fn () => $access->assertCanManageSite('bob_atlas', (int)$siteA->getId()),
	'foreign_site',
	'SEC-IDOR-02 bob cannot manage site A',
	$results,
	$fail
);

// alice cannot void as admin path on site B log (site ACL under lock)
$bobLog = $logs->create([
	'itemId' => (int)$itemB->getId(),
	'qty' => 1,
	'idempotencyKey' => 'atlas-idor-bob-' . bin2hex(random_bytes(6)),
	'siteId' => (int)$siteB->getId(),
	'actorUserId' => 'bob_atlas',
	'source' => 'web',
	'mode' => 'self',
]);
$logId = (int)$bobLog['log']->getId();

expectCode(
	static fn () => $logs->void($logId, 'alice_atlas', 'idor probe', true),
	'foreign_site',
	'SEC-IDOR-03 alice cannot void bob site-B log as manager',
	$results,
	$fail
);

// bob_atlas cannot self-undo alice's log on another site
$itemA = $catalog->create((int)$siteA->getId(), 'Atlas IDOR A ' . uniqid('', true), 50, 'admin', 'snack');
$aliceLog = $logs->create([
	'itemId' => (int)$itemA->getId(),
	'qty' => 1,
	'idempotencyKey' => 'atlas-idor-alice-' . bin2hex(random_bytes(6)),
	'siteId' => (int)$siteA->getId(),
	'actorUserId' => 'alice_atlas',
	'source' => 'web',
	'mode' => 'self',
]);
expectCode(
	static fn () => $logs->selfUndo((int)$aliceLog['log']->getId(), 'bob_atlas'),
	'permission_denied',
	'SEC-IDOR-04 bob cannot self-undo alice log',
	$results,
	$fail
);

// alice IS kitchen manager (positive)
try {
	$access->assertKitchenManager('alice_atlas');
	$results[] = ['label' => 'SEC-RBAC-05 alice_atlas is kitchen manager (positive)', 'pass' => true, 'detail' => 'ok'];
} catch (Throwable $e) {
	$results[] = ['label' => 'SEC-RBAC-05 alice_atlas is kitchen manager (positive)', 'pass' => false, 'detail' => $e->getMessage()];
	$fail++;
}

// Non-manager employee: create ephemeral? use a user not on managers
// Prefer ekc_victim if exists
$victim = $um->get('ekc_victim') ? 'ekc_victim' : 'admin';
if ($victim !== 'admin') {
	expectCode(
		static fn () => $access->assertKitchenManager($victim),
		'permission_denied',
		'SEC-RBAC-06 plain employee denied kitchen manager',
		$results,
		$fail
	);
	expectCode(
		static fn () => $access->assertAppAdmin($victim),
		'permission_denied',
		'SEC-RBAC-07 plain employee denied app admin',
		$results,
		$fail
	);
}

// resolveManagedSiteId foreign site
expectCode(
	static fn () => $access->resolveManagedSiteId('alice_atlas', (int)$siteB->getId()),
	'foreign_site',
	'SEC-IDOR-08 alice resolveManagedSiteId site B denied',
	$results,
	$fail
);

// Positive: alice can manage A
try {
	$access->assertCanManageSite('alice_atlas', (int)$siteA->getId());
	$results[] = ['label' => 'SEC-POS-09 alice manages site A', 'pass' => true, 'detail' => 'ok'];
} catch (Throwable $e) {
	$results[] = ['label' => 'SEC-POS-09 alice manages site A', 'pass' => false, 'detail' => $e->getMessage()];
	$fail++;
}

$catalog->softDelete((int)$itemA->getId(), 'admin');
$catalog->softDelete((int)$itemB->getId(), 'admin');

echo json_encode(['fail' => $fail, 'results' => $results], JSON_PRETTY_PRINT) . "\n";
exit($fail === 0 ? 0 : 2);
