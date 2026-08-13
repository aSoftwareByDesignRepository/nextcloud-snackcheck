<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Payroll package reconcile invariant (CORE §9.5).
 */
final class PayrollReconcile
{
	/**
	 * @param list<array{line_total_cents:int,billing_bucket:string,voided:bool}> $lines
	 * @param list<array{gross_cents:int,subsidy_cents:int,deduct_cents:int}> $summaryByUser
	 */
	public static function assertInvariant(array $lines, array $summaryByUser, int $allowanceCents): void
	{
		$chargeable = 0;
		foreach ($lines as $line) {
			if (!empty($line['voided'])) {
				continue;
			}
			if (($line['billing_bucket'] ?? 'personal') !== 'personal') {
				continue;
			}
			$total = (int)$line['line_total_cents'];
			if ($total > 0) {
				$chargeable += $total;
			}
		}

		$sumGross = 0;
		$sumSubsidy = 0;
		$sumDeduct = 0;
		foreach ($summaryByUser as $row) {
			$sumGross += (int)$row['gross_cents'];
			$sumSubsidy += (int)$row['subsidy_cents'];
			$sumDeduct += (int)$row['deduct_cents'];
			$calc = SubsidyCalculator::forUser((int)$row['gross_cents'], $allowanceCents);
			if ($calc['subsidy_cents'] !== (int)$row['subsidy_cents']
				|| $calc['deduct_cents'] !== (int)$row['deduct_cents']) {
				throw new \RuntimeException('subsidy_row_mismatch');
			}
		}

		if ($sumGross !== $chargeable) {
			throw new \RuntimeException('gross_mismatch');
		}
		if ($sumDeduct !== $sumGross - $sumSubsidy) {
			throw new \RuntimeException('deduct_mismatch');
		}
	}

	/**
	 * Build per-user summary from personal chargeable lines.
	 *
	 * @param list<array{user_id:string,line_total_cents:int,billing_bucket:string,voided:bool}> $lines
	 * @return list<array{user_id:string,gross_cents:int,subsidy_cents:int,deduct_cents:int}>
	 */
	public static function summarizeByUser(array $lines, int $allowanceCents): array
	{
		$grossByUser = [];
		foreach ($lines as $line) {
			if (!empty($line['voided'])) {
				continue;
			}
			if (($line['billing_bucket'] ?? 'personal') !== 'personal') {
				continue;
			}
			$total = (int)$line['line_total_cents'];
			if ($total <= 0) {
				continue;
			}
			$uid = (string)$line['user_id'];
			$grossByUser[$uid] = ($grossByUser[$uid] ?? 0) + $total;
		}
		ksort($grossByUser);
		$out = [];
		foreach ($grossByUser as $uid => $gross) {
			$calc = SubsidyCalculator::forUser($gross, $allowanceCents);
			$out[] = [
				'user_id' => $uid,
				'gross_cents' => $calc['gross_cents'],
				'subsidy_cents' => $calc['subsidy_cents'],
				'deduct_cents' => $calc['deduct_cents'],
			];
		}
		return $out;
	}
}
