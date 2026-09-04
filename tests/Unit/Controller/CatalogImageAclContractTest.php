<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Web catalogImage must not be an unauthenticated-to-app photo scrape.
 * Active SKUs require assertAccess; inactive require site manage rights.
 */
final class CatalogImageAclContractTest extends TestCase
{
	public function testCatalogImageEnforcesAccessAndInactiveSiteScope(): void
	{
		$api = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ApiController.php');
		self::assertMatchesRegularExpression(
			'/function catalogImage\(int \$id\)[\s\S]{0,500}assertAccess\(\$user\)/',
			$api,
			'catalogImage must assertAccess before serving blobs'
		);
		self::assertMatchesRegularExpression(
			'/function catalogImage\(int \$id\)[\s\S]{0,700}assertNotCrossSiteDownload\(\)/',
			$api,
			'catalogImage CSRF-exempt GET must reject cross-site downloads'
		);
		self::assertMatchesRegularExpression(
			'/function catalogImage\(int \$id\)[\s\S]{0,900}getActive\(\)[\s\S]{0,200}assertCanManageSite\(\$user/',
			$api,
			'inactive catalog images require assertCanManageSite'
		);
		// Device path keeps site bind (already covered elsewhere) — web must not be uid()-only.
		self::assertDoesNotMatchRegularExpression(
			'/function catalogImage\(int \$id\)[\s\S]{0,180}\$this->uid\(\);\s*\$blob = \$this->catalogImages->read/',
			$api,
			'catalogImage must not serve blobs after bare uid() without ACL'
		);
	}
}
