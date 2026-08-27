<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for SnackCheck settings sub-pages (Check-family standard).
 *
 * Artifacts that must stay in sync (contract-tested):
 *  - appinfo/routes.php `{section}` requirement via {@see routeRequirement()}
 *  - PageController validation / titles / nav injection
 *  - templates/pages/settings.php literal slug → partial map
 *  - sidebar children + in-page chip bar
 *
 * Legacy aliases `periods` / `sites` redirect to ops pages and are NOT catalog sections.
 */
final class SettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'access';

	/**
	 * Ordered section slugs — drives sidebar + chip bar order.
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		'access',
		'benefits',
		'privacy',
		'pulse',
		'digests',
		'unlock',
		'license',
		'support',
	];

	/**
	 * Route aliases that redirect away from settings (kept in routes.php allowlist).
	 *
	 * @var list<string>
	 */
	public const REDIRECT_ALIASES = [
		'periods',
		'sites',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	/**
	 * Value for the `{section}` route placeholder (sections + redirect aliases).
	 */
	public static function routeRequirement(): string
	{
		return implode('|', array_merge(self::SECTIONS, self::REDIRECT_ALIASES));
	}

	/**
	 * H1 / breadcrumb current — longer descriptive title.
	 */
	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access control'),
			'benefits' => $l->t('Subsidy & kitchens'),
			'privacy' => $l->t('Privacy'),
			'pulse' => $l->t('Kitchen overview settings'),
			'digests' => $l->t('Reminder emails'),
			'unlock' => $l->t('Unlock PIN / QR'),
			'license' => $l->t('Official tablet licenses'),
			'support' => $l->t('Support & us'),
			default => $l->t('Settings'),
		};
	}

	/**
	 * Short sidebar / chip label.
	 */
	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Access'),
			'benefits' => $l->t('Subsidy'),
			'privacy' => $l->t('Privacy'),
			'pulse' => $l->t('Overview'),
			'digests' => $l->t('Emails'),
			'unlock' => $l->t('Unlock PIN / QR'),
			'license' => $l->t('License'),
			/* Short chip label — page H1 still uses “Support & us”. */
			'support' => $l->t('Support'),
			default => $l->t('Settings'),
		};
	}

	/**
	 * One-line lead under the H1. Empty when the section ships its own intro.
	 */
	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'access' => $l->t('Decide who may open SnackCheck. Restriction takes effect immediately for non-administrators.'),
			'benefits' => $l->t('Monthly subsidy, company treats, and several kitchens.'),
			'privacy' => $l->t('Hide itemized consumption lines when privacy mode is on.'),
			'pulse' => $l->t('How many days the overview uses for snacks and restock.'),
			'digests' => $l->t('Optional emails before month end and weekly restock reminders.'),
			'unlock' => $l->t('PIN or QR so people can unlock the kitchen tablet. Keep secrets offline.'),
			'license' => $l->t('The web app stays free. An SNK2 key unlocks kitchen tablets for your organisation.'),
			'support' => '',
			default => '',
		};
	}
}
