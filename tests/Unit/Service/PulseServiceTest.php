<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Service;

use OCA\SnackCheck\Service\PulseService;
use PHPUnit\Framework\TestCase;

class PulseServiceTest extends TestCase
{
	public function testAvgPerDay(): void
	{
		self::assertSame(2.0, PulseService::avgPerDay(10, 5));
		self::assertSame(10.0, PulseService::avgPerDay(10, 0)); // max(1,days)
	}

	public function testTopUpRequiresParAndOnHand(): void
	{
		self::assertFalse(PulseService::needsTopUp(null, 5, 1.0, 3));
		self::assertFalse(PulseService::needsTopUp(10, null, 1.0, 3));
	}

	public function testTopUpWhenBelowParOrDaysLeft(): void
	{
		self::assertTrue(PulseService::needsTopUp(10, 5, 2.0, 3)); // on_hand <= par
		self::assertTrue(PulseService::needsTopUp(100, 6, 3.0, 3)); // days_left=2 <= 3
		self::assertFalse(PulseService::needsTopUp(10, 20, 1.0, 3)); // plenty
	}

	public function testTopUpWhenNoPace(): void
	{
		self::assertTrue(PulseService::needsTopUp(10, 5, 0.0, 3));
		self::assertFalse(PulseService::needsTopUp(10, 15, 0.0, 3));
	}

	public function testSuggestedBuy(): void
	{
		self::assertSame(7, PulseService::suggestedBuy(10, 3));
		self::assertSame(0, PulseService::suggestedBuy(5, 10));
		self::assertNull(PulseService::suggestedBuy(null, 3));
	}

	public function testBuildForSiteFiltersRanksByCategory(): void
	{
		$drink = new \OCA\SnackCheck\Db\CatalogItem();
		$drink->setId(1);
		$drink->setName('Cola');
		$drink->setCategory('drink');
		$drink->setPriceCents(100);
		$drink->setActive(1);
		$snack = new \OCA\SnackCheck\Db\CatalogItem();
		$snack->setId(2);
		$snack->setName('Chips');
		$snack->setCategory('snack');
		$snack->setPriceCents(80);
		$snack->setActive(1);
		$snack->setParLevel(10);
		$snack->setOnHand(2);

		$logDrink = new \OCA\SnackCheck\Db\ConsumptionLog();
		$logDrink->setItemId(1);
		$logDrink->setItemNameSnap('Cola');
		$logDrink->setQty(5);
		$logDrink->setLineTotalCents(500);
		$logDrink->setCreatedAt(new \DateTime('2026-08-09'));
		$logSnack = new \OCA\SnackCheck\Db\ConsumptionLog();
		$logSnack->setItemId(2);
		$logSnack->setItemNameSnap('Chips');
		$logSnack->setQty(3);
		$logSnack->setLineTotalCents(240);
		$logSnack->setCreatedAt(new \DateTime('2026-08-09'));

		$logs = $this->createMock(\OCA\SnackCheck\Db\ConsumptionLogMapper::class);
		$logs->method('findSince')->willReturn([$logDrink, $logSnack]);
		$catalog = $this->createMock(\OCA\SnackCheck\Db\CatalogItemMapper::class);
		$catalog->method('findActiveBySite')->willReturn([$drink, $snack]);
		$settings = $this->createMock(\OCA\SnackCheck\Service\SettingsService::class);
		$settings->method('getPaceWindowDays')->willReturn(7);
		$settings->method('getRestockHorizonDays')->willReturn(3);
		$time = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-10'));

		$svc = new PulseService($logs, $catalog, $settings, $time);
		$all = $svc->buildForSite(1);
		self::assertCount(2, $all['ranks']);
		$drinks = $svc->buildForSite(1, 'drink');
		self::assertCount(1, $drinks['ranks']);
		self::assertSame('Cola', $drinks['ranks'][0]['name']);
		self::assertCount(0, $drinks['shoppingList']);
		$snacks = $svc->buildForSite(1, 'snack');
		self::assertCount(1, $snacks['ranks']);
		self::assertNotEmpty($snacks['shoppingList']);
	}
}
