<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\LicenseEnforcementService;
use OCA\SnackCheck\Service\LicenseService;
use OCA\SnackCheck\Service\TerminalDeviceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LicenseEnforcementClearTest extends TestCase
{
	public function testClearCommercialStateRevokesThenClears(): void
	{
		$terminals = $this->createMock(TerminalDeviceService::class);
		$terminals->expects(self::once())->method('trimToLimit')->with(0)->willReturn(2);

		$license = $this->createMock(LicenseService::class);
		$license->expects(self::once())->method('clearLicense');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$svc = new LicenseEnforcementService($terminals, $license, $logger);
		$result = $svc->clearCommercialState();
		self::assertSame(['terminalsRevoked' => 2], $result);
	}
}
