<?php

declare(strict_types=1);

/** Atlas Lens 2 — rate limit must trip after DEVICE_API_LIMIT (120/min). */
require '/var/www/html/lib/base.php';

$rl = \OC::$server->get(\OCA\SnackCheck\Service\RateLimitService::class);
$device = 'atlas-rl-' . bin2hex(random_bytes(4));
$tripped = false;
$n = 0;
for ($i = 0; $i < 130; $i++) {
	$n++;
	try {
		$rl->assertDeviceApi($device);
	} catch (\OCA\SnackCheck\Exception\DomainException $e) {
		if ($e->errorCode === 'rate_limited' && $e->httpStatus === 429) {
			$tripped = true;
			break;
		}
		throw $e;
	}
}
echo json_encode(['tripped' => $tripped, 'atHit' => $n, 'pass' => $tripped && $n === 121]) . "\n";
exit($tripped && $n === 121 ? 0 : 2);
