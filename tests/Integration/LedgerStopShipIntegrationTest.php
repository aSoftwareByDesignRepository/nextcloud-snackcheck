<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Integration;

use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\PayrollExportService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\PulseService;
use OCA\SnackCheck\Service\SiteService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Stop-ship ledger journey: log → pulse → close → export reconcile (CORE §10.2).
 */
final class LedgerStopShipIntegrationTest extends TestCase
{
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	public function testLogPulseClosePayrollReconcile(): void
	{
		if (!$this->db->tableExists('snk_catalog_items')) {
			self::markTestSkipped('SnackCheck tables not present');
		}

		$sites = \OC::$server->get(SiteService::class);
		$catalog = \OC::$server->get(CatalogService::class);
		$logs = \OC::$server->get(ConsumptionLogService::class);
		$periods = \OC::$server->get(PeriodService::class);
		$pulse = \OC::$server->get(PulseService::class);
		$payroll = \OC::$server->get(PayrollExportService::class);

		$site = $sites->ensureDefaultSite();
		$siteId = (int)$site->getId();
		$period = $periods->ensureOpenPeriod();

		$item = $catalog->create($siteId, 'StopShip Coffee ' . uniqid('', true), 150, 'admin', 'drink');
		$key = 'stopship-' . bin2hex(random_bytes(8));

		$created = $logs->create([
			'itemId' => (int)$item->getId(),
			'qty' => 2,
			'idempotencyKey' => $key,
			'siteId' => $siteId,
			'actorUserId' => 'admin',
			'source' => 'web',
			'mode' => 'self',
		]);
		self::assertFalse($created['replay']);
		self::assertSame(300, (int)$created['log']->getLineTotalCents());

		$pulseData = $pulse->buildForSite($siteId);
		self::assertIsArray($pulseData);
		self::assertArrayHasKey('topUp', $pulseData);

		$pkg = $payroll->buildPersonalPackage((int)$period->getId(), null);
		self::assertTrue($pkg['reconcileOk'], 'payroll must reconcile to zero delta');

		// Web never requires SNK2: creating another log without license still works via service path.
		$created2 = $logs->create([
			'itemId' => (int)$item->getId(),
			'qty' => 1,
			'idempotencyKey' => $key . '-b',
			'siteId' => $siteId,
			'actorUserId' => 'admin',
			'source' => 'web',
			'mode' => 'self',
		]);
		self::assertSame(201, $created2['httpStatus']);

		// AC-16: after close, writes stay locked until openNextPeriod.
		$closed = $periods->close((int)$period->getId(), 'admin', true);
		self::assertSame('closed', $closed['period']->getState());
		try {
			$logs->create([
				'itemId' => (int)$item->getId(),
				'qty' => 1,
				'idempotencyKey' => $key . '-after-close',
				'siteId' => $siteId,
				'actorUserId' => 'admin',
				'source' => 'web',
				'mode' => 'self',
			]);
			self::fail('expected period_closed after close');
		} catch (\OCA\SnackCheck\Exception\DomainException $e) {
			self::assertSame('period_closed', $e->errorCode);
			self::assertSame(409, $e->httpStatus);
		}
		$next = $periods->openNextPeriod('admin');
		self::assertSame('open', $next->getState());
		$created3 = $logs->create([
			'itemId' => (int)$item->getId(),
			'qty' => 1,
			'idempotencyKey' => $key . '-after-open',
			'siteId' => $siteId,
			'actorUserId' => 'admin',
			'source' => 'web',
			'mode' => 'self',
		]);
		self::assertSame(201, $created3['httpStatus']);
	}
}
