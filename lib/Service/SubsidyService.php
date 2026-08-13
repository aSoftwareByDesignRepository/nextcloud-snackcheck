<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Employer subsidy math (integer cents only).
 *
 * G = Σ personal chargeable line_total (price > 0, not voided, billing_bucket=personal)
 * free lines never consume subsidy
 * subsidy_applied = min(A, G)
 * to_deduct = G − subsidy_applied
 */
class SubsidyService
{
	/**
	 * @param list<array{line_total_cents:int, billing_bucket?:string, voided?:bool}> $lines
	 * @return array{gross_cents:int, subsidy_cents:int, deduct_cents:int}
	 */
	public function computeForUser(int $allowanceCents, array $lines): array
	{
		$allowanceCents = max(0, $allowanceCents);
		$gross = 0;
		foreach ($lines as $line) {
			if (!empty($line['voided'])) {
				continue;
			}
			$bucket = $line['billing_bucket'] ?? 'personal';
			if ($bucket !== 'personal') {
				continue;
			}
			$total = (int)($line['line_total_cents'] ?? 0);
			if ($total <= 0) {
				continue; // complimentary / free never consume subsidy
			}
			$gross += $total;
		}
		$applied = min($allowanceCents, $gross);
		return [
			'gross_cents' => $gross,
			'subsidy_cents' => $applied,
			'deduct_cents' => $gross - $applied,
		];
	}

	/**
	 * Reconcile invariant: Σ deduct === Σ chargeable personal − Σ subsidy.
	 *
	 * @param list<array{gross_cents:int, subsidy_cents:int, deduct_cents:int}> $summaries
	 * @param list<array{line_total_cents:int, billing_bucket?:string, voided?:bool}> $allLines
	 */
	public function reconcileInvariant(array $summaries, array $allLines): bool
	{
		$sumDeduct = 0;
		$sumSubsidy = 0;
		foreach ($summaries as $s) {
			$sumDeduct += (int)$s['deduct_cents'];
			$sumSubsidy += (int)$s['subsidy_cents'];
		}
		$chargeable = 0;
		foreach ($allLines as $line) {
			if (!empty($line['voided'])) {
				continue;
			}
			if (($line['billing_bucket'] ?? 'personal') !== 'personal') {
				continue;
			}
			$total = (int)($line['line_total_cents'] ?? 0);
			if ($total > 0) {
				$chargeable += $total;
			}
		}
		return $sumDeduct === ($chargeable - $sumSubsidy);
	}

	public static function lineTotalCents(int $qty, int $unitPriceCents): int
	{
		if ($qty < 1 || $qty > 100) {
			throw new \InvalidArgumentException('qty_invalid');
		}
		if ($unitPriceCents < 0) {
			throw new \InvalidArgumentException('price_invalid');
		}
		return $qty * $unitPriceCents;
	}
}
