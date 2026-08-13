<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\CatalogItem;
use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;

class PulseService
{
	public function __construct(
		private readonly ConsumptionLogMapper $logs,
		private readonly CatalogItemMapper $catalog,
		private readonly SettingsService $settings,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Pure formula helpers (unit-testable without DB).
	 */
	public static function avgPerDay(int $qtyInWindow, int $daysWithData): float
	{
		return $qtyInWindow / max(1, $daysWithData);
	}

	public static function daysLeft(?int $onHand, float $avgPerDay): ?float
	{
		if ($onHand === null || $avgPerDay <= 0.0) {
			return null;
		}
		return $onHand / $avgPerDay;
	}

	/**
	 * Top-up = (par AND on_hand set) AND (days_left ≤ horizon OR on_hand ≤ par)
	 * If avg==0 AND on_hand ≤ par → true
	 * If par OR on_hand null → false
	 */
	public static function needsTopUp(?int $parLevel, ?int $onHand, float $avgPerDay, int $horizonDays): bool
	{
		if ($parLevel === null || $onHand === null) {
			return false;
		}
		if ($avgPerDay <= 0.0) {
			return $onHand <= $parLevel;
		}
		$daysLeft = self::daysLeft($onHand, $avgPerDay);
		if ($daysLeft === null) {
			return false;
		}
		return $daysLeft <= $horizonDays || $onHand <= $parLevel;
	}

	public static function suggestedBuy(?int $parLevel, ?int $onHand): ?int
	{
		if ($parLevel === null || $onHand === null) {
			return null;
		}
		return max(0, $parLevel - $onHand);
	}

	/**
	 * @return array{ranks: list<array<string,mixed>>, topUp: list<array<string,mixed>>, shoppingList: list<array<string,mixed>>}
	 */
	public function buildForSite(int $siteId, ?string $category = null): array
	{
		$window = $this->settings->getPaceWindowDays();
		$horizon = $this->settings->getRestockHorizonDays();
		$since = $this->timeFactory->getDateTime()->modify('-' . $window . ' days');
		$logs = $this->logs->findSince($since, $siteId);
		$category = $category !== null ? strtolower(trim($category)) : null;
		if ($category === '' || $category === 'all') {
			$category = null;
		}

		$items = $this->catalog->findActiveBySite($siteId);
		$itemMeta = [];
		foreach ($items as $item) {
			/** @var CatalogItem $item */
			$itemMeta[(int)$item->getId()] = [
				'category' => (string)$item->getCategory(),
				'item' => $item,
			];
		}

		$byItem = [];
		$daysSeen = [];
		foreach ($logs as $log) {
			/** @var ConsumptionLog $log */
			$itemId = $log->getItemId() ?? 0;
			if ($category !== null) {
				$cat = $itemId > 0 ? ($itemMeta[$itemId]['category'] ?? '') : '';
				if ($cat !== $category) {
					continue;
				}
			}
			$key = $itemId > 0 ? (string)$itemId : $log->getItemNameSnap();
			if (!isset($byItem[$key])) {
				$byItem[$key] = [
					'itemId' => $itemId > 0 ? $itemId : null,
					'name' => $log->getItemNameSnap(),
					'qty' => 0,
					'eurCents' => 0,
				];
			}
			$byItem[$key]['qty'] += (int)$log->getQty();
			$byItem[$key]['eurCents'] += (int)$log->getLineTotalCents();
			$day = $log->getCreatedAt()?->format('Y-m-d') ?? '';
			if ($day !== '') {
				$daysSeen[$day] = true;
			}
		}
		$daysWithData = max(1, count($daysSeen));
		$totalQty = array_sum(array_column($byItem, 'qty'));
		$totalEur = 0;
		foreach ($byItem as $row) {
			$totalEur += (int)$row['eurCents'];
		}

		$ranks = [];
		foreach ($byItem as $row) {
			$avg = self::avgPerDay((int)$row['qty'], $daysWithData);
			$ranks[] = [
				'itemId' => $row['itemId'],
				'name' => $row['name'],
				'qty' => $row['qty'],
				'eurCents' => $row['eurCents'],
				'qtySharePct' => $totalQty > 0 ? round(100.0 * $row['qty'] / $totalQty, 1) : 0.0,
				'eurSharePct' => $totalEur > 0 ? round(100.0 * $row['eurCents'] / $totalEur, 1) : 0.0,
				'avgPerDay' => round($avg, 3),
			];
		}
		usort($ranks, static fn ($a, $b) => $b['qty'] <=> $a['qty']);

		$topUp = [];
		$shopping = [];
		foreach ($items as $item) {
			/** @var CatalogItem $item */
			if ($category !== null && (string)$item->getCategory() !== $category) {
				continue;
			}
			$qty = 0;
			foreach ($byItem as $row) {
				if ($row['itemId'] === $item->getId()) {
					$qty = (int)$row['qty'];
					break;
				}
			}
			$avg = self::avgPerDay($qty, $daysWithData);
			$par = $item->getParLevel();
			$onHand = $item->getOnHand();
			$parI = $par === null ? null : (int)$par;
			$onI = $onHand === null ? null : (int)$onHand;
			$flag = self::needsTopUp($parI, $onI, $avg, $horizon);
			$buy = self::suggestedBuy($parI, $onI);
			$row = [
				'itemId' => $item->getId(),
				'name' => $item->getName(),
				'category' => $item->getCategory(),
				'onHand' => $onI,
				'parLevel' => $parI,
				'avgPerDay' => round($avg, 3),
				'daysLeft' => self::daysLeft($onI, $avg),
				'topUp' => $flag,
				'suggestedBuy' => $buy,
				'complimentary' => ((int)$item->getPriceCents()) === 0,
			];
			if ($flag) {
				$topUp[] = $row;
			}
			if ($buy !== null && $buy > 0) {
				$shopping[] = $row;
			}
		}

		return ['ranks' => $ranks, 'topUp' => $topUp, 'shoppingList' => $shopping];
	}
}
