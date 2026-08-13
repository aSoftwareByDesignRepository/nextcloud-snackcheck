<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Directory picker must be a WAI-ARIA combobox (Check-family), not role=button list items.
 * Stale-search races and listed-mode chicken-egg are stop-ship.
 */
final class DirectoryPickerComboboxContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testPickerIsComboboxWithInflightAndKeyboard(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString("setAttribute('role', 'combobox')", $js);
		self::assertStringContainsString("aria-autocomplete", $js);
		self::assertStringContainsString("aria-expanded", $js);
		self::assertStringContainsString("aria-activedescendant", $js);
		self::assertStringContainsString("role', 'option'", $js);
		self::assertStringContainsString("role', 'listbox'", $js);
		self::assertStringContainsString('inflight', $js);
		self::assertStringContainsString("my !== inflight", $js);
		self::assertStringContainsString("ArrowDown", $js);
		self::assertStringContainsString("ArrowUp", $js);
		self::assertStringContainsString("Escape", $js);
		self::assertStringContainsString('findUserSearchNear', $js);
		self::assertStringContainsString('formBodyLastWins', $js);
		self::assertStringContainsString("data-snk-busy", $js);
		self::assertStringContainsString('scope=directory', $js);
		self::assertStringNotContainsString("setAttribute('role', 'button')", $js);
		self::assertStringNotContainsString('Object.fromEntries(fd.entries())', $js);
	}

	public function testDirectoryScopeSearchTemplatesAndApi(): void
	{
		$settings = (string)file_get_contents($this->root() . '/templates/pages/settings.php');
		self::assertStringContainsString('data-snk-search-scope="directory"', $settings);
		self::assertStringContainsString('snk-chip-search', $settings);
		$sites = (string)file_get_contents($this->root() . '/templates/pages/sites.php');
		self::assertStringContainsString('data-snk-search-scope="directory"', $sites);
		$log = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		self::assertStringContainsString('data-snk-search-scope="access"', $log);
		$api = (string)file_get_contents($this->root() . '/lib/Controller/ApiController.php');
		self::assertStringContainsString("\$scope === 'directory'", $api);
		self::assertMatchesRegularExpression(
			'/\$directory[\s\S]{0,80}canAccessApp\(\$uid\)/',
			$api
		);
		self::assertDoesNotMatchRegularExpression(
			'/foreach \(\$this->userManager->search[\s\S]{0,120}if \(\!\$this->access->canAccessApp\(\$uid\)\)/',
			$api,
			'default access filter must be gated by !\$directory'
		);
	}

	public function testCssHighlightsSelectedOption(): void
	{
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('aria-selected="true"', $css);
		self::assertStringContainsString('.snk-chip-search', $css);
		self::assertStringContainsString('.snk-chip-list', $css);
		self::assertStringContainsString('.snk-chip__remove', $css);
		self::assertStringContainsString('#app-content.snk-app .snk-chip__remove', $css);
		self::assertStringContainsString('min-width: var(--snk-touch)', $css);
	}

	public function testRemovableChipsPartialAndTemplates(): void
	{
		$partial = (string)file_get_contents($this->root() . '/templates/parts/snk-chip-field.php');
		self::assertStringContainsString('snk-chip-list', $partial);
		self::assertStringContainsString('data-snk-chip-remove', $partial);
		self::assertStringContainsString('type="hidden"', $partial);
		self::assertStringContainsString('data-snk-chip-activate', $partial);
		self::assertStringContainsString('No one selected yet', $partial);
		$settings = (string)file_get_contents($this->root() . '/templates/pages/settings.php');
		self::assertStringContainsString('snk-chip-field.php', $settings);
		self::assertStringNotContainsString('class="snk-chip-target" value="', $settings);
		self::assertStringNotContainsString('readonly />', $settings);
		$sites = (string)file_get_contents($this->root() . '/templates/pages/sites.php');
		self::assertStringContainsString('snk-chip-field.php', $sites);
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('wireChipFields', $js);
		self::assertStringContainsString('min-height: 2.75rem', (string)file_get_contents($this->root() . '/css/app.css'));
	}
}
