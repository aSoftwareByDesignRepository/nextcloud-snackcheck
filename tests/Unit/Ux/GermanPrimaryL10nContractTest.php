<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * AC-28 / MH-09 — primary Log/Users/nav strings must ship DE translations
 * (not English fallbacks) for DACH kitchen admins.
 */
final class GermanPrimaryL10nContractTest extends TestCase
{
	/** @return list<string> */
	private function primaryKeys(): array
	{
		return [
			'Kitchen',
			'Money',
			'Undo',
			'Quantity',
			'Print list',
			'Open',
			'Closed',
			'Digests',
			'Hospitality',
			'Stock',
			'In fridge',
			'Target',
			'quantity, colleague, company',
			'No snacks logged this period',
			'Consumption changed a lot vs last period',
			'Pick a site above before logging. Each kitchen has its own catalog.',
			'Choose a site from the Site menu, then come back to log.',
			'Available even when privacy hides itemized lines.',
			'Itemized lines hidden by privacy mode.',
			'Totals only (privacy)',
			'No personal consumption this period.',
			'Catalog is empty.',
			'Add catalog item',
			'Save PIN',
			'How many?',
			'Tap a snack. Done.',
			'Tap below to log this snack. Done.',
			'Shelf item',
			'Free',
			'Log for a colleague',
			'Find users',
			'Matching users',
			'Tap a field first',
			'Colleague',
			'Company',
			'Me',
			'Choose site',
			'Open Catalog',
			'Open Benefits',
			'Log a snack',
			'Matching people',
			'Saving…',
			'Pick your kitchen above, then tap a snack here.',
			'Adds the suggested quantity in one tap.',
			'Choose… then search',
			'Monthly subsidy (€)',
			'See My month',
		];
	}

	public function testDeJsonTranslatesPrimaryUiStrings(): void
	{
		$path = dirname(__DIR__, 3) . '/l10n/de.json';
		$raw = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$tr = $raw['translations'] ?? [];
		self::assertIsArray($tr);
		foreach ($this->primaryKeys() as $key) {
			self::assertArrayHasKey($key, $tr, 'missing DE key: ' . $key);
			self::assertNotSame($key, $tr[$key], 'DE still English for: ' . $key);
			self::assertNotSame('', trim((string)$tr[$key]));
		}
		self::assertSame('Gratis', $tr['Free']);
		self::assertSame('Küche', $tr['Kitchen']);
		self::assertSame('Geld', $tr['Money']);
		self::assertSame('Rückgängig', $tr['Undo']);
		self::assertSame('Offen', $tr['Open']);
		self::assertSame('Geschlossen', $tr['Closed']);
		self::assertSame('Bewirtung', $tr['Hospitality']);
	}

	public function testEnJsonContainsPrimaryUiKeys(): void
	{
		$path = dirname(__DIR__, 3) . '/l10n/en.json';
		$raw = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$tr = $raw['translations'] ?? [];
		foreach ($this->primaryKeys() as $key) {
			self::assertArrayHasKey($key, $tr, 'missing EN key: ' . $key);
			self::assertSame($key, $tr[$key]);
		}
	}

	public function testClosePeriodWarningsUseL10nHelper(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
		self::assertMatchesRegularExpression(
			"/zero_logs:\s*t\(\s*'No snacks logged this period'/",
			$js
		);
		self::assertMatchesRegularExpression(
			"/huge_mom_delta:\s*t\(\s*'Consumption changed a lot vs last period'/",
			$js
		);
	}

	public function testPeriodStateEnumsAreTranslatedInTemplates(): void
	{
		$periods = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/pages/periods.php');
		$hosp = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/pages/hospitality.php');
		self::assertStringContainsString("\$l->t('Closed')", $periods);
		self::assertStringContainsString("\$l->t('Open')", $periods);
		self::assertStringContainsString("\$l->t('Closed')", $hosp);
		self::assertStringContainsString('companyUserDisplay', $hosp);
		self::assertStringContainsString('allowlistDisplay', $hosp);
		self::assertStringNotContainsString("implode(', ', \$_['allowlist'])", $hosp);
	}
}
