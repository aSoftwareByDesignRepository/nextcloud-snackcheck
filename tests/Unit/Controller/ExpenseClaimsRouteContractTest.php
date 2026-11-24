<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * F-SN2 route contract: claim routing must key on routingReady (capability
 * AND resolved category/cost-center mapping) — a bare capability check would
 * submit claims the mapping cannot satisfy, and a missing mapping check in
 * the write gate would silently write ledger rows the admin believes are
 * claim drafts.
 */
final class ExpenseClaimsRouteContractTest extends TestCase
{
	private string $api;
	private string $device;
	private string $page;

	protected function setUp(): void
	{
		$root = dirname(__DIR__, 3);
		$this->api = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
		$this->device = (string)file_get_contents($root . '/lib/Controller/DeviceApiController.php');
		$this->page = (string)file_get_contents($root . '/lib/Controller/PageController.php');
	}

	public function testRouteExpenseClaimStillVerifiesReadiness(): void
	{
		// The write gate keys on stored routing; routeExpenseClaim then
		// verifies live readiness before touching EXP — both halves exist.
		self::assertStringContainsString('expenseClaimRoutingReady()', $this->api);
		$pos = strpos($this->api, 'function routeExpenseClaim');
		self::assertNotFalse($pos);
		self::assertStringContainsString('routingReady', substr($this->api, $pos, 2500));
	}

	public function testRouteFailsUniformWhenBridgeAbsentOrNotReady(): void
	{
		$pos = strpos($this->api, 'function routeExpenseClaim');
		self::assertNotFalse($pos);
		$body = substr($this->api, $pos, 3000);
		self::assertStringContainsString("'expense_route_unavailable'", $body);
		self::assertStringContainsString('409', $body);
		self::assertStringContainsString('routingReady', $body);
	}

	public function testSourceRefIsDeterministicPerIdempotencyKey(): void
	{
		// Resubmit/double-click must reproduce the same source_ref so EXP
		// request_key + line-uniqueness dedupe collapses the retry.
		self::assertStringContainsString("'snkexp:'", $this->api);
		self::assertStringContainsString('Idempotency-Key', $this->api);
		self::assertStringContainsString('submitDraft(', $this->api);
		// No key, no write — identical contract to the ledger path. A generated
		// fallback ref would mint a fresh claim per key-less retry.
		$pos = strpos($this->api, 'function routeExpenseClaim');
		self::assertNotFalse($pos);
		$body = substr($this->api, $pos, 3000);
		self::assertStringContainsString("'Idempotency key required'", $body);
		self::assertStringNotContainsString('random_bytes', $body);
	}

	public function testSettingsSaveAcceptsMappingIds(): void
	{
		self::assertStringContainsString("'expenseClaimCategoryId'", $this->api);
		self::assertStringContainsString("'expenseClaimCostCenterId'", $this->api);
		self::assertStringContainsString('setExpenseClaimCategoryId', $this->api);
		self::assertStringContainsString('setExpenseClaimCostCenterId', $this->api);
		self::assertStringContainsString('parseNonNegativeId', $this->api);
	}

	public function testWriteGateUsesStoredRoutingNeverEffective(): void
	{
		// INT-05: a degraded claim route must hard-fail, not silently credit.
		// The submit branch therefore keys on getExpenseRoutingStored() —
		// the effective getter would degrade to ledger and lie about the
		// destination of the member's money.
		$pos = strpos($this->api, 'routeExpenseClaim($user');
		self::assertNotFalse($pos);
		$gate = substr($this->api, max(0, $pos - 500), 500);
		self::assertStringContainsString('getExpenseRoutingStored()', $gate);
		self::assertStringNotContainsString('getExpenseRouting(', $gate);
	}

	public function testDeviceSurfaceKeysOnStoredRouting(): void
	{
		// NN-15 + INT-05: a terminal can never mint an EXP claim — stored
		// claim routing hides the kiosk expense lane entirely, including a
		// degraded route (effective getter would reopen it silently).
		self::assertStringContainsString('getExpenseRoutingStored()', $this->device);
		self::assertStringNotContainsString(
			'getExpenseRouting($this->claimsBridge?->capabilityPresent()',
			$this->device,
		);
	}

	public function testPageSurfacesTrackReadinessAndStoredChoice(): void
	{
		// The dialog hint mirrors the stored promise (the honest 409 reads
		// right); the settings payload reports the degraded effective value.
		self::assertStringContainsString('expenseClaimRoutingReady()', $this->page);
		self::assertStringContainsString('getExpenseRoutingStored()', $this->page);
		self::assertStringContainsString('expenseRoutingStored', $this->page);
		self::assertStringNotContainsString(
			'getExpenseRouting($this->claimsBridge?->capabilityPresent()',
			$this->page,
		);
	}
}
