<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Cron;

use OCA\SnackCheck\Service\DigestMailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Pre-close personal digest — daily; mails to_deduct when within N days of period end.
 */
class PersonalDigestJob extends TimedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly DigestMailService $digests,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
	}

	protected function run($argument): void
	{
		try {
			$result = $this->digests->sendPersonalDigests();
			$this->logger->info('SnackCheck personal digest run', $result);
		} catch (\Throwable $e) {
			$this->logger->warning('SnackCheck personal digest failed', ['exception' => $e]);
		}
	}
}
