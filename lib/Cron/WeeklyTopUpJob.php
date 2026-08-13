<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Cron;

use OCA\SnackCheck\Service\DigestMailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Weekly top-up email to app admins (default off). */
class WeeklyTopUpJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly DigestMailService $digests,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(7 * 24 * 3600);
	}

	protected function run($argument): void
	{
		try {
			$result = $this->digests->sendWeeklyTopUp();
			$this->logger->info('SnackCheck weekly top-up run', $result);
		} catch (\Throwable $e) {
			$this->logger->warning('SnackCheck weekly top-up failed', ['exception' => $e]);
		}
	}
}
