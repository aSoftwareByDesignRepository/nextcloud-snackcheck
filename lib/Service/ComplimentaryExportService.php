<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\ConsumptionLogMapper;

/**
 * Complimentary usage CSV (US-OPP-V) — qty of free lines, anonymized by item.
 */
class ComplimentaryExportService
{
	public function __construct(
		private readonly ConsumptionLogMapper $logs,
		private readonly PeriodService $periods,
	) {
	}

	/**
	 * @return list<array{item_name:string,qty:int,period_label:string}>
	 */
	public function buildRows(int $periodId): array
	{
		$period = $this->periods->get($periodId);
		$byItem = [];
		foreach ($this->logs->findForPeriod($periodId, false) as $log) {
			if ((int)$log->getLineTotalCents() !== 0) {
				continue;
			}
			$name = $log->getItemNameSnap();
			if (!isset($byItem[$name])) {
				$byItem[$name] = 0;
			}
			$byItem[$name] += (int)$log->getQty();
		}
		$rows = [];
		foreach ($byItem as $name => $qty) {
			$rows[] = [
				'item_name' => $name,
				'qty' => $qty,
				'period_label' => $period->getLabel(),
			];
		}
		usort($rows, static fn ($a, $b) => $b['qty'] <=> $a['qty']);
		return $rows;
	}

	public function toCsv(int $periodId): string
	{
		$rows = $this->buildRows($periodId);
		$out = [];
		foreach ($rows as $row) {
			$out[] = [$row['item_name'], $row['qty'], $row['period_label']];
		}
		return CsvExportBuilder::build(['item_name', 'qty', 'period_label'], $out);
	}
}
