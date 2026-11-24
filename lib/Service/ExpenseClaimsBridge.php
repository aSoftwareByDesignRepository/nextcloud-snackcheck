<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCP\App\IAppManager;
use OCP\Server;

/**
 * Detection + access seam for the ExpenseCheck companion capability
 * `check:expense-claims`. EXP is optional — every call fails soft when the
 * app (or its published ingress contract) is absent.
 */
class ExpenseClaimsBridge
{
	public const EXP_APP_ID = 'expensecheck';
	/** F24 contract: EXP publishes a draft-ingress service under this class name. */
	public const INGRESS_CLASS = 'OCA\\ExpenseCheck\\Public\\ExpClaimDraftIngress';

	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}

	public function capabilityPresent(): bool
	{
		return $this->appManager->isEnabledForUser(self::EXP_APP_ID)
			&& interface_exists(self::INGRESS_CLASS);
	}

	/** Resolve the EXP draft ingress, or null when the capability is absent. */
	public function ingress(): ?object
	{
		if (!$this->capabilityPresent()) {
			return null;
		}
		try {
			$ingress = Server::get(self::INGRESS_CLASS);
			return is_object($ingress) ? $ingress : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
