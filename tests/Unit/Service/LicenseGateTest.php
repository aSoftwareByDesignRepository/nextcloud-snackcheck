<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Config\InstanceId;
use OCA\SnackCheck\Db\LicenseStateMapper;
use OCA\SnackCheck\Exception\PaymentRequiredException;
use OCA\SnackCheck\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LicenseGateTest extends TestCase
{
	public function testRequireTerminalLicenseThrowsWhenUnlicensed(): void
	{
		$mapper = $this->createMock(LicenseStateMapper::class);
		$mapper->method('findCurrent')->willReturn(null);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10'));
		$svc = new LicenseService(
			$mapper,
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(InstanceId::class),
			$this->createMock(ILockingProvider::class),
		);
		$this->expectException(PaymentRequiredException::class);
		$svc->requireTerminalLicense();
	}

	public function testApplyBusyFailsClosed(): void
	{
		$path = __DIR__ . '/../../fixtures/license_snk2_golden.json';
		$data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
		putenv('SNK_VENDOR_PUBLIC_KEY_B64=' . $data['publicKeyB64']);
		putenv('SNK_ALLOW_VENDOR_KEY_OVERRIDE=1');

		$mapper = $this->createMock(LicenseStateMapper::class);
		$mapper->expects($this->never())->method('upsert');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10'));
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with('snackcheck/license_apply', ILockingProvider::LOCK_EXCLUSIVE)
			->willThrowException(new \OCP\Lock\LockedException('snackcheck/license_apply'));
		$locking->expects($this->never())->method('releaseLock');

		$svc = new LicenseService(
			$mapper,
			$time,
			$this->createMock(LoggerInterface::class),
			$this->createMock(InstanceId::class),
			$locking,
		);
		self::assertFalse($svc->applyLicenseKey($data['wireKey']));
		self::assertSame('license_busy', $svc->getLastApplyErrorCode());
	}

	public function testWebNeverUsesRequireTerminalLicenseInPageController(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/PageController.php');
		self::assertStringNotContainsString('requireTerminalLicense', $src);
		self::assertStringNotContainsString('PaymentRequiredException', $src);
		$device = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/DeviceApiController.php');
		self::assertStringContainsString('isTerminalPlanActive', $device);
		self::assertStringContainsString('PaymentRequiredException', $device);
	}
}
