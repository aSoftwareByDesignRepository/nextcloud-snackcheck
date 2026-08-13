<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCA\SnackCheck\Exception\DomainException;

/**
 * Admin Users / totals view with privacy mode enforcement (MH-06 / AC-27).
 */
class AdminTotalsService
{
	public function __construct(
		private readonly ConsumptionLogMapper $logs,
		private readonly PeriodService $periods,
		private readonly SettingsService $settings,
		private readonly SubsidyService $subsidy,
	) {
	}

	/**
	 * @param list<int>|null $siteIds when set, only include logs for these sites (site managers)
	 * @return array{
	 *   periodLabel:string,
	 *   privacyTotalsOnly:bool,
	 *   users: list<array{userId:string,displayName:string,grossCents:int,subsidyCents:int,deductCents:int,lines?:list<array<string,mixed>>}>
	 * }
	 */
	public function buildForOpenPeriod(?string $focusUserId = null, ?array $siteIds = null): array
	{
		$period = $this->periods->findOpen() ?? $this->periods->findLatestClosed();
		if ($period === null) {
			return [
				'periodLabel' => '—',
				'privacyTotalsOnly' => $this->settings->isPrivacyTotalsOnly(),
				'users' => [],
			];
		}
		$privacy = $this->settings->isPrivacyTotalsOnly();
		if ($privacy && $focusUserId !== null && $focusUserId !== '') {
			throw new DomainException('privacy_totals_only', 'Itemized admin view disabled', 403);
		}
		$siteAllow = $siteIds === null ? null : array_fill_keys(array_map('intval', $siteIds), true);
		$byUser = [];
		foreach ($this->logs->findForPeriod((int)$period->getId(), false) as $log) {
			if ($log->getBillingBucket() === 'company_hospitality') {
				continue;
			}
			if ($siteAllow !== null && !isset($siteAllow[(int)$log->getSiteId()])) {
				continue;
			}
			$uid = $log->getUserId();
			if (!isset($byUser[$uid])) {
				$byUser[$uid] = [
					'userId' => $uid,
					'displayName' => $log->getUserDisplaySnap() ?: $uid,
					'lines' => [],
				];
			}
			$byUser[$uid]['lines'][] = [
				'id' => $log->getId(),
				'name' => $log->getItemNameSnap(),
				'qty' => $log->getQty(),
				'line_total_cents' => (int)$log->getLineTotalCents(),
				'billing_bucket' => 'personal',
				'createdAt' => $log->getCreatedAt()?->format('c'),
				'free' => ((int)$log->getLineTotalCents()) === 0,
				'siteId' => (int)$log->getSiteId(),
			];
		}
		$allowance = $this->settings->getSubsidyAllowanceCents();
		$users = [];
		foreach ($byUser as $row) {
			if ($focusUserId !== null && $focusUserId !== '' && $row['userId'] !== $focusUserId) {
				continue;
			}
			$calc = $this->subsidy->computeForUser($allowance, $row['lines']);
			$out = [
				'userId' => $row['userId'],
				'displayName' => $row['displayName'],
				'grossCents' => $calc['gross_cents'],
				'subsidyCents' => $calc['subsidy_cents'],
				'deductCents' => $calc['deduct_cents'],
			];
			if (!$privacy) {
				$out['lines'] = $row['lines'];
			}
			$users[] = $out;
		}
		usort($users, static fn ($a, $b) => strcasecmp($a['displayName'], $b['displayName']));
		return [
			'periodLabel' => $period->getLabel(),
			'privacyTotalsOnly' => $privacy,
			'users' => $users,
		];
	}
}
