<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCP\IL10N;

/**
 * Shared My month payroll statement — web UI and PDF use the same numbers and labels.
 */
final class MyMonthStatementPresenter
{
	public function showSubsidy(int $subsidyAllowanceCents, int $subsidyCents): bool
	{
		return $subsidyAllowanceCents > 0 || $subsidyCents > 0;
	}

	public function formatEuroWeb(int $cents): string
	{
		return number_format($cents / 100, 2, ',', '.') . ' €';
	}

	public function formatEuroPdf(int $cents): string
	{
		return PayrollExportService::centsToEur($cents) . ' EUR';
	}

	/**
	 * @return list<array{label:string,value:string}>
	 */
	public function breakdownRows(IL10N $l, int $grossCents, int $subsidyCents, int $subsidyAllowanceCents): array
	{
		$rows = [
			[
				'label' => $l->t('What you logged'),
				'value' => $this->formatEuroWeb($grossCents),
			],
		];
		if ($this->showSubsidy($subsidyAllowanceCents, $subsidyCents)) {
			$rows[] = [
				'label' => $l->t('Company covers'),
				'value' => $this->formatEuroWeb($subsidyCents),
			];
		}
		return $rows;
	}

	/**
	 * @param list<array{name:string,qty:int,line_total_cents:int,free:bool,createdAt:string,siteName?:string}> $lines
	 * @return array<string,mixed>
	 */
	public function buildPdfDocument(
		IL10N $l,
		string $periodLabel,
		string $personLabel,
		string $generatedAt,
		array $lines,
		int $grossCents,
		int $subsidyCents,
		int $deductCents,
		int $subsidyAllowanceCents,
		int $freeQty,
		bool $multiSite,
	): array {
		$breakdown = [
			[
				'label' => $l->t('What you logged'),
				'value' => $this->formatEuroPdf($grossCents),
			],
		];
		if ($this->showSubsidy($subsidyAllowanceCents, $subsidyCents)) {
			$breakdown[] = [
				'label' => $l->t('Company covers'),
				'value' => $this->formatEuroPdf($subsidyCents),
			];
		}

		if ($multiSite) {
			$columns = [$l->t('Item'), $l->t('Site'), $l->t('Qty'), $l->t('Amount'), $l->t('When')];
			$colWidths = [0.30, 0.16, 0.08, 0.18, 0.28];
		} else {
			$columns = [$l->t('Item'), $l->t('Qty'), $l->t('Amount'), $l->t('When')];
			$colWidths = [0.38, 0.08, 0.18, 0.36];
		}

		$rows = [];
		foreach ($lines as $row) {
			$cells = [(string)$row['name']];
			if ($multiSite) {
				$cells[] = (string)($row['siteName'] ?? '');
			}
			$cells[] = (string)(int)$row['qty'];
			$cells[] = !empty($row['free']) ? $l->t('Free') : $this->formatEuroPdf((int)$row['line_total_cents']);
			$cells[] = (string)($row['createdAt'] ?? '');
			$rows[] = $cells;
		}

		$note = $l->t('Amounts in EUR. Free items are logged for restock and are not charged.');
		if ($freeQty > 0) {
			$note .= ' ' . $l->t('Free items in this period: %n', ['n' => (string)$freeQty]);
		}

		return [
			'brand' => 'SnackCheck',
			'title' => $l->t('My month — payroll preview'),
			'meta' => [
				[$l->t('Period'), $periodLabel],
				[$l->t('Person'), $personLabel],
				[$l->t('Generated'), $generatedAt],
			],
			'keyFigure' => [
				'label' => $l->t('To deduct'),
				'value' => $this->formatEuroPdf($deductCents),
			],
			'breakdown' => $breakdown,
			'totalLine' => [
				'label' => $l->t('TOTAL TO DEDUCT'),
				'value' => $this->formatEuroPdf($deductCents),
			],
			'tableTitle' => $l->t('Logged items'),
			'columns' => $columns,
			'colWidths' => $colWidths,
			'rows' => $rows,
			'note' => $note,
			'emptyItemsText' => $l->t('No items in this period.'),
		];
	}
}
