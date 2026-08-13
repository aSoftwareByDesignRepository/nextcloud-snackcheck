<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use OCA\SnackCheck\Controller\DeviceApiController;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\TerminalDevice;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\RateLimitService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** CORE §9.7 + COMPANION §7.5: device API 120/min at auth + user 60/min on createLog. */
final class DeviceLogDualRateLimitContractTest extends TestCase
{
	public function testCreateLogAssertsDeviceApiAndUserBuckets(): void
	{
		$device = new TerminalDevice();
		$device->setId(7);
		$device->setSiteId(3);

		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);

		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);

		$unlock = $this->createMock(UnlockService::class);
		$unlock->method('peekUnlockToken')->willReturn([
			'userId' => 'alice',
			'isKitchenAdmin' => false,
			'hospitalityAllowed' => false,
		]);

		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->once())->method('assertDeviceApi')->with('7');
		$rate->expects($this->once())->method('assertUserLog')->with('alice');
		$rate->expects($this->never())->method('assertDeviceLog');

		$log = new \OCA\SnackCheck\Db\ConsumptionLog();
		$log->setId(1);
		$log->setItemId(11);
		$log->setItemNameSnap('Cola');
		$log->setQty(1);
		$log->setLineTotalCents(100);
		$log->setCreatedAt(new \DateTime('2026-08-10T12:00:00+00:00'));
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->method('create')->willReturn(['log' => $log, 'replay' => false, 'httpStatus' => 201]);

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function (string $h): string {
			return match ($h) {
				'Authorization' => 'Bearer snkterm_x',
				'Idempotency-Key' => 'idem',
				default => '',
			};
		});
		$request->method('getParam')->willReturnCallback(static function (string $key) {
			return match ($key) {
				'unlockToken' => 'tok',
				'itemId' => 11,
				'qty' => 1,
				'mode' => 'self',
				default => null,
			};
		});
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$period = new Period();
		$period->setId(1);
		$period->setLabel('2026-08');
		$period->setState('open');
		$periods = $this->createMock(PeriodService::class);
		$periods->method('findOpen')->willReturn($period);

		$site = new Site();
		$site->setCode('DEFAULT');
		$sites = $this->createMock(SiteService::class);
		$sites->method('get')->willReturn($site);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));

		$ctrl = new DeviceApiController(
			'snackcheck',
			$request,
			$terminals,
			$license,
			$this->createMock(CatalogService::class),
			$periods,
			$sites,
			$unlock,
			$logs,
			$this->createMock(SettingsService::class),
			$this->createMock(AccessControlService::class),
			$rate,
			$this->createMock(IUserManager::class),
			$time,
		);
		$res = $ctrl->createLog();
		self::assertSame(201, $res->getStatus());
	}

	public function testAuthAndCreateLogSourceContracts(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		self::assertMatchesRegularExpression(
			'/function authenticateDevice[\s\S]{0,900}assertDeviceApi/',
			$src
		);
		$pos = strpos($src, 'function createLog');
		self::assertNotFalse($pos);
		$chunk = substr($src, $pos, 900);
		self::assertStringContainsString('assertUserLog($session[\'userId\'])', $chunk);
		self::assertStringNotContainsString('assertDeviceLog', $chunk);
	}

	public function testBootstrapAlsoHitsDeviceApiBucket(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		self::assertStringContainsString('function bootstrap', $src);
		self::assertStringContainsString('authenticateDevice()', $src);
		$rl = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/RateLimitService.php');
		self::assertStringContainsString("hit('dapi:'", $rl);
		self::assertStringContainsString('DEVICE_API_LIMIT = 120', $rl);
	}
}
