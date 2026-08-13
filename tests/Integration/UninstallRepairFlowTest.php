<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Integration;

use OCA\SnackCheck\Repair\UninstallRepairFlow;
use PHPUnit\Framework\TestCase;

class UninstallRepairFlowTest extends TestCase
{
	public function testDisableIsNotRemoval(): void
	{
		self::assertFalse(UninstallRepairFlow::isRemovalContext([
			['class' => 'OC\\App\\AppManager', 'function' => 'disableApp'],
		]));
	}

	public function testRemoveAppIsRemoval(): void
	{
		self::assertTrue(UninstallRepairFlow::isRemovalContext([
			['class' => 'OC\\Installer', 'function' => 'removeApp'],
		]));
	}
}
