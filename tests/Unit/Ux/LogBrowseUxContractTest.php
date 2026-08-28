<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use OCA\SnackCheck\Service\CatalogImageService;
use OCA\SnackCheck\Service\IconCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Bachus: Log browse must stay scannable — groups, find, pictures, category icons.
 */
final class LogBrowseUxContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testLogGroupsFindAndPictureChrome(): void
	{
		$log = (string)file_get_contents($this->root() . '/templates/pages/log.php');
		$tile = (string)file_get_contents($this->root() . '/templates/parts/snk-log-tile.php');
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		$page = (string)file_get_contents($this->root() . '/lib/Controller/PageController.php');

		self::assertStringContainsString('itemGroups', $page);
		self::assertStringContainsString('groupLogItems', $page);
		self::assertStringContainsString('snk-log-group', $log);
		self::assertStringContainsString('data-snk-log-find', $log);
		self::assertStringContainsString('data-snk-log-cat', $log);
		self::assertStringContainsString('snk-tile__media', $tile);
		self::assertStringContainsString('snk-tile__img', $tile);
		self::assertStringContainsString('IconCatalog::forCategory', $tile);
		self::assertMatchesRegularExpression('/\.snk-tile__media\s*\{[^}]*background:\s*var\(--snk-product-stage\)/', $css);
		self::assertStringContainsString('.snk-log-group__title', $css);
		// Equal card footprint: li fills row; button fills li; media/name/tags reserved.
		self::assertStringContainsString('.snk-tile-grid > li', $css);
		self::assertStringContainsString('repeat(3, minmax(0, 1fr))', $css);
		self::assertStringContainsString('width: 100% !important', $css);
		self::assertMatchesRegularExpression('/\.snk-tile\s*\{[^}]*height:\s*100%/', $css);
		self::assertMatchesRegularExpression('/\.snk-tile__media\s*\{[^}]*aspect-ratio:\s*1/', $css);
		self::assertMatchesRegularExpression('/\.snk-tile__media\s*\{[^}]*min-height:\s*5rem/', $css);
		self::assertMatchesRegularExpression('/\.snk-tile__name\s*\{[^}]*min-height:\s*calc\(1\.3em \* 2\)/', $css);
		self::assertStringContainsString('snk-tile__figure', $tile);
		self::assertStringContainsString('snk-tile__foot', $tile);
		self::assertStringContainsString('snk-tag--hazard', $tile);
		self::assertStringNotContainsString('grayscale', $css);
		self::assertMatchesRegularExpression('/\.snk-tile__tags\s*\{[^}]*min-height:\s*1\.5rem/', $css);
		self::assertStringContainsString('blockedLogFeedback', $js);
		self::assertStringContainsString('data-snk-block-reason="period"', $tile);
		self::assertStringNotContainsString('disabled aria-disabled', $tile);
		self::assertStringContainsString('.snk-toast--ok', $css);
		self::assertStringContainsString('position: fixed', $css);
		self::assertStringContainsString('uploadCatalogImage', $js);
		self::assertStringContainsString('snk-tile__tags', $tile);
		self::assertStringContainsString('snk-tile__price--free', $tile);
		self::assertStringContainsString('aria-hidden="true"', $tile);
		// Category meta text must not clutter every tile when groups exist.
		self::assertStringNotContainsString('snk-tile__meta', $tile);
		// Proxy/Company panels sit above the grid (not buried under quantity).
		self::assertStringContainsString('snk-proxy-panel.php', $log);
		self::assertDoesNotMatchRegularExpression(
			'/<details[^>]*snk-log-advanced[\s\S]*snk-proxy-panel/',
			$log
		);
	}

	public function testCatalogImageServiceSecurityContracts(): void
	{
		self::assertSame(2_097_152, CatalogImageService::MAX_BYTES);
		self::assertArrayHasKey('image/jpeg', CatalogImageService::ALLOWED);
		self::assertArrayHasKey('image/png', CatalogImageService::ALLOWED);
		self::assertArrayHasKey('image/webp', CatalogImageService::ALLOWED);
		self::assertArrayNotHasKey('image/svg+xml', CatalogImageService::ALLOWED);

		$src = (string)file_get_contents($this->root() . '/lib/Service/CatalogImageService.php');
		self::assertStringContainsString('getimagesizefromstring', $src);
		self::assertStringContainsString('reencode', $src);
		self::assertStringContainsString('is_uploaded_file', $src);
		self::assertStringContainsString("item-\\d+-[a-f0-9]{16}", $src);

		$routes = (string)file_get_contents($this->root() . '/appinfo/routes.php');
		self::assertStringContainsString('api#uploadCatalogImage', $routes);
		self::assertStringContainsString('api#catalogImage', $routes);
		self::assertStringContainsString('device_api#catalogImage', $routes);

		$catalog = (string)file_get_contents($this->root() . '/templates/pages/catalog.php');
		self::assertStringContainsString('Picture (optional)', $catalog);
		self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $catalog);
		self::assertStringContainsString('clear-item-image', $catalog);
	}

	public function testCategoryIconsExist(): void
	{
		self::assertSame('coffee', IconCatalog::forCategory('drink'));
		self::assertSame('cookie', IconCatalog::forCategory('snack'));
		self::assertSame('wine', IconCatalog::forCategory('alcohol'));
		self::assertSame('package', IconCatalog::forCategory('other'));
		self::assertSame('package', IconCatalog::forCategory(null));
		self::assertNotSame('', IconCatalog::render('cookie'));
		self::assertNotSame('', IconCatalog::render('wine'));
	}

	public function testMigrationAddsImageColumns(): void
	{
		$mig = (string)file_get_contents($this->root() . '/lib/Migration/Version1008Date20260827170000.php');
		self::assertStringContainsString('image_name', $mig);
		self::assertStringContainsString('image_mime', $mig);
		self::assertStringContainsString('hasColumn', $mig);
	}
}
