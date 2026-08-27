<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use Psr\Log\LoggerInterface;

/**
 * Commercial seat/device enforcement for SNK2.
 * Web UI is never gated — only kitchen tablets.
 */
class LicenseEnforcementService
{
	public function __construct(
		private readonly TerminalDeviceService $terminals,
		private readonly LicenseService $license,
		private readonly LoggerInterface $logger,
	) {
	}

	public function trimTerminalsToLimit(int $limit): int
	{
		return $this->terminals->trimToLimit($limit);
	}

	/**
	 * Admin remove-license: revoke every kitchen tablet, then drop stored SNK2 state.
	 * Order matters — revoke while the old limit is still known for audit/trim paths,
	 * then clear so Device API rejects any leftover token immediately.
	 *
	 * @return array{terminalsRevoked: int}
	 */
	public function clearCommercialState(): array
	{
		$revoked = $this->terminals->trimToLimit(0);
		$this->license->clearLicense();
		$this->logger->warning('SNK2 license cleared; kitchen tablets revoked', [
			'terminalsRevoked' => $revoked,
		]);
		return ['terminalsRevoked' => $revoked];
	}
}
