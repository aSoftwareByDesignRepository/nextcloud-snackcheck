<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

/**
 * Shelf QR SVG generator (US-OPP-I) via vendored splitbrain phpQRCode.
 */
class ShelfQrService
{
	public function __construct(
		private readonly CatalogService $catalog,
		private readonly \OCP\IURLGenerator $url,
	) {
	}

	public function absoluteShelfUrl(int $itemId): string
	{
		$this->catalog->get($itemId); // not_found if missing
		return $this->url->linkToRouteAbsolute('snackcheck.page.shelf', ['itemId' => $itemId]);
	}

	public function svgForItem(int $itemId): string
	{
		$url = $this->absoluteShelfUrl($itemId);
		require_once dirname(__DIR__) . '/Vendor/splitbrain/phpQRCode/QRCode.php';
		return \splitbrain\phpQRCode\QRCode::svg($url, ['s' => 'qrm']);
	}
}
