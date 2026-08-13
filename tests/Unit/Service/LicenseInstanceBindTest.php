<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Config\InstanceId;
use OCA\SnackCheck\Db\LicenseState;
use OCA\SnackCheck\Db\LicenseStateMapper;
use OCA\SnackCheck\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Argus: bound_instance_id must be enforced — copied license rows must not unlock tablets. */
final class LicenseInstanceBindTest extends TestCase
{
	protected function setUp(): void
	{
		$path = __DIR__ . '/../../fixtures/license_snk2_golden.json';
		$data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
		putenv('SNK_VENDOR_PUBLIC_KEY_B64=' . $data['publicKeyB64']);
		putenv('SNK_ALLOW_VENDOR_KEY_OVERRIDE=1');
	}

	private function goldenState(string $boundInstanceId): LicenseState
	{
		$path = __DIR__ . '/../../fixtures/license_snk2_golden.json';
		$data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
		$state = new LicenseState();
		$state->setCustomerId('test');
		$state->setValidUntil(new \DateTime('2027-12-31'));
		$state->setMobileSeats(0);
		$state->setTerminalDevices(1);
		$state->setBundle(0);
		$state->setKeyAppliedAt(new \DateTime('2026-01-01'));
		$state->setPayloadB64($data['payloadB64']);
		$state->setSignatureB64($data['signatureB64']);
		$state->setLicenseFingerprint('fp');
		$state->setBoundInstanceId($boundInstanceId);
		return $state;
	}

	private function service(LicenseStateMapper $mapper, string $instanceId): LicenseService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10'));
		$instance = $this->createMock(InstanceId::class);
		$instance->method('get')->willReturn($instanceId);
		$locking = $this->createMock(ILockingProvider::class);
		return new LicenseService(
			$mapper,
			$time,
			$this->createMock(LoggerInterface::class),
			$instance,
			$locking,
		);
	}

	public function testWrongInstanceDisablesTerminalPlan(): void
	{
		$mapper = $this->createMock(LicenseStateMapper::class);
		$mapper->method('findCurrent')->willReturn($this->goldenState('other-instance'));
		$svc = $this->service($mapper, 'this-instance');
		self::assertFalse($svc->isTerminalPlanActive());
		self::assertSame(0, $svc->getTerminalDeviceLimit());
		$summary = $svc->getLicenseSummary();
		self::assertNotNull($summary);
		self::assertFalse($summary['active']);
		self::assertFalse($summary['instanceValid']);
		self::assertTrue($summary['dateValid']);
		self::assertTrue($summary['cryptographicallyValid']);
	}

	public function testMatchingInstanceKeepsTerminalPlan(): void
	{
		$mapper = $this->createMock(LicenseStateMapper::class);
		$mapper->method('findCurrent')->willReturn($this->goldenState('this-instance'));
		$svc = $this->service($mapper, 'this-instance');
		self::assertTrue($svc->isTerminalPlanActive());
		self::assertSame(1, $svc->getTerminalDeviceLimit());
		$summary = $svc->getLicenseSummary();
		self::assertTrue($summary['active']);
		self::assertTrue($summary['instanceValid']);
	}

	public function testEmptyBoundInstanceFailsClosed(): void
	{
		$mapper = $this->createMock(LicenseStateMapper::class);
		$mapper->method('findCurrent')->willReturn($this->goldenState(''));
		$svc = $this->service($mapper, 'this-instance');
		self::assertFalse($svc->isTerminalPlanActive());
		$summary = $svc->getLicenseSummary();
		self::assertFalse($summary['instanceValid']);
		self::assertFalse($summary['active']);
	}

	public function testApplyStoresCurrentInstanceId(): void
	{
		$path = __DIR__ . '/../../fixtures/license_snk2_golden.json';
		$data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
		$mapper = $this->createMock(LicenseStateMapper::class);
		$mapper->expects($this->once())->method('upsert')->with($this->callback(
			static function (LicenseState $state): bool {
				return $state->getBoundInstanceId() === 'prod-abc';
			}
		));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10'));
		$instance = $this->createMock(InstanceId::class);
		$instance->method('get')->willReturn('prod-abc');
		$locking = $this->createMock(ILockingProvider::class);
		$svc = new LicenseService(
			$mapper,
			$time,
			$this->createMock(LoggerInterface::class),
			$instance,
			$locking,
		);
		self::assertTrue($svc->applyLicenseKey($data['wireKey']));
	}
}
