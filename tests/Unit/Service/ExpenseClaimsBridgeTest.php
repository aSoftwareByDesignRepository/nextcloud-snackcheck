<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\ExpenseClaimsBridge;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * ExpenseClaimsBridge: capability detection must reflect the REAL shipped
 * EXP contract (the HTTP ingress controller class), not the speculative
 * in-process interface name the original probe looked for.
 */
final class ExpenseClaimsBridgeTest extends TestCase
{
	private function bridge(bool $enabled): ExpenseClaimsBridge
	{
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn($enabled);
		$url = $this->createMock(IURLGenerator::class);
		return new ExpenseClaimsBridge($appManager, $url);
	}

	public function testCapabilityAbsentWhenAppDisabled(): void
	{
		self::assertFalse($this->bridge(false)->capabilityPresent());
		self::assertFalse($this->bridge(false)->routingReady(106, 47));
	}

	public function testCapabilityFollowsTheRealIngressClass(): void
	{
		// EXP v1 ships its sibling contract as IngressApiController — the probe
		// must bind to that marker, never to a nonexistent Public\ interface.
		self::assertSame(
			class_exists(ExpenseClaimsBridge::INGRESS_MARKER),
			$this->bridge(true)->capabilityPresent(),
		);
	}

	public function testMappingAndRoutingRejectZeroIdsWithoutTouchingExp(): void
	{
		// Early false — never resolves EXP services for an unmapped config.
		$b = $this->bridge(true);
		self::assertFalse($b->mappingConfigured(0, 0));
		self::assertFalse($b->mappingConfigured(0, 5));
		self::assertFalse($b->mappingConfigured(5, 0));
		self::assertFalse($b->routingReady(0, 0));
	}

	public function testBridgeTargetsTheShippedExpServices(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/ExpenseClaimsBridge.php');
		self::assertStringContainsString('ClaimLifecycleService', $src);
		self::assertStringContainsString('ClaimLineService', $src);
		self::assertStringContainsString('ClaimMapper', $src);
		self::assertStringContainsString("':ingress:'", $src);
		self::assertStringContainsString("SOURCE_APP = 'snackcheck'", $src);
		self::assertStringContainsString('getRequiresAttendees', $src);
		// Contract-code surface: EXP errors collapse onto SNK codes only.
		self::assertStringContainsString("'validation_failed'", $src);
		self::assertStringContainsString("'expense_route_unavailable'", $src);
	}
}
