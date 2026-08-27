<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use OCA\SnackCheck\Controller\DeviceApiController;
use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\Site;
use OCA\SnackCheck\Db\TerminalDevice;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\CatalogImageService;
use OCA\SnackCheck\Service\CatalogService;
use OCA\SnackCheck\Service\ConsumptionLogService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\PeriodService;
use OCA\SnackCheck\Service\RateLimitService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\SiteService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use OCA\SnackCheck\Service\UnlockService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests: device JSON shapes must match mobile/snackcheck-kiosk TypeScript types.
 */
final class DeviceApiContractTest extends TestCase
{
	public function testCatalogReturnsItemsEnvelopeAndEtag(): void
	{
		$device = $this->device(7, 3);
		$item = new CatalogItem();
		$item->setId(11);
		$item->setName('Kaffee');
		$item->setPriceCents(50);
		$item->setCategory('drink');
		$item->setActive(1);
		$item->setTagsJson('["milk"]');

		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$catalog = $this->createMock(CatalogService::class);
		$catalog->method('listActive')->with(3)->willReturn([$item]);

		$ctrl = $this->controller(
			terminals: $terminals,
			license: $license,
			catalog: $catalog,
		);
		$res = $ctrl->catalog();
		self::assertSame(Http::STATUS_OK, $res->getStatus());
		$data = $res->getData();
		self::assertArrayHasKey('items', $data);
		self::assertArrayHasKey('catalogVersion', $data);
		self::assertSame(11, $data['items'][0]['id']);
		self::assertSame(['milk'], $data['items'][0]['allergenTags']);
		self::assertTrue($data['items'][0]['active']);
		self::assertFalse($data['items'][0]['hasImage']);
		self::assertSame(
			\OCA\SnackCheck\Controller\DeviceApiController::catalogVersionToken([$item]),
			(string)$data['catalogVersion']
		);
		self::assertNotSame('1', (string)$data['catalogVersion']);
	}

	public function testCatalogVersionChangesWhenPriceChangesNotOnlyCount(): void
	{
		$a = new CatalogItem();
		$a->setId(1);
		$a->setName('Cola');
		$a->setPriceCents(100);
		$a->setCategory('drink');
		$a->setActive(1);
		$b = new CatalogItem();
		$b->setId(1);
		$b->setName('Cola');
		$b->setPriceCents(120);
		$b->setCategory('drink');
		$b->setActive(1);
		self::assertNotSame(
			DeviceApiController::catalogVersionToken([$a]),
			DeviceApiController::catalogVersionToken([$b])
		);
	}

	public function testUnlockIncludesKioskFields(): void
	{
		$device = $this->device(1, 2);
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$unlock = $this->createMock(UnlockService::class);
		$unlock->method('verify')->willReturn([
			'unlockToken' => 'tok',
			'userId' => 'alice',
			'userDisplayName' => 'Alice',
			'expiresAt' => '2030-01-01T00:00:00+00:00',
			'isKitchenAdmin' => true,
			'hospitalityAllowed' => true,
		]);
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->method('quickTotalCentsForUser')->willReturn(150);
		$rate = $this->createMock(RateLimitService::class);

		$ctrl = $this->controller(
			terminals: $terminals,
			license: $license,
			unlock: $unlock,
			logs: $logs,
			rateLimit: $rate,
		);
		$res = $ctrl->unlockVerify();
		$data = $res->getData();
		self::assertSame('alice', $data['userId']);
		self::assertTrue($data['canHospitality']);
		self::assertSame(150, $data['quickTotalCents']);
		self::assertTrue($data['isKitchenAdmin']);
	}

	public function testMissingDeviceReturnsFlatCode(): void
	{
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn(null);
		$ctrl = $this->controller(terminals: $terminals);
		$res = $ctrl->heartbeat();
		self::assertSame(401, $res->getStatus());
		$data = $res->getData();
		self::assertSame('no_device', $data['code']);
	}

	public function testLicenseRequiredIs402(): void
	{
		$device = $this->device(1, 1);
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(false);
		$ctrl = $this->controller(terminals: $terminals, license: $license);
		$res = $ctrl->heartbeat();
		self::assertSame(402, $res->getStatus());
		self::assertSame('license_required', $res->getData()['code']);
	}

	public function testColleaguesRequiresKitchenAdmin(): void
	{
		$device = $this->device(1, 1);
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$unlock = $this->createMock(UnlockService::class);
		$unlock->method('peekUnlockToken')->willReturn([
			'userId' => 'alice',
			'isKitchenAdmin' => true, // stale session flag must not grant access
			'hospitalityAllowed' => false,
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('alice')->willReturn(false);
		$access->method('canManageSite')->with('alice', 1)->willReturn(false);
		$access->method('canAccessApp')->willReturn(true);
		$ctrl = $this->controller(terminals: $terminals, license: $license, unlock: $unlock, access: $access);
		$res = $ctrl->colleagues();
		self::assertSame(403, $res->getStatus());
		self::assertSame('permission_denied', $res->getData()['code']);
	}

	public function testColleaguesRejectsWhenLiveAccessRevoked(): void
	{
		$device = $this->device(1, 1);
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$unlock = $this->createMock(UnlockService::class);
		$unlock->method('peekUnlockToken')->willReturn([
			'userId' => 'alice',
			'isKitchenAdmin' => true,
			'hospitalityAllowed' => false,
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->with('alice')->willReturn(false);
		$access->expects($this->never())->method('canManageSite');
		$ctrl = $this->controller(terminals: $terminals, license: $license, unlock: $unlock, access: $access);
		$res = $ctrl->colleagues();
		self::assertSame(401, $res->getStatus());
		self::assertSame('unlock_invalid', $res->getData()['code']);
	}

	public function testLockSessionBindsInvalidateToDevice(): void
	{
		$device = $this->device(42, 1);
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$unlock = $this->createMock(UnlockService::class);
		$unlock->expects($this->once())->method('invalidateUnlockToken')
			->with('snkunlock_abc', '42');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function (string $h): string {
			return $h === 'Authorization' ? 'Bearer snkterm_x' : '';
		});
		$request->method('getParam')->willReturn('snkunlock_abc');
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$ctrl = $this->controller(terminals: $terminals, license: $license, unlock: $unlock, request: $request);
		$res = $ctrl->lockSession();
		self::assertSame(200, $res->getStatus());
		self::assertTrue($res->getData()['ok']);
	}

	public function testCreateLogRecomputesLiveKitchenAdminNotSessionCache(): void
	{
		$device = $this->device(1, 9);
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$unlock = $this->createMock(UnlockService::class);
		$unlock->method('peekUnlockToken')->willReturn([
			'userId' => 'alice',
			'isKitchenAdmin' => true, // revoked in live ACL
			'hospitalityAllowed' => false,
		]);
		$access = $this->createMock(AccessControlService::class);
		$access->method('isAppAdmin')->with('alice')->willReturn(false);
		$access->method('canManageSite')->with('alice', 9)->willReturn(false);
		$access->method('canAccessApp')->with('alice')->willReturn(true);
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->expects($this->once())->method('create')->with($this->callback(
			static function (array $input): bool {
				return ($input['isKitchenAdmin'] ?? null) === false
					&& ($input['actorUserId'] ?? null) === 'alice';
			}
		))->willReturn([
			'log' => $this->sampleLog(),
			'replay' => false,
			'httpStatus' => 201,
		]);
		$rate = $this->createMock(RateLimitService::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function (string $h): string {
			return match ($h) {
				'Authorization' => 'Bearer snkterm_x',
				'Idempotency-Key' => 'idem-1',
				default => '',
			};
		});
		$request->method('getParam')->willReturnCallback(static function (string $k) {
			return match ($k) {
				'itemId' => 11,
				'qty' => 1,
				'mode' => 'self',
				'unlockToken' => 'tok',
				default => null,
			};
		});
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$ctrl = $this->controller(
			terminals: $terminals,
			license: $license,
			unlock: $unlock,
			logs: $logs,
			access: $access,
			rateLimit: $rate,
			request: $request,
		);
		$res = $ctrl->createLog();
		self::assertSame(201, $res->getStatus());
	}

	public function testInactiveSiteDeviceAuthIsClosedOracle(): void
	{
		$device = $this->device(1, 99);
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->method('resolveToken')->willReturn($device);
		$terminals->method('getDeviceLimit')->willReturn(10);
		$terminals->method('getActiveCount')->willReturn(1);
		$license = $this->createMock(LicenseService::class);
		$license->method('isTerminalPlanActive')->willReturn(true);
		$sites = $this->createMock(SiteService::class);
		$sites->method('get')->willThrowException(new DomainException('not_found', 'Site not found', 404));
		$ctrl = $this->controller(terminals: $terminals, license: $license, sites: $sites);
		$res = $ctrl->heartbeat();
		self::assertSame(401, $res->getStatus());
		self::assertSame('no_device', $res->getData()['code']);
	}

	public function testCreateLogRejectsWhenLiveAccessRevoked(): void
	{
		$device = $this->device(1, 2);
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
		$access = $this->createMock(AccessControlService::class);
		$access->method('canAccessApp')->with('alice')->willReturn(false);
		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->expects($this->never())->method('create');
		$rate = $this->createMock(RateLimitService::class);
		$rate->expects($this->never())->method('assertUserLog');
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function (string $h): string {
			return match ($h) {
				'Authorization' => 'Bearer snkterm_x',
				'Idempotency-Key' => 'idem-1',
				default => '',
			};
		});
		$request->method('getParam')->willReturnCallback(static function (string $k) {
			return match ($k) {
				'itemId' => 11,
				'qty' => 1,
				'mode' => 'self',
				'unlockToken' => 'tok',
				default => null,
			};
		});
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		$ctrl = $this->controller(
			terminals: $terminals,
			license: $license,
			unlock: $unlock,
			logs: $logs,
			access: $access,
			rateLimit: $rate,
			request: $request,
		);
		$res = $ctrl->createLog();
		self::assertSame(401, $res->getStatus());
		self::assertSame('unlock_invalid', $res->getData()['code']);
	}

	/** NN-13: device createLog attributes from unlock session only — never client userId. */
	public function testCreateLogIgnoresSpoofedClientUserId(): void
	{
		$device = $this->device(5, 2);
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
		$log = new \OCA\SnackCheck\Db\ConsumptionLog();
		$log->setId(9);
		$log->setItemId(11);
		$log->setItemNameSnap('Cola');
		$log->setQty(1);
		$log->setLineTotalCents(100);
		$log->setCreatedAt(new \DateTime('2026-08-10T12:00:00+00:00'));

		$logs = $this->createMock(ConsumptionLogService::class);
		$logs->expects($this->once())->method('create')->with($this->callback(static function (array $in): bool {
			return ($in['actorUserId'] ?? null) === 'alice'
				&& !array_key_exists('userId', $in)
				&& ($in['itemId'] ?? null) === 11
				&& ($in['qty'] ?? null) === 1;
		}))->willReturn(['log' => $log, 'replay' => false, 'httpStatus' => 201]);

		$rate = $this->createMock(RateLimitService::class);
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static function (string $h): string {
			return match ($h) {
				'Authorization' => 'Bearer snkterm_x',
				'Idempotency-Key' => 'idem-1',
				default => '',
			};
		});
		$request->method('getParam')->willReturnCallback(static function (string $key) {
			return match ($key) {
				'unlockToken' => 'tok',
				'itemId' => 11,
				'qty' => 1,
				'mode' => 'self',
				// Spoofed identity — must be ignored by controller.
				'userId' => 'attacker',
				default => null,
			};
		});
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		$ctrl = $this->controller(
			terminals: $terminals,
			license: $license,
			unlock: $unlock,
			logs: $logs,
			rateLimit: $rate,
			request: $request,
		);
		$res = $ctrl->createLog();
		self::assertSame(201, $res->getStatus());
		self::assertSame(9, $res->getData()['id']);
	}

	public function testCreateLogSourceNeverReadsBodyUserId(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/DeviceApiController.php');
		$createPos = strpos($src, 'function createLog');
		self::assertNotFalse($createPos);
		$chunk = substr($src, $createPos, 1800);
		self::assertStringContainsString("'actorUserId' => \$session['userId']", $chunk);
		self::assertStringContainsString('isLiveKitchenAdmin', $chunk);
		self::assertStringNotContainsString("\$body['userId']", $chunk);
		self::assertStringNotContainsString("getParam('userId')", $chunk);
	}

	private function sampleLog(): \OCA\SnackCheck\Db\ConsumptionLog
	{
		$log = new \OCA\SnackCheck\Db\ConsumptionLog();
		$log->setId(9);
		$log->setItemId(11);
		$log->setItemNameSnap('Cola');
		$log->setQty(1);
		$log->setLineTotalCents(100);
		$log->setCreatedAt(new \DateTime('2026-08-10T12:00:00+00:00'));
		return $log;
	}

	private function device(int $id, int $siteId): TerminalDevice
	{
		$d = new TerminalDevice();
		$d->setId($id);
		$d->setSiteId($siteId);
		$d->setLabel('Kitchen');
		return $d;
	}

	private function controller(
		?TerminalDeviceService $terminals = null,
		?LicenseService $license = null,
		?CatalogService $catalog = null,
		?PeriodService $periods = null,
		?SiteService $sites = null,
		?UnlockService $unlock = null,
		?ConsumptionLogService $logs = null,
		?SettingsService $settings = null,
		?AccessControlService $access = null,
		?RateLimitService $rateLimit = null,
		?IUserManager $users = null,
		?IRequest $request = null,
	): DeviceApiController {
		if ($request === null) {
			$request = $this->createMock(IRequest::class);
			$request->method('getHeader')->willReturn('Bearer snkterm_x');
			$request->method('getParam')->willReturn(null);
			$request->method('getRemoteAddress')->willReturn('127.0.0.1');
		}

		$period = new Period();
		$period->setId(9);
		$period->setLabel('2026-08');
		$period->setState('open');
		$periods ??= $this->createMock(PeriodService::class);
		$periods->method('findOpen')->willReturn($period);
		$periods->method('findLatestClosed')->willReturn(null);
		$periods->method('ensureOpenPeriod')->willReturn($period);

		$site = new Site();
		$site->setCode('DEFAULT');
		$site->setName('Default');
		$sites ??= $this->createMock(SiteService::class);
		$sites->method('get')->willReturn($site);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10T12:00:00+00:00'));

		$license ??= $this->createMock(LicenseService::class);
		$license->method('buildEnvelope')->willReturn([
			'format' => 'SNK2',
			'payloadB64' => 'p',
			'signatureB64' => 's',
		]);
		$license->method('isTerminalPlanActive')->willReturn(true);

		if ($access === null) {
			$access = $this->createMock(AccessControlService::class);
			$access->method('canAccessApp')->willReturn(true);
		}

		return new DeviceApiController(
			'snackcheck',
			$request,
			$terminals ?? $this->createMock(TerminalDeviceService::class),
			$license,
			$catalog ?? $this->createMock(CatalogService::class),
			$this->createMock(CatalogImageService::class),
			$periods,
			$sites,
			$unlock ?? $this->createMock(UnlockService::class),
			$logs ?? $this->createMock(ConsumptionLogService::class),
			$settings ?? $this->createMock(SettingsService::class),
			$access,
			$rateLimit ?? $this->createMock(RateLimitService::class),
			$users ?? $this->createMock(IUserManager::class),
			$time,
		);
	}
}
