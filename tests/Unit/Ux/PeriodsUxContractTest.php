<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Periods page: clear status → kitchen filter chips → primary export → separated danger.
 */
final class PeriodsUxContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testCurrentPeriodPanelHierarchy(): void
	{
		$tpl = (string)file_get_contents($this->root() . '/templates/pages/periods.php');
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		$js = (string)file_get_contents($this->root() . '/js/app.js');

		self::assertStringContainsString('snk-period-panel', $tpl);
		self::assertStringContainsString('PeriodDisplay::format', $tpl);
		self::assertStringContainsString('data-snk-payroll-site-filters', $tpl);
		self::assertStringContainsString('snk-period-panel__danger', $tpl);
		self::assertStringContainsString('snk-period-panel__primary', $tpl);
		self::assertStringContainsString('Download payroll package', $tpl);
		self::assertStringContainsString('Line sheets for', $tpl);
		self::assertStringContainsString('All kitchens', $tpl);
		self::assertStringContainsString('User payroll totals always include every kitchen.', $tpl);
		// No duplicated wall of filter notes
		self::assertStringNotContainsString('Payroll site filter', $tpl);
		self::assertStringNotContainsString('Lines and site sheets follow this filter.', $tpl);
		self::assertStringContainsString('id="snk-payroll-site"', $tpl);
		self::assertStringContainsString('role="radiogroup"', $tpl);
		self::assertStringContainsString('role="radio"', $tpl);

		self::assertStringContainsString('.snk-period-panel', $css);
		self::assertStringContainsString('.snk-period-panel__danger', $css);
		self::assertStringContainsString('wirePayrollSiteFilters', $js);
		self::assertStringContainsString('data-snk-payroll-site', $js);
		self::assertStringContainsString("getElementById('snk-payroll-site')", $js);
	}

	public function testHistoryUsesBadgesAndDisplayLabels(): void
	{
		$tpl = (string)file_get_contents($this->root() . '/templates/pages/periods.php');
		self::assertStringContainsString('snk-badge--ok', $tpl);
		self::assertStringContainsString('snk-badge--muted', $tpl);
		self::assertStringContainsString("\$l->t('Closed')", $tpl);
		self::assertStringContainsString("\$l->t('Open')", $tpl);
		self::assertStringContainsString('snk-close-dialog', $tpl);
		self::assertStringContainsString('snk-reopen-dialog', $tpl);
	}

	public function testUserFacingPeriodLabelsNeverRawSuccessorKeys(): void
	{
		$admin = (string)file_get_contents($this->root() . '/lib/Service/AdminTotalsService.php');
		$br = (string)file_get_contents($this->root() . '/lib/Service/BrAggregateService.php');
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');
		$hosp = (string)file_get_contents($this->root() . '/templates/pages/hospitality.php');
		self::assertStringContainsString('PeriodDisplay::format', $admin);
		self::assertStringContainsString('PeriodDisplay::format', $br);
		self::assertStringContainsString("PeriodDisplay::format((string)\$period->getLabel())", $page);
		self::assertStringContainsString('PeriodDisplay::format', $hosp);
		self::assertStringNotContainsString("\$period->getLabel()); ?>", $hosp);
	}
}
