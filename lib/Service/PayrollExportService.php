<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\ConsumptionLog;
use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Db\Period;
use OCA\SnackCheck\Db\SiteMapper;
use OCA\SnackCheck\Exception\DomainException;
use OCP\IUserManager;

/**
 * Payroll package: lines + summary_by_user + summary_by_user_site.
 * Hospitality excluded from personal payroll (NN-17).
 * Complimentary (line_total=0) excluded (NN-15).
 * CSV UTF-8 BOM + ';' delimiter. XLSX via lightweight OOXML writer.
 */
class PayrollExportService
{
	public function __construct(
		private readonly ConsumptionLogMapper $logs,
		private readonly SiteMapper $sites,
		private readonly SubsidyService $subsidy,
		private readonly SettingsService $settings,
		private readonly PeriodService $periods,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * @return array{
	 *   lines: list<array<string,mixed>>,
	 *   summaryByUser: list<array<string,mixed>>,
	 *   summaryByUserSite: list<array<string,mixed>>,
	 *   grandTotalCents: int,
	 *   complimentaryQtyExcluded: int,
	 *   reconcileOk: bool
	 * }
	 */
	public function buildPersonalPackage(int $periodId, ?int $siteFilter = null): array
	{
		$period = $this->periods->get($periodId);
		$all = $this->logs->findForPeriod($periodId, false);
		$allowance = $this->settings->getSubsidyAllowanceCents();
		$siteMap = [];
		foreach ($this->sites->findAllActive() as $s) {
			$siteMap[(int)$s->getId()] = ['code' => $s->getCode(), 'name' => $s->getName()];
		}

		$complimentaryQty = 0;
		$personalLines = [];
		$byUserLines = [];
		$byUserSiteGross = [];
		$displayByUser = [];

		foreach ($all as $log) {
			/** @var ConsumptionLog $log */
			if ($log->getBillingBucket() === 'company_hospitality') {
				continue;
			}
			$total = (int)$log->getLineTotalCents();
			$sid = (int)$log->getSiteId();
			$inSiteFilter = $siteFilter === null || $sid === $siteFilter;
			if ($total <= 0) {
				// Complimentary qty respects site filter (Y17 lines/site sheets).
				if ($inSiteFilter) {
					$complimentaryQty += (int)$log->getQty();
				}
				continue;
			}
			$uid = $log->getUserId();
			if (($displayByUser[$uid] ?? '') === '') {
				$displayByUser[$uid] = (string)$log->getUserDisplaySnap();
			}
			$siteMeta = $siteMap[$sid] ?? ['code' => '', 'name' => ''];
			$row = [
				'period_label' => $period->getLabel(),
				'user_id' => $uid,
				'user_display_name' => $log->getUserDisplaySnap(),
				'item_name' => $log->getItemNameSnap(),
				'qty' => (int)$log->getQty(),
				'unit_price_eur' => self::centsToEur((int)$log->getUnitPriceCents()),
				'line_total_eur' => self::centsToEur($total),
				'line_total_cents' => $total,
				'logged_at' => $log->getCreatedAt()?->format('c') ?? '',
				'source' => $log->getSource(),
				'site_code' => $siteMeta['code'],
				'site_name' => $siteMeta['name'],
				'site_id' => $sid,
				'billing_bucket' => 'personal',
			];
			// Y17: lines + summary_by_user_site respect site filter; summary_by_user stays org-wide.
			if ($inSiteFilter) {
				$personalLines[] = $row;
				$usk = $uid . "\0" . $sid;
				$byUserSiteGross[$usk] = ($byUserSiteGross[$usk] ?? 0) + $total;
			}
			$byUserLines[$uid][] = [
				'line_total_cents' => $total,
				'billing_bucket' => 'personal',
				'voided' => false,
			];
		}

		$summaryByUser = [];
		foreach ($byUserLines as $uid => $lines) {
			$calc = $this->subsidy->computeForUser($allowance, $lines);
			$summaryByUser[] = [
				'user_id' => $uid,
				'user_display_name' => $displayByUser[$uid] ?? '',
				'gross_cents' => $calc['gross_cents'],
				'subsidy_cents' => $calc['subsidy_cents'],
				'deduct_cents' => $calc['deduct_cents'],
			];
		}

		$summaryByUserSite = [];
		foreach ($byUserSiteGross as $usk => $gross) {
			[$uid, $sid] = explode("\0", $usk, 2);
			$siteMeta = $siteMap[(int)$sid] ?? ['code' => '', 'name' => ''];
			$summaryByUserSite[] = [
				'user_id' => $uid,
				'site_code' => $siteMeta['code'],
				'site_name' => $siteMeta['name'],
				'gross_cents' => $gross,
			];
		}

		$allPersonalForReconcile = [];
		foreach ($byUserLines as $lines) {
			foreach ($lines as $l) {
				$allPersonalForReconcile[] = $l;
			}
		}
		$reconcileOk = $this->subsidy->reconcileInvariant($summaryByUser, $allPersonalForReconcile);
		$grand = 0;
		foreach ($summaryByUser as $s) {
			$grand += (int)$s['deduct_cents'];
		}

		return [
			'lines' => $personalLines,
			'summaryByUser' => $summaryByUser,
			'summaryByUserSite' => $summaryByUserSite,
			'grandTotalCents' => $grand,
			'complimentaryQtyExcluded' => $complimentaryQty,
			'reconcileOk' => $reconcileOk,
			'multiSiteEnabled' => $this->settings->isMultiSiteEnabled(),
			'siteFilter' => $siteFilter,
			'periodLabel' => $period->getLabel(),
		];
	}

	/** @return list<array<string,mixed>> */
	/**
	 * @return list<array<string,mixed>>
	 */
	public function buildHospitalityRows(int $periodId, ?int $siteFilter = null): array
	{
		$period = $this->periods->get($periodId);
		$rows = [];
		$siteMap = [];
		foreach ($this->sites->findAllActive() as $s) {
			$siteMap[(int)$s->getId()] = ['code' => $s->getCode(), 'name' => $s->getName()];
		}
		foreach ($this->logs->findForPeriod($periodId, false) as $log) {
			if ($log->getBillingBucket() !== 'company_hospitality') {
				continue;
			}
			$sid = (int)$log->getSiteId();
			if ($siteFilter !== null && $sid !== $siteFilter) {
				continue;
			}
			$actorUid = (string)($log->getLoggedBy() ?? '');
			$actorDisplay = $actorUid;
			if ($actorUid !== '') {
				$u = $this->userManager->get($actorUid);
				$actorDisplay = $u?->getDisplayName() ?: $actorUid;
			}
			$rows[] = [
				'logged_at' => $log->getCreatedAt()?->format('c') ?? '',
				'actor_uid' => $actorUid,
				'actor_display' => $actorDisplay,
				'company_user_id' => $log->getUserId(),
				'item_name' => $log->getItemNameSnap(),
				'qty' => (int)$log->getQty(),
				'unit_price_cents' => (int)$log->getUnitPriceCents(),
				'line_total_cents' => (int)$log->getLineTotalCents(),
				'reason' => (string)($log->getHospReason() ?? ''),
				'source' => $log->getSource(),
				'site_code' => $siteMap[$sid]['code'] ?? '',
				'site_name' => $siteMap[$sid]['name'] ?? '',
				'period_label' => $period->getLabel(),
			];
		}
		return $rows;
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @param list<string> $columns
	 */
	public function toCsv(array $rows, array $columns): string
	{
		$out = "\xEF\xBB\xBF";
		$out .= implode(';', $columns) . "\n";
		foreach ($rows as $row) {
			$cells = [];
			foreach ($columns as $col) {
				$cells[] = self::csvEscape((string)($row[$col] ?? ''));
			}
			$out .= implode(';', $cells) . "\n";
		}
		return $out;
	}

	/**
	 * Minimal XLSX (ZIP OOXML) with three sheets.
	 * @param array<string,mixed> $package
	 */
	public function toXlsx(array $package): string
	{
		$sheets = [
			'lines' => [
				'cols' => ['period_label','user_id','user_display_name','item_name','qty','unit_price_eur','line_total_eur','logged_at','source','site_code','site_name'],
				'rows' => $package['lines'],
			],
			'summary_by_user' => [
				'cols' => ['user_id','user_display_name','gross_cents','subsidy_cents','deduct_cents'],
				'rows' => $package['summaryByUser'],
			],
			'summary_by_user_site' => [
				'cols' => ['user_id','site_code','site_name','gross_cents'],
				'rows' => $package['summaryByUserSite'],
			],
		];
		return XlsxWriter::fromSheets($sheets);
	}

	public static function centsToEur(int $cents): string
	{
		return number_format($cents / 100, 2, '.', '');
	}

	public static function csvEscape(string $value): string
	{
		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
			$value = "'" . $value;
		}
		if (str_contains($value, ';') || str_contains($value, '"') || str_contains($value, "\n")) {
			return '"' . str_replace('"', '""', $value) . '"';
		}
		return $value;
	}
}
