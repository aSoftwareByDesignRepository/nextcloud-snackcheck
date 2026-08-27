<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Integration;

use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\LicenseEnforcementService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Companion money path: license → register → unlock PIN → terminal log → My month (CORE §10.2).
 */
final class DeviceCompanionJourneyIntegrationTest extends TestCase
{
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	public function testRegisterUnlockLogAppearsInMyMonthThenUnpair(): void
	{
		if (!$this->db->tableExists('snk_term_devices')) {
			self::markTestSkipped('SnackCheck tables not present');
		}

		$golden = json_decode(
			(string)file_get_contents(dirname(__DIR__) . '/fixtures/license_snk2_golden.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$wire = (string)($golden['wireKey'] ?? '');
		self::assertNotSame('', $wire);
		putenv('SNK_VENDOR_PUBLIC_KEY_B64=' . (string)$golden['publicKeyB64']);
		putenv('SNK_ALLOW_VENDOR_KEY_OVERRIDE=1');

		$license = \OC::$server->get(LicenseService::class);
		$enforcement = \OC::$server->get(LicenseEnforcementService::class);
		$terminals = \OC::$server->get(TerminalDeviceService::class);
		$unlock = \OC::$server->get(UnlockService::class);
		$sites = \OC::$server->get(SiteService::class);
		$catalog = \OC::$server->get(CatalogService::class);
		$logs = \OC::$server->get(ConsumptionLogService::class);
		$periods = \OC::$server->get(PeriodService::class);
		$logMapper = \OC::$server->get(ConsumptionLogMapper::class);

		self::assertTrue($license->applyLicenseKey($wire));
		$summary = $license->getLicenseSummary();
		$enforcement->trimTerminalsToLimit((int)($summary['terminalDevices'] ?? 0));
		self::assertTrue($license->isTerminalPlanActive());
		// Ensure a free device slot for this journey (shared Docker DB may already be at cap).
		foreach ($terminals->listActive() as $existing) {
			$terminals->revoke((int)$existing['id'], 'journey-cleanup');
		}

		$site = $sites->ensureDefaultSite();
		$siteId = (int)$site->getId();
		$periods->ensureOpenPeriod();

		$reg = $terminals->register('admin', 'Journey Tablet ' . uniqid('', true), $siteId);
		self::assertTrue($reg['ok'], (string)($reg['error'] ?? 'register failed'));
		$token = (string)($reg['deviceToken'] ?? '');
		self::assertStringStartsWith('snkterm_', $token);
		$deviceId = (int)($reg['device']['id'] ?? 0);

		$pin = '42' . substr((string)(time() % 1000000), -6);
		$unlock->setPin('admin', $pin, 'admin');
		$session = $unlock->verify($pin, null, 'dev:journey:' . $deviceId, null, $siteId, (string)$deviceId);
		self::assertSame('admin', $session['userId']);
		self::assertNotSame('', $session['unlockToken']);

		$item = $catalog->create($siteId, 'Journey Espresso ' . uniqid('', true), 120, 'admin', 'drink');
		$key = 'journey-' . bin2hex(random_bytes(8));
		$created = $logs->create([
			'itemId' => (int)$item->getId(),
			'qty' => 1,
			'idempotencyKey' => $key,
			'siteId' => $siteId,
			'actorUserId' => $session['userId'],
			'source' => 'terminal',
			'mode' => 'self',
			'deviceId' => (string)$deviceId,
			'isKitchenAdmin' => $session['isKitchenAdmin'],
		]);
		self::assertSame(201, $created['httpStatus']);
		self::assertSame(120, (int)$created['log']->getLineTotalCents());

		$period = $periods->ensureOpenPeriod();
		$mine = $logMapper->findForUserPeriod((int)$period->getId(), 'admin');
		$ids = array_map(static fn ($row) => (int)$row->getId(), $mine);
		self::assertContains((int)$created['log']->getId(), $ids, 'terminal log must appear in My month');

		$resolved = $terminals->resolveToken('Bearer ' . $token);
		self::assertNotNull($resolved);
		$unpair = $terminals->revoke($deviceId, 'device:' . $deviceId);
		self::assertTrue($unpair['ok']);
		self::assertNull($terminals->resolveToken('Bearer ' . $token), 'revoked token must not authenticate');
		$catalog->softDelete((int)$item->getId(), 'admin');
	}
}
