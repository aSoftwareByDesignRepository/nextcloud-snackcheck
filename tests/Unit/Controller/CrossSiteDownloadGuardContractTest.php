<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Argus MF: CSRF-exempt GET downloads reject Sec-Fetch-Site: cross-site.
 */
final class CrossSiteDownloadGuardContractTest extends TestCase
{
	public function testAssertNotCrossSiteDownloadWiredOnSensitiveExports(): void
	{
		$api = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertStringContainsString('function assertNotCrossSiteDownload', $api);
		self::assertStringContainsString("\$site === 'cross-site'", $api);
		foreach ([
			'function downloadPayroll',
			'function downloadHospitality',
			'function downloadMyMonthPdf',
			'function complimentaryExport',
			'function shelfQr',
			'function shoppingList',
			'function brReport',
			'function catalogImage',
		] as $fn) {
			self::assertMatchesRegularExpression(
				'/' . preg_quote($fn, '/') . '[\s\S]{0,350}assertNotCrossSiteDownload\(\)/',
				$api,
				$fn . ' must call assertNotCrossSiteDownload'
			);
		}
		// Guard runs for every format (JSON included), not only csv/html.
		self::assertDoesNotMatchRegularExpression(
			'/format === \'csv\' \|\| \$format === \'html\'[\s\S]{0,80}assertNotCrossSiteDownload/',
			$api
		);
		// My-month PDF must be a statement with an explicit total (not a truncated line dump).
		self::assertStringContainsString('SimplePdfBuilder::buildStatement', $api);
		$presenter = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Service/MyMonthStatementPresenter.php');
		self::assertStringContainsString('TOTAL TO DEDUCT', $presenter);
		self::assertStringContainsString("\$l->t('TOTAL TO DEDUCT')", $presenter);
	}
}
