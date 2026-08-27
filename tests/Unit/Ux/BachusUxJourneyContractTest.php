<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Bachus UX journey contracts — friction collapses must stay effortless and accessible.
 */
final class BachusUxJourneyContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testPulseInstantRestockDoesNotOpenDialog(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertMatchesRegularExpression(
			"/data-instant'\) === '1'[\s\S]{0,400}catalog\/' \+ itemId \+ '\/restock/",
			$js
		);
		self::assertStringContainsString('Restocked', $js);
	}

	public function testCatalogRestockPlusOneIsInstant(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/catalog.php');
		self::assertStringContainsString('data-instant="1"', $src);
		self::assertStringContainsString('Restock +1', $src);
		self::assertStringContainsString('Restock other amount', $src);
		// Bachus: Restock +1 is the always-visible primary; Edit secondary; other under More.
		self::assertMatchesRegularExpression(
			'/snk-btn--primary[\s\S]{0,200}data-instant="1"[\s\S]{0,120}Restock \+1/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/data-instant="1"[\s\S]{0,400}data-snk-action="edit-item"/',
			$src
		);
	}

	public function testChipFocusJumpsToSearch(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('snk-input--ready', $js);
		self::assertStringContainsString('search.focus()', $js);
		self::assertStringContainsString('resolveChipTargetForSearch', $js);
	}

	public function testNavBucketsAndActiveStateCss(): void
	{
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-nav__group-label', $css);
		self::assertStringContainsString('.snk-nav__link.is-active', $css);
		self::assertStringContainsString('#app-navigation.snk-nav', $css);
		self::assertStringContainsString('snk-app--catalog .snk-table tr', $css);
		self::assertStringContainsString('--snk-touch: 44px', $css);
	}

	public function testAccessibilityTreeBasicsStayIntact(): void
	{
		$main = (string)file_get_contents($this->root() . '/templates/main.php');
		$nav = (string)file_get_contents($this->root() . '/templates/common/navigation.php');
		self::assertStringContainsString('snk-skip-link', $main);
		self::assertStringContainsString('id="snk-main-content"', $main);
		self::assertStringContainsString('tabindex="-1"', $main);
		self::assertStringContainsString('snk-page-stack', $main);
		self::assertStringContainsString('aria-labelledby="snk-nav-', $nav);
		self::assertStringContainsString('id="snk-live-region"', $main);
		self::assertStringContainsString('aria-current="page"', $nav);
		self::assertStringContainsString('Choose a site', $main);
		self::assertStringContainsString('id="app-navigation"', $nav);
		self::assertStringContainsString('snk-page-header', $main);
	}

	public function testPagesUseCardChromeAndIconEmptyStates(): void
	{
		$log = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		self::assertStringContainsString('snk-card__header', $log);
		self::assertStringContainsString('snk-empty-state.php', $log);
		self::assertStringContainsString('Tap a snack', $log);
		self::assertStringContainsString('data-snk-action="focus-site"', $log);
		self::assertStringContainsString('Choose site', $log);
		$pulse = (string)file_get_contents($this->root() . '/templates/pages/pulse.php');
		self::assertStringContainsString('snk-card__title', $pulse);
		self::assertStringContainsString('One tap restocks', $pulse);
		// Hollow CSV/Print CTAs must not appear when Top-up list is empty.
		self::assertMatchesRegularExpression(
			'/\$exportList !== \[\][\s\S]{0,400}shopping-csv/',
			$pulse
		);
		self::assertStringNotContainsString('Shopping list', $pulse);
		$catalog = (string)file_get_contents($this->root() . '/templates/pages/catalog.php');
		self::assertStringContainsString('snk-card--table-solo', $catalog);
		self::assertMatchesRegularExpression(
			'/snk-btn--secondary[\s\S]{0,120}data-snk-action="edit-item"/',
			$catalog
		);
		self::assertMatchesRegularExpression(
			'/snk-btn--primary[\s\S]{0,200}data-snk-action="restock"[\s\S]{0,200}data-instant="1"[\s\S]{0,120}Restock \+1/',
			$catalog
		);
		self::assertStringNotContainsString("\$l->t('Tags')", $catalog);
		self::assertStringContainsString("\$l->t('Stock')", $catalog);
		$users = (string)file_get_contents($this->root() . '/templates/pages/users.php');
		self::assertStringContainsString('Open Catalog', $users);
		self::assertStringContainsString('data-snk-action="focus-site"', $users);
		self::assertStringContainsString('See My month', $users);
		$sites = (string)file_get_contents($this->root() . '/templates/pages/sites.php');
		self::assertStringContainsString('never type raw IDs', $sites);
		self::assertStringNotContainsString('Manager user ids', $sites);
	}

	public function testChipHintReplacesFieldPlaceholder(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString("replace('{field}', name)", $js);
		self::assertStringContainsString('Search below → adding to {field}', $js);
		self::assertStringContainsString("form.setAttribute('aria-busy', 'true')", $js);
		self::assertStringContainsString("announce(t('Saving…'", $js);
		self::assertStringContainsString("action === 'focus-site'", $js);
		self::assertStringContainsString("getElementById('snk-site-select')", $js);
	}

	public function testMobileCardStackIncludesPeriodsAndBrReport(): void
	{
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-app--periods .snk-table-wrap', $css);
		self::assertStringContainsString('.snk-app--brreport .snk-table-wrap', $css);
		self::assertStringContainsString('.snk-app--periods .snk-table thead', $css);
		self::assertStringContainsString('.snk-app--brreport .snk-table tr', $css);
		self::assertStringContainsString('.snk-list__row .snk-btn', $css);
		self::assertStringContainsString('.snk-settings-nav', $css);
	}

	public function testMoneyConfirmsRemainDialogBased(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('snkConfirm', $js);
		self::assertStringNotContainsString('window.confirm(', $js);
		self::assertStringContainsString('Deactivate this site?', $js);
		$periods = (string)file_get_contents($this->root() . '/templates/pages/periods.php');
		self::assertStringContainsString('snk-close-dialog', $periods);
		self::assertStringContainsString('snk-reopen-dialog', $periods);
	}

	public function testLogTileTapQtyAndModeJourney(): void
	{
		self::assertStringContainsString('Tap a snack. Done.', $main = (string)file_get_contents($this->root() . '/templates/main.php'));
		self::assertStringContainsString('id="snk-log-lead"', $main);
		$log = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		self::assertStringContainsString('snk-log-advanced', $log);
		self::assertStringContainsString('snk-qty-chip', $log);
		self::assertStringContainsString('data-snk-qty', $log);
		self::assertStringContainsString('[1, 2, 3, 5]', $log);
		self::assertStringContainsString('data-snk-mode', $log);
		self::assertStringContainsString('snk-proxy-panel.php', $log);
		self::assertStringContainsString('snk-mode-hospitality', $log);
		self::assertStringContainsString('snk-mode-bar--surface', $log);
		self::assertStringContainsString('quantity', $log);
		self::assertStringContainsString('data-snk-log-find', $log);
		self::assertStringContainsString('snk-log-group', $log);
		$tile = (string)file_get_contents($this->root() . '/templates/parts/snk-log-tile.php');
		self::assertStringContainsString('snk-tile__media', $tile);
		self::assertStringContainsString('snk-log-tile.php', $log);
		$proxy = (string)file_get_contents($this->root() . '/templates/parts/snk-proxy-panel.php');
		self::assertStringContainsString('snk-mode-proxy', $proxy);
		self::assertStringContainsString('$autoReady = true', $proxy);
		self::assertStringContainsString('Find a colleague', $proxy);
		self::assertStringContainsString('data-snk-search-scope="access"', $proxy);
		self::assertStringContainsString('snk-filter-panel', $proxy);
		self::assertStringContainsString('snk-mode-panel--embedded', $proxy);
		self::assertStringContainsString('snk-proxy-pick__step', $proxy);
		self::assertStringNotContainsString('Choose… then search', $proxy);
		self::assertStringContainsString('snk-filter-panel', $log);
		self::assertStringContainsString('snk-quick-filters', $log);
		$users = (string)file_get_contents($this->root() . '/templates/pages/users.php');
		self::assertStringContainsString('$embedded = true', $users);
		$pulse = (string)file_get_contents($this->root() . '/templates/pages/pulse.php');
		self::assertStringContainsString('snk-quick-filters', $pulse);
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-filter-panel', $css);
		self::assertStringContainsString('border-inline-start: 4px solid var(--snk-primary)', $css);
		self::assertStringContainsString('.snk-quick-filters', $css);
		self::assertStringContainsString('.snk-mode-panel--embedded', $css);
		self::assertDoesNotMatchRegularExpression(
			'/\.snk-filter-bar\s*\{[^}]*border-left:\s*4px/',
			$css
		);
		$chip = (string)file_get_contents($this->root() . '/templates/parts/snk-chip-field.php');
		self::assertStringContainsString('data-snk-chip-auto', $chip);
		// Progressive disclosure: who-for → mode panels → tiles → qty inside More options.
		self::assertMatchesRegularExpression(
			'/snk-mode-bar--surface[\s\S]*snk-proxy-panel\.php[\s\S]*snk-tile-grid[\s\S]*<details[^>]*snk-log-advanced[\s\S]*snk-qty-chip[\s\S]*<\/details>/',
			$log
		);
		self::assertMatchesRegularExpression(
			'/<details[^>]*snk-log-advanced[\s\S]*snk-qty-chip[\s\S]*<\/details>/',
			$log
		);
		self::assertStringNotContainsString('data-snk-form="proxy-log"', $log);
		self::assertStringNotContainsString('data-snk-form="hospitality-log"', $log);
		self::assertStringNotContainsString('<select name="itemId"', $log);
		self::assertStringNotContainsString('Open Periods', $log);

		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('snkLogQty', $js);
		self::assertStringContainsString('currentLogMode', $js);
		self::assertStringContainsString("mode === 'proxy'", $js);
		self::assertStringContainsString("mode === 'hospitality'", $js);
		self::assertStringContainsString('activateChipTarget(chips[0])', $js);
		self::assertStringContainsString('data-snk-log-find', $js);
		self::assertStringContainsString('uploadCatalogImage', $js);
		self::assertStringContainsString('aria-busy', $js);
		self::assertStringContainsString('flashTile', $js);
		self::assertStringContainsString('mode: mode', $js);
		self::assertStringContainsString('userFacingError', $js);
	}

	public function testCatalogCreatePrimaryIsNameAndPriceOnly(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/catalog.php');
		self::assertMatchesRegularExpression(
			'/data-snk-form="catalog-create"[\s\S]*?<details class="snk-details">[\s\S]*name="category"/',
			$src
		);
		self::assertMatchesRegularExpression(
			'/data-snk-form="catalog-create"[\s\S]*?<details class="snk-details">[\s\S]*name="onHand"/',
			$src
		);
		// Scope to create form — lead copy also mentions "More options".
		self::assertMatchesRegularExpression(
			'/data-snk-form="catalog-create"[\s\S]*?name="name"[\s\S]*?name="priceEuro"[\s\S]*?<details class="snk-details">[\s\S]*?<summary>\s*<\?php p\(\$l->t\(\'More options\'\)\); \?>/',
			$src
		);
		// Edit dialog also progressive: name/price primary, category under More options.
		self::assertMatchesRegularExpression(
			'/data-snk-form="catalog-update"[\s\S]*?id="snk-edit-name"[\s\S]*?id="snk-edit-price"[\s\S]*?<details class="snk-details">[\s\S]*id="snk-edit-category"/',
			$src
		);
		self::assertStringContainsString('snk-btn--danger', $src);
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('dialog.snk-dialog:not([open])', $css);
		// NC reset kills dialog margin — open dialogs must re-center explicitly.
		self::assertStringContainsString('margin: auto !important', $css);
		self::assertMatchesRegularExpression('/dialog\.snk-dialog\[open\]\s*\{[^}]*inset:\s*0/s', $css);
		self::assertStringContainsString('snk-dialog--edit', $src);
		self::assertStringContainsString('data-snk-initial-focus', $src);
		self::assertStringContainsString('Choose picture', $src);
	}

	public function testPulseTopUpFirstAndRanksCollapsed(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/pulse.php');
		$top = strpos($src, 'Top-up');
		$ranks = strpos($src, "What's selling");
		self::assertNotFalse($top);
		self::assertNotFalse($ranks);
		self::assertLessThan($ranks, $top);
		self::assertStringNotContainsString('snk-details" open', $src);
		self::assertStringContainsString('snk-details--flush', $src);
		self::assertStringContainsString("\$icon = 'fridge'", $src);
		self::assertStringContainsString("\$icon = 'activity'", $src);
		self::assertStringContainsString('Nothing needs topping up', $src);
		self::assertStringNotContainsString('Nothing needs topping up.', $src);
		self::assertStringContainsString('In fridge', $src);
		self::assertStringContainsString('Target', $src);
		self::assertStringNotContainsString('Shopping list', $src);
		self::assertStringContainsString('snk-rank-panel', $src);
		self::assertStringContainsString('snk-rank__place', $src);
		self::assertStringContainsString('snk-rank__bar', $src);
		self::assertStringContainsString('paceWindowDays', $src);
		self::assertStringContainsString('qtySharePct', $src);
	}

	public function testErrorsAreHumanizedNotRawCodes(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('function userFacingError', $js);
		self::assertStringContainsString('period_closed:', $js);
		self::assertStringContainsString('Session expired. Reload the page and try again.', $js);
		self::assertStringContainsString('toast(userFacingError(e), null, true)', $js);
		self::assertStringNotContainsString('toast(String(e.message || e))', $js);
	}

	public function testSitePickRequiredZeroesCurrentSite(): void
	{
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		self::assertStringContainsString("!empty(\$params['sitePickRequired'])", $page);
		self::assertStringContainsString("\$params['currentSiteId'] = 0", $page);
	}

	public function testDeadFlatNavShellsRemoved(): void
	{
		$root = $this->root() . '/templates';
		foreach (['index.php', 'log.php', 'pulse.php', 'catalog.php', 'settings.php', 'sites.php', 'hospitality.php', 'periods.php', 'my-month.php'] as $dead) {
			self::assertFileDoesNotExist($root . '/' . $dead, 'legacy flat-nav shell must stay deleted: ' . $dead);
		}
		self::assertFileExists($root . '/main.php');
		self::assertFileExists($root . '/pages/log.php');
	}

	public function testMyMonthHidesPdfWhenEmpty(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/pages/mymonth.php');
		self::assertMatchesRegularExpression(
			'/\$hasLines\s*\):\s*\?>[\s\S]*?Download PDF/',
			$src
		);
		self::assertStringContainsString('snk-hero__stats', $src);
		self::assertStringContainsString('snk-hero__stat', $src);
		self::assertStringContainsString('aria-labelledby="snk-hero-deduct-label"', $src);
		self::assertStringNotContainsString('snk-hero__meta', $src);
		// PDF must not render in the empty-state branch.
		self::assertDoesNotMatchRegularExpression(
			'/Nothing logged this month yet[\s\S]*Download PDF/',
			$src
		);
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-hero__stats', $css);
		self::assertStringContainsString('.snk-hero__stat', $css);
	}

	public function testBenefitsSaveNeverBrickWalls(): void
	{
		$src = (string)file_get_contents($this->root() . '/templates/parts/settings/benefits.php');
		self::assertStringContainsString('save.disabled = false', $src);
		self::assertStringNotContainsString('save.disabled = block', $src);
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('Hospitality left off', $js);
		self::assertStringContainsString('subsidyAllowanceEuro', $js);
	}

	public function testLogA11yRolesForQtyAndMode(): void
	{
		$log = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		self::assertStringContainsString('role="group"', $log);
		self::assertStringContainsString('role="radiogroup"', $log);
		self::assertStringContainsString('aria-pressed', $log);
		self::assertStringContainsString('snk-log-advanced', $log);
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertStringContainsString('.snk-qty-chip', $css);
		self::assertStringContainsString('.snk-mode-chip', $css);
		self::assertStringContainsString('.snk-tile.is-ok', $css);
		self::assertStringContainsString('.snk-tile.is-err', $css);
	}
}