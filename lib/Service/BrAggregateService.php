<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\CatalogItemMapper;
use OCA\SnackCheck\Db\ConsumptionLogMapper;

/**
 * Anonymized BR aggregate — category/item totals only (US-OPP-L).
 * Never includes user_id / display names. Safe under privacy mode.
 */
class BrAggregateService
{
	public function __construct(
		private readonly ConsumptionLogMapper $logs,
		private readonly CatalogItemMapper $catalog,
		private readonly PeriodService $periods,
	) {
	}

	/**
	 * @return array{
	 *   periodLabel:string,
	 *   byCategory: list<array{category:string,qty:int,eurCents:int}>,
	 *   byItem: list<array{itemName:string,category:string,qty:int,eurCents:int}>
	 * }
	 */
	public function buildForPeriod(int $periodId): array
	{
		$period = $this->periods->get($periodId);
		$catById = [];
		$byItem = [];
		foreach ($this->logs->findForPeriod($periodId, false) as $log) {
			$itemId = (int)($log->getItemId() ?? 0);
			$name = $log->getItemNameSnap();
			$cat = 'other';
			if ($itemId > 0) {
				if (!isset($catById[$itemId])) {
					$entity = $this->catalog->find($itemId);
					$catById[$itemId] = $entity !== null
						? [(string)($entity->getCategory() ?: 'other'), $entity->getName()]
						: ['other', $name];
				}
				$cat = $catById[$itemId][0];
				$name = $catById[$itemId][1];
			}
			$key = $itemId > 0 ? ('id:' . $itemId) : ('n:' . $name);
			if (!isset($byItem[$key])) {
				$byItem[$key] = [
					'itemName' => $name,
					'category' => $cat,
					'qty' => 0,
					'eurCents' => 0,
				];
			}
			$byItem[$key]['qty'] += (int)$log->getQty();
			$byItem[$key]['eurCents'] += (int)$log->getLineTotalCents();
		}
		$byCategory = [];
		foreach ($byItem as $row) {
			$c = $row['category'] !== '' ? $row['category'] : 'other';
			if (!isset($byCategory[$c])) {
				$byCategory[$c] = ['category' => $c, 'qty' => 0, 'eurCents' => 0];
			}
			$byCategory[$c]['qty'] += $row['qty'];
			$byCategory[$c]['eurCents'] += $row['eurCents'];
		}
		$items = array_values($byItem);
		usort($items, static fn ($a, $b) => $b['qty'] <=> $a['qty']);
		$cats = array_values($byCategory);
		usort($cats, static fn ($a, $b) => $b['qty'] <=> $a['qty']);
		return [
			'periodLabel' => $period->getLabel(),
			'byCategory' => $cats,
			'byItem' => $items,
		];
	}

	/** @return array{periodLabel:string,byCategory:list<array{category:string,qty:int,eurCents:int}>,byItem:list<array{itemName:string,category:string,qty:int,eurCents:int}>} */
	public function buildForOpenPeriod(): array
	{
		$period = $this->periods->findOpen() ?? $this->periods->findLatestClosed();
		if ($period === null) {
			return ['periodLabel' => '—', 'byCategory' => [], 'byItem' => []];
		}
		return $this->buildForPeriod((int)$period->getId());
	}

	/** @return list<string> column names — used by anonymity tests */
	public static function forbiddenColumns(): array
	{
		return ['user_id', 'userId', 'user_display_name', 'actor_uid', 'logged_by'];
	}
}
