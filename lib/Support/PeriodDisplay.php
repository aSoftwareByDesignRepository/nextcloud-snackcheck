<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Support;

/**
 * Human-facing period labels. Storage keeps unique keys like 2026-08 / 2026-08-2
 * (successor after reopen in the same month). UI must not look like invalid dates.
 */
final class PeriodDisplay
{
	/**
	 * @return array{base:string,suffix:int|null}
	 */
	public static function parse(string $label): array
	{
		$label = trim($label);
		if (preg_match('/^(\d{4}-\d{2})-(\d+)$/', $label, $m) === 1) {
			return ['base' => $m[1], 'suffix' => (int)$m[2]];
		}
		return ['base' => $label, 'suffix' => null];
	}

	/**
	 * Plain text for tables/toasts (ASCII-safe; l10n wraps in templates when needed).
	 */
	public static function format(string $label): string
	{
		$parts = self::parse($label);
		if ($parts['suffix'] === null) {
			return $parts['base'];
		}
		return $parts['base'] . ' (#' . $parts['suffix'] . ')';
	}
}
