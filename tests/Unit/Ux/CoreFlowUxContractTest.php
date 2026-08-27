<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * User-journey / friction contracts — prove core flows stay one-step and accessible.
 */
final class CoreFlowUxContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testLogPeriodClosedGivesAdminEscapeHatch(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		self::assertStringContainsString('open-next-period', $src);
		// Bachus: one primary that does the job — no dual "Open Periods" nav detour.
		self::assertStringNotContainsString('Open Periods', $src);
		self::assertStringContainsString('data-snk-mode', $src);
		self::assertStringContainsString('Colleague', $src);
		self::assertStringNotContainsString("More…", $src);
		self::assertStringNotContainsString('data-snk-form="proxy-log"', $src);
	}

	public function testPulseTopUpHasOneClickRestock(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/pulse.php');
		self::assertStringContainsString('data-snk-action="restock"', $src);
		self::assertStringContainsString('data-default-qty', $src);
		self::assertStringContainsString('data-instant="1"', $src);
		self::assertStringContainsString('snk-list__row', $src);
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString("data-instant') === '1'", $js);
	}

	public function testCatalogPrimaryActionsOnlyWithMoreDetails(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/catalog.php');
		self::assertStringContainsString('snk-row-actions', $src);
		self::assertStringContainsString('snk-row-actions__panel', $src);
		self::assertStringContainsString('snk-row-more', $src);
		self::assertStringContainsString('snk-restock-dialog', $src);
		self::assertStringContainsString('snk-table-wrap', $src);
		self::assertStringContainsString('catalog-deactivate', $src);
		self::assertStringContainsString('data-instant="1"', $src);
		self::assertStringContainsString('Restock other amount', $src);
		self::assertStringContainsString('Actions for %s', $src);
		// Restock must not rely on window.prompt in JS
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringNotContainsString("window.prompt('Add quantity'", $js);
		self::assertStringNotContainsString('window.confirm(', $js);
		self::assertStringNotContainsString('window.prompt(', $js);
		self::assertStringContainsString('snkConfirm', $js);
		self::assertStringContainsString('catalog-restock', $js);
		self::assertStringContainsString('ensureRestockDialog', $js);
		self::assertStringContainsString('wireRowActionMenus', $js);
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-row-actions__panel', $css);
		self::assertStringContainsString('.snk-row-actions__item--danger', $css);
	}

	public function testSettingsNavIsLeanAndLabeled(): void
	{
		$shell = (string)file_get_contents($this->root() . '/templates/pages/settings.php');
		$nav = (string)file_get_contents($this->root() . '/templates/parts/settings-nav.php');
		$benefits = (string)file_get_contents($this->root() . '/templates/parts/settings/benefits.php');
		$catalog = (string)file_get_contents($this->root() . '/lib/Service/SettingsSectionCatalog.php');
		self::assertStringContainsString('settings-nav.php', $shell);
		self::assertStringContainsString('Settings pages', $nav);
		self::assertStringContainsString("'unlock'", $catalog);
		self::assertStringContainsString('Unlock PIN / QR', $catalog);
		self::assertStringNotContainsString("'periods'", $shell);
		// UX-30: hospitality Save stays enabled; incomplete hospitality auto-clears on submit.
		self::assertStringContainsString('snk-benefits-save', $benefits);
		self::assertStringContainsString('save.disabled = false', $benefits);
		self::assertStringContainsString('aria-disabled', $benefits);
		self::assertStringContainsString('Monthly subsidy (€)', $benefits);
		self::assertStringContainsString('subsidyAllowanceEuro', $benefits);
		self::assertStringContainsString('snk-switch-field', $benefits);
		self::assertStringContainsString('role="switch"', $benefits);
		self::assertStringContainsString('id="snk-hosp-enabled"', $benefits);
		self::assertStringContainsString('const on = !!en.checked;', $benefits);
		self::assertStringNotContainsString('en.value === \'1\'', $benefits);
		self::assertStringNotContainsString('save.disabled = block', $benefits);
	}

	public function testChipPickerRequiresExplicitTarget(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('Choose… then search', $js);
		self::assertStringContainsString('updateChipHints', $js);
		self::assertStringContainsString('resolveChipTargetForSearch', $js);
		self::assertStringContainsString('findUserSearchNear', $js);
		self::assertStringContainsString("role', 'combobox'", $js);
		self::assertStringContainsString('snk-chip__remove', $js);
		self::assertStringContainsString('removeChipId', $js);
		self::assertStringContainsString('renderChipList', $js);
		self::assertStringNotContainsString("|| document.querySelector('.snk-chip-target');", $js);
	}

	public function testNavUsesRoleBuckets(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/common/navigation.php');
		$main = (string)file_get_contents($this->root() . '/templates/main.php');
		self::assertStringContainsString('snk-nav__group', $src);
		self::assertStringContainsString("'Kitchen'", $src);
		self::assertStringContainsString("'Money'", $src);
		self::assertStringContainsString('id="app-navigation"', $src);
		self::assertStringContainsString('snk-skip-link', $main);
		self::assertStringContainsString('snk-page-header', $main);
	}

	public function testCssHasGiantTouchAndTableScroll(): void
	{
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-table-wrap', $css);
		self::assertStringContainsString('overflow-x: auto', $css);
		self::assertStringContainsString('--snk-touch: 44px', $css);
		self::assertStringContainsString('.snk-list__row', $css);
		self::assertStringContainsString('skip-link', $css);
		self::assertStringContainsString(':focus-visible', $css);
		self::assertStringContainsString('@media (max-width: 768px)', $css);
		foreach (['mymonth.php', 'audit.php', 'periods.php', 'hospitality.php', 'brreport.php', 'users.php', 'catalog.php'] as $page) {
			$html = (string)file_get_contents($this->root() . '/templates/pages/' . $page);
			if (str_contains($html, 'snk-table')) {
				self::assertStringContainsString('snk-table-wrap', $html, "$page tables must be wrapped");
			}
		}
	}

	public function testTerminalTokenSurfacesInlinePanel(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('snk-terminal-token-panel', $js);
		self::assertStringContainsString('Copy token', $js);
	}

	public function testAccessibilityTreeBasicsInChrome(): void
	{
		$main = (string)file_get_contents($this->root() . '/templates/main.php');
		self::assertStringContainsString('snk-skip-link', $main);
		self::assertStringContainsString('id="snk-main-content"', $main);
		self::assertStringContainsString('tabindex="-1"', $main);
		self::assertStringContainsString('aria-label', $main);
	}

	public function testShoppingListOffersPrintHtml(): void
	{
		$pulse = (string)file_get_contents($this->root() . '/templates/pages/pulse.php');
		self::assertStringContainsString('shopping-print', $pulse);
		self::assertMatchesRegularExpression(
			'/\$exportList !== \[\][\s\S]{0,400}shopping-print/',
			$pulse
		);
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString("format', 'html'", $js);
		self::assertStringContainsString('shopping-print', $js);
	}

	public function testDeadEndsOfferOneClickRecovery(): void
	{
		$log = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		self::assertStringContainsString('data-snk-action="focus-site"', $log);
		$users = (string)file_get_contents($this->root() . '/templates/pages/users.php');
		self::assertStringContainsString('Open Catalog', $users);
		self::assertStringContainsString('Open next period', $users);
		$hosp = (string)file_get_contents($this->root() . '/templates/pages/hospitality.php');
		self::assertStringContainsString('Log a snack', $hosp);
		self::assertStringContainsString('Open Benefits', $hosp);
		$br = (string)file_get_contents($this->root() . '/templates/pages/brreport.php');
		self::assertMatchesRegularExpression(
			'/!empty\(\$report\[\'byCategory\'\]\)[\s\S]{0,200}Download CSV/',
			$br
		);
	}

	public function testLogMultiSitePickAndA11yPriceLabels(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		$tile = (string)file_get_contents($this->root() . '/templates/parts/snk-log-tile.php');
		self::assertStringContainsString('sitePickRequired', $src);
		self::assertStringContainsString('Pick a site above', $src);
		self::assertStringContainsString(" \$item['name'] . ' — ' . \$priceLabel", $tile);
		self::assertStringContainsString('maxlength="500"', $src);
		self::assertStringContainsString("!empty(\$_['canProxy'])", $src);
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertStringContainsString('requireExplicitSiteId', $page);
		self::assertStringContainsString('sitePickRequired', $page);
	}

	public function testHospitalityOverviewShowsSiteWhenMultiSite(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/hospitality.php');
		self::assertStringContainsString("!empty(\$_['multiSite'])", $src);
		self::assertStringContainsString("site_name", $src);
	}

	public function testCreateLogUsesRequireExplicitSiteId(): void
	{
		$api = (string)file_get_contents($this->root() . '/lib/Controller/ApiController.php');
		self::assertMatchesRegularExpression(
			'/function createLog[\s\S]{0,500}requireExplicitSiteId/',
			$api
		);
		self::assertStringNotContainsString(
			"resolveScopeSiteId((int)\$this->request->getParam('siteId')",
			$api
		);
	}

	public function testSitesAndHospitalityRedirectWhenFeatureOff(): void
	{
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertMatchesRegularExpression(
			'/function hospitality\(\): TemplateResponse\|RedirectResponse/',
			$page
		);
		self::assertMatchesRegularExpression(
			'/function sites\(\): TemplateResponse\|RedirectResponse/',
			$page
		);
		self::assertStringContainsString('isHospitalityEnabled()', $page);
		self::assertStringContainsString('isMultiSiteEnabled()', $page);
	}

	public function testStarterCatalogPostsScopedSiteId(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString("action === 'starter'", $js);
		self::assertMatchesRegularExpression(
			"/action === 'starter'[\s\S]{0,400}starterBody\.siteId/",
			$js
		);
		self::assertStringContainsString("/apps/snackcheck/api/catalog/starter", $js);
	}
}
