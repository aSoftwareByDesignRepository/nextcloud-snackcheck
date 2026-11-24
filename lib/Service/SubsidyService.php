<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Employer subsidy + expense-credit math (integer cents only).
 *
 * G = Σ personal chargeable line_total (price > 0, not voided, billing_bucket=personal)
 * C = Σ non-voided personal_credit (−line_total; credits are stored negative)
 * free lines never consume subsidy
 * subsidy_applied = min(A, G)
 * to_deduct = G − subsidy_applied − C
 *
 * Clamp mode (expense_clamp_negative_deduct): negative to_deduct is floored at 0
 * and the un-netted remainder is reported as unused_credit_cents.
 */
class SubsidyService
{
	public const BUCKET_PERSONAL = 'personal';
	public const BUCKET_PERSONAL_CREDIT = 'personal_credit';
	public const BUCKET_COMPANY_HOSPITALITY = 'company_hospitality';

	/**
	 * @param list<array{line_total_cents:int, billing_bucket?:string, voided?:bool}> $lines
	 * @return array{gross_cents:int, subsidy_cents:int, credits_cents:int, deduct_cents:int, unused_credit_cents:int}
	 */
	public function computeForUser(int $allowanceCents, array $lines, bool $clampNegativeDeduct = false): array
	{
		$allowanceCents = max(0, $allowanceCents);
		$gross = 0;
		$credits = 0;
		foreach ($lines as $line) {
			if (!empty($line['voided'])) {
				continue;
			}
			$bucket = $line['billing_bucket'] ?? self::BUCKET_PERSONAL;
			$total = (int)($line['line_total_cents'] ?? 0);
			if ($bucket === self::BUCKET_PERSONAL_CREDIT) {
				if ($total < 0) {
					$credits += -$total;
				}
				continue;
			}
			if ($bucket !== self::BUCKET_PERSONAL) {
				continue;
			}
			if ($total <= 0) {
				continue; // complimentary / free never consume subsidy
			}
			$gross += $total;
		}
		$applied = min($allowanceCents, $gross);
		$deduct = $gross - $applied - $credits;
		$unused = 0;
		if ($clampNegativeDeduct && $deduct < 0) {
			$unused = -$deduct;
			$deduct = 0;
		}
		return [
			'gross_cents' => $gross,
			'subsidy_cents' => $applied,
			'credits_cents' => $credits,
			'deduct_cents' => $deduct,
			'unused_credit_cents' => $unused,
		];
	}

	/**
	 * Reconcile invariant:
	 *   payout mode: Σ deduct          === Σ chargeable personal − Σ subsidy − Σ credits
	 *   clamp  mode: Σ (deduct−unused) === Σ chargeable personal − Σ subsidy − Σ credits
	 *
	 * @param list<array{deduct_cents:int, subsidy_cents:int, unused_credit_cents?:int}> $summaries
	 * @param list<array{line_total_cents:int, billing_bucket?:string, voided?:bool}> $allLines
	 */
	public function reconcileInvariant(array $summaries, array $allLines, bool $clampNegativeDeduct = false): bool
	{
		$sumNet = 0;
		$sumSubsidy = 0;
		foreach ($summaries as $s) {
			$deduct = (int)$s['deduct_cents'];
			if ($clampNegativeDeduct) {
				$deduct -= (int)($s['unused_credit_cents'] ?? 0);
			}
			$sumNet += $deduct;
			$sumSubsidy += (int)$s['subsidy_cents'];
		}
		$chargeable = 0;
		$credits = 0;
		foreach ($allLines as $line) {
			if (!empty($line['voided'])) {
				continue;
			}
			$bucket = $line['billing_bucket'] ?? self::BUCKET_PERSONAL;
			$total = (int)($line['line_total_cents'] ?? 0);
			if ($bucket === self::BUCKET_PERSONAL_CREDIT) {
				if ($total < 0) {
					$credits += -$total;
				}
				continue;
			}
			if ($bucket !== self::BUCKET_PERSONAL) {
				continue;
			}
			if ($total > 0) {
				$chargeable += $total;
			}
		}
		return $sumNet === ($chargeable - $sumSubsidy - $credits);
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
