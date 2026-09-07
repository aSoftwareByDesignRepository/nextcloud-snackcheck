<?php

declare(strict_types=1);

/**
 * Atlas Lens 1 worker — one create attempt.
 * Usage: php atlas-concurrent-idempotency-worker.php <itemId> <siteId> <key>
 */
if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "cli only\n");
	exit(2);
}

$itemId = (int)($argv[1] ?? 0);
$siteId = (int)($argv[2] ?? 0);
$key = (string)($argv[3] ?? '');
if ($itemId <= 0 || $siteId <= 0 || $key === '') {
	fwrite(STDERR, "usage: itemId siteId key\n");
	exit(2);
}

require '/var/www/html/lib/base.php';

$user = \OC::$server->get(\OCP\IUserManager::class)->get('admin');
if ($user === null) {
	fwrite(STDERR, "no admin\n");
	exit(2);
}
\OC::$server->get(\OCP\IUserSession::class)->setUser($user);

$logs = \OC::$server->get(\OCA\SnackCheck\Service\ConsumptionLogService::class);
try {
	$result = $logs->create([
		'itemId' => $itemId,
		'qty' => 1,
		'idempotencyKey' => $key,
		'siteId' => $siteId,
		'actorUserId' => 'admin',
		'source' => 'web',
		'mode' => 'self',
	]);
	echo json_encode([
		'ok' => true,
		'replay' => $result['replay'],
		'http' => $result['httpStatus'],
		'logId' => (int)$result['log']->getId(),
	]) . "\n";
} catch (Throwable $e) {
	$code = $e instanceof \OCA\SnackCheck\Exception\DomainException ? $e->errorCode : null;
	echo json_encode([
		'ok' => false,
		'error' => $e->getMessage(),
		'code' => $code,
		'class' => $e::class,
	]) . "\n";
	exit(1);
}
