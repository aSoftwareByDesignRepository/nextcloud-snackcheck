<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\ConsumptionLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;

/**
 * Personal pre-close digest + weekly top-up mail (US-OPP-B / pack T).
 * Idempotent per (period,user) / (isoWeek,site) via appconfig claims.
 * Weekly top-up: site managers receive ONLY their sites (AC-OPP-Y5); app admins get all.
 */
class DigestMailService
{
	public const APP_ID = 'snackcheck';
	public const DEFAULT_DAYS_BEFORE = 3;

	public function __construct(
		private readonly SettingsService $settings,
		private readonly PeriodService $periods,
		private readonly SiteService $sites,
		private readonly PulseService $pulse,
		private readonly SubsidyService $subsidy,
		private readonly ConsumptionLogMapper $logs,
		private readonly IMailer $mailer,
		private readonly IUserManager $userManager,
		private readonly IURLGenerator $url,
		private readonly IConfig $config,
		private readonly ITimeFactory $timeFactory,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
	) {
	}

	public function getDigestDaysBefore(): int
	{
		return max(1, min(14, (int)$this->config->getAppValue(self::APP_ID, 'personal_digest_days_before', (string)self::DEFAULT_DAYS_BEFORE)));
	}

	public function setDigestDaysBefore(int $days): void
	{
		$this->config->setAppValue(self::APP_ID, 'personal_digest_days_before', (string)max(1, min(14, $days)));
	}

	/**
	 * @return array{sent:int,skipped:int,eligible:bool}
	 */
	public function sendPersonalDigests(?\DateTimeInterface $now = null): array
	{
		if (!$this->settings->isPersonalDigestEnabled()) {
			return ['sent' => 0, 'skipped' => 0, 'eligible' => false];
		}
		$now = $now ?? $this->timeFactory->getDateTime();
		$period = $this->periods->findOpen();
		if ($period === null) {
			return ['sent' => 0, 'skipped' => 0, 'eligible' => false];
		}
		$endsOn = $period->getEndsOn();
		if ($endsOn === null) {
			return ['sent' => 0, 'skipped' => 0, 'eligible' => false];
		}
		$end = \DateTimeImmutable::createFromInterface($endsOn)->setTime(23, 59, 59);
		$nowImm = \DateTimeImmutable::createFromInterface($now);
		$daysLeft = (int)$nowImm->diff($end)->format('%r%a');
		if ($daysLeft < 0 || $daysLeft > $this->getDigestDaysBefore()) {
			return ['sent' => 0, 'skipped' => 0, 'eligible' => false];
		}

		$sent = 0;
		$skipped = 0;
		$allowance = $this->settings->getSubsidyAllowanceCents();
		$userIds = $this->distinctUsersForPeriod((int)$period->getId());
		foreach ($userIds as $uid) {
			$claimKey = 'digest_sent_' . $period->getId() . '_' . hash('sha256', $uid);
			if ($this->config->getAppValue(self::APP_ID, $claimKey, '') === '1') {
				$skipped++;
				continue;
			}
			$user = $this->userManager->get($uid);
			$email = $user?->getEMailAddress();
			if ($email === null || $email === '') {
				$skipped++;
				continue;
			}
			$lines = [];
			foreach ($this->logs->findForUserPeriod((int)$period->getId(), $uid) as $log) {
				if ($log->getVoidedAt() !== null) {
					continue;
				}
				if ($log->getBillingBucket() !== 'personal') {
					continue;
				}
				$lines[] = [
					'line_total_cents' => (int)$log->getLineTotalCents(),
					'billing_bucket' => 'personal',
				];
			}
			$calc = $this->subsidy->computeForUser($allowance, $lines);
			if ($this->settings->isPersonalDigestSkipZeroEnabled() && (int)$calc['deduct_cents'] === 0) {
				// AC-OPP-B4: do not claim — if balance appears later in the window, still mail once.
				$skipped++;
				continue;
			}
			if (!$this->claimDigestSlot($claimKey)) {
				$skipped++;
				continue;
			}
			$myMonthUrl = $this->url->linkToRouteAbsolute('snackcheck.page.myMonth');
			$subject = $this->l10n->t('SnackCheck: your month total');
			$body = $this->l10n->t(
				'Period %1$s — to deduct: %2$s EUR (gross %3$s, subsidy %4$s). Review: %5$s',
				[
					$period->getLabel(),
					\OCA\SnackCheck\Service\PayrollExportService::centsToEur($calc['deduct_cents']),
					\OCA\SnackCheck\Service\PayrollExportService::centsToEur($calc['gross_cents']),
					\OCA\SnackCheck\Service\PayrollExportService::centsToEur($calc['subsidy_cents']),
					$myMonthUrl,
				]
			);
			if ($this->sendPlain($email, $subject, $body)) {
				$sent++;
			} else {
				$this->releaseDigestSlot($claimKey);
				$skipped++;
			}
		}
		return ['sent' => $sent, 'skipped' => $skipped, 'eligible' => true];
	}

	/**
	 * @return array{sent:int,items:int}
	 */
	public function sendWeeklyTopUp(?\DateTimeInterface $now = null): array
	{
		if (!$this->settings->isWeeklyTopUpEmailEnabled()) {
			return ['sent' => 0, 'items' => 0];
		}
		$now = $now ?? $this->timeFactory->getDateTime();
		$weekKey = $now->format('o-\WW');
		$admins = array_fill_keys($this->settings->getAppAdmins(), true);
		$sites = $this->settings->isMultiSiteEnabled()
			? $this->sites->listActive()
			: [$this->sites->ensureDefaultSite()];

		/** @var array<int, string> $sectionsBySite */
		$sectionsBySite = [];
		/** @var array<int, array<string, true>> $managersBySite */
		$managersBySite = [];
		$claimKeys = [];
		$itemCount = 0;
		$recipientUids = $admins;

		foreach ($sites as $site) {
			$siteId = (int)$site->getId();
			$claimKey = 'weekly_topup_sent_' . $weekKey . '_' . $siteId;
			if (!$this->claimDigestSlot($claimKey)) {
				continue;
			}
			$managersBySite[$siteId] = [];
			foreach ($this->sites->managerUids($site) as $uid) {
				$managersBySite[$siteId][$uid] = true;
				$recipientUids[$uid] = true;
			}
			$pulse = $this->pulse->buildForSite($siteId);
			$topUp = $pulse['topUp'];
			$itemCount += count($topUp);
			$lines = [];
			foreach ($topUp as $row) {
				$lines[] = sprintf(
					'%s — buy %d (on hand %s / par %s)',
					(string)$row['name'],
					(int)($row['suggestedBuy'] ?? 0),
					$row['onHand'] === null ? '—' : (string)$row['onHand'],
					$row['parLevel'] === null ? '—' : (string)$row['parLevel'],
				);
			}
			$sectionsBySite[$siteId] = $this->l10n->t('Site %1$s:', [$site->getName()]) . "\n"
				. ($lines === [] ? $this->l10n->t('Nothing needs topping up.') : implode("\n", $lines));
			$claimKeys[] = $claimKey;
		}
		if ($claimKeys === [] || $sectionsBySite === []) {
			return ['sent' => 0, 'items' => 0];
		}
		if ($recipientUids === []) {
			foreach ($claimKeys as $claimKey) {
				$this->releaseDigestSlot($claimKey);
			}
			return ['sent' => 0, 'items' => $itemCount];
		}

		$subject = $this->l10n->t('SnackCheck: weekly top-up');
		$pulseUrl = $this->url->linkToRouteAbsolute('snackcheck.page.pulse');
		$sent = 0;
		foreach (array_keys($recipientUids) as $uid) {
			$user = $this->userManager->get($uid);
			$email = $user?->getEMailAddress();
			if ($email === null || $email === '') {
				continue;
			}
			$isAdmin = isset($admins[$uid]);
			$sections = [];
			foreach ($sectionsBySite as $siteId => $section) {
				if ($isAdmin || isset($managersBySite[$siteId][$uid])) {
					$sections[] = $section;
				}
			}
			if ($sections === []) {
				continue;
			}
			$body = $this->l10n->t('SnackCheck weekly top-up list:') . "\n\n"
				. implode("\n\n", $sections)
				. "\n\n" . $pulseUrl;
			if ($this->sendPlain($email, $subject, $body)) {
				$sent++;
			}
		}
		if ($sent === 0) {
			foreach ($claimKeys as $claimKey) {
				$this->releaseDigestSlot($claimKey);
			}
		}
		return ['sent' => $sent, 'items' => $itemCount];
	}

	/** @return list<string> */
	private function distinctUsersForPeriod(int $periodId): array
	{
		$uids = [];
		foreach ($this->logs->findForPeriod($periodId, false) as $log) {
			$uid = $log->getUserId();
			if (is_string($uid) && $uid !== '') {
				$uids[$uid] = true;
			}
		}
		return array_keys($uids);
	}

	private function sendPlain(string $to, string $subject, string $body): bool
	{
		try {
			/** @var IMessage $message */
			$message = $this->mailer->createMessage();
			$message->setTo([$to => $to]);
			$message->setSubject($subject);
			$message->setPlainBody($body);
			$failed = $this->mailer->send($message);
			return $failed === [];
		} catch (\Throwable $e) {
			$this->logger->warning('SnackCheck digest mail failed', [
				'to' => $to,
				'exception' => $e,
			]);
			return false;
		}
	}

	/** Claim-before-send under exclusive lock — prevents duplicate digests under overlapping cron. */
	private function claimDigestSlot(string $claimKey): bool
	{
		$lockKey = 'snackcheck/digest/' . hash('sha256', $claimKey);
		try {
			$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return false;
		}
		try {
			if ($this->config->getAppValue(self::APP_ID, $claimKey, '') === '1') {
				return false;
			}
			$this->config->setAppValue(self::APP_ID, $claimKey, '1');
			return true;
		} finally {
			$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function releaseDigestSlot(string $claimKey): void
	{
		$this->config->deleteAppValue(self::APP_ID, $claimKey);
	}
}
