<?php

declare(strict_types=1);

namespace OCA\SnackCheck\AppInfo;

use OCP\Capabilities\ICapability;

/**
 * Advertised capabilities. `check:expense-credits` is the contract marker
 * ExpenseCheck looks for before calling SnkExpenseCreditWriteFacade.
 */
class Capabilities implements ICapability
{
	public function getCapabilities(): array
	{
		return [
			'snackcheck' => [
				'expense-credits' => ['version' => \OCA\SnackCheck\Public\SnkExpenseCreditWriteFacade::FACADE_VERSION],
			],
		];
	}
}
