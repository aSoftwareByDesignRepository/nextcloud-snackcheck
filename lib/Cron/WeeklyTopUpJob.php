<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Cron;

use OCA\SnackCheck\Service\DigestMailService;
use OCA\SnackCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Weekly top-up email to app admins (default off). */
class WeeklyTopUpJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly DigestMailService $digests,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
	) {
		parent::__construct($time);
		$this->setInterval(7 * 24 * 3600);
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'weekly top-up',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'SnackCheck: skipping weekly top-up — upgrade backup/restore in progress.',
				['app' => 'snackcheck'],
			);
			return;
		}
		try {
			$this->doRun($argument);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun($argument): void
	{
		try {
			$result = $this->digests->sendWeeklyTopUp();
			$this->logger->info('SnackCheck weekly top-up run', $result);
		} catch (\Throwable $e) {
			// best-effort: cron run must not throw; failure is logged and the job
			// reschedules on the next tick.
			$this->logger->warning('SnackCheck weekly top-up failed', ['exception' => $e]);
		}
	}
}
