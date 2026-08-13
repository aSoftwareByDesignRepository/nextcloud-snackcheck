<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Employer subsidy: chargeable gross then deduct.
 * Free (€0) lines never consume allowance.
 */
final class SubsidyCalculator
{
	/**
	 * @return array{gross_cents:int,subsidy_cents:int,deduct_cents:int}
	 */
	public static function forUser(int $chargeableGrossCents, int $allowanceCents): array
	{
		$gross = max(0, $chargeableGrossCents);
		$allowance = max(0, $allowanceCents);
		$subsidy = min($allowance, $gross);
		return [
			'gross_cents' => $gross,
			'subsidy_cents' => $subsidy,
			'deduct_cents' => $gross - $subsidy,
		];
	}

	/**
	 * Pace / top-up formulas (CORE §9.6).
	 *
	 * @return array{avg_per_day:float,days_left:?float,top_up:bool}
	 */
	public static function topUpHint(
		int $qtyInWindow,
		int $daysWithData,
		?int $onHand,
		?int $parLevel,
		int $restockHorizonDays,
	): array {
		$days = max(1, $daysWithData);
		$avg = $qtyInWindow / $days;
		$daysLeft = null;
		$topUp = false;
		if ($onHand !== null && $parLevel !== null) {
			if ($avg > 0.0) {
				$daysLeft = $onHand / $avg;
				if ($daysLeft <= $restockHorizonDays || $onHand <= $parLevel) {
					$topUp = true;
				}
			} elseif ($onHand <= $parLevel) {
				$topUp = true;
			}
		}
		return [
			'avg_per_day' => $avg,
			'days_left' => $daysLeft,
			'top_up' => $topUp,
		];
	}

	public static function suggestedBuy(?int $parLevel, ?int $onHand): int
	{
		if ($parLevel === null || $onHand === null) {
			return 0;
		}
		return max(0, $parLevel - $onHand);
	}

	public static function lineTotal(int $qty, int $unitPriceCents): int
	{
		if ($qty < 1 || $qty > 100) {
			throw new \InvalidArgumentException('qty_invalid');
		}
		if ($unitPriceCents < 0 || $unitPriceCents > 1_000_000) {
			throw new \InvalidArgumentException('price_invalid');
		}
		return $qty * $unitPriceCents;
	}

	public static function isComplimentaryLine(int $lineTotalCents): bool
	{
		return $lineTotalCents === 0;
	}

	public static function formatEur(int $cents): string
	{
		$neg = $cents < 0;
		$cents = abs($cents);
		$euros = intdiv($cents, 100);
		$rem = $cents % 100;
		$s = sprintf('%d.%02d', $euros, $rem);
		return $neg ? '-' . $s : $s;
	}
}
