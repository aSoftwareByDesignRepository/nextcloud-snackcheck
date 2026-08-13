<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

class LicenseEnforcementService
{
	public function __construct(
		private readonly TerminalDeviceService $terminals,
	) {
	}

	public function trimTerminalsToLimit(int $limit): int
	{
		return $this->terminals->trimToLimit($limit);
	}
}
