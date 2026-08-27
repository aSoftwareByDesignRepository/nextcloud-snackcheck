<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Ux;

use PHPUnit\Framework\TestCase;

/**
 * Switch-regression + FormData last-wins + multi-site confirm contracts.
 * Kills the select→checkbox UX-30 false-positive (`value === '1'` always true).
 */
final class SettingsSwitchUxContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testHospitalitySyncUsesCheckedOnly(): void
	{
		$benefits = (string)file_get_contents($this->root() . '/templates/parts/settings/benefits.php');
		$privacy = (string)file_get_contents($this->root() . '/templates/parts/settings/privacy.php');
		$digests = (string)file_get_contents($this->root() . '/templates/parts/settings/digests.php');
		self::assertStringContainsString('const on = !!en.checked;', $benefits);
		self::assertStringNotContainsString('en.value === \'1\'', $benefits);
		self::assertStringNotContainsString('en.checked || en.value', $benefits);
		self::assertStringContainsString('id="snk-hosp-enabled"', $benefits);
		self::assertStringContainsString('role="switch"', $benefits);
		self::assertMatchesRegularExpression(
			'/<input type="hidden" name="hospitalityEnabled" value="0"\s*\/>/',
			$benefits
		);
		self::assertMatchesRegularExpression(
			'/<input type="hidden" name="multiSiteEnabled" value="0"\s*\/>/',
			$benefits
		);
		self::assertMatchesRegularExpression(
			'/<input type="hidden" name="privacyTotalsOnly" value="0"\s*\/>/',
			$privacy
		);
		self::assertMatchesRegularExpression(
			'/<input type="hidden" name="personalDigestEnabled" value="0"\s*\/>/',
			$digests
		);
	}

	public function testSettingsFormDataUsesLastWinsNotObjectFromEntriesAlone(): void
	{
		$js = (string)file_get_contents($this->root() . '/js/app.js');
		self::assertStringContainsString('formBodyLastWins', $js);
		self::assertStringContainsString('fd.forEach(function (value, key)', $js);
		self::assertStringContainsString('body[key] = value', $js);
		self::assertStringContainsString('snk-settings-saved', $js);
		self::assertStringNotContainsString('HTMLFormElement.prototype.submit', $js);
		self::assertStringContainsString("dispatchEvent(new Event('submit'", $js);
		self::assertStringContainsString("ms.setAttribute('data-was', '0')", $js);
		self::assertStringContainsString('WeakMap', $js);
		self::assertStringNotContainsString('Object.fromEntries(fd.entries())', $js);
		self::assertStringContainsString("data-snk-busy", $js);
	}

	public function testMutatingApiActionsAreNotCsrfExempt(): void
	{
		$src = (string)file_get_contents($this->root() . '/lib/Controller/ApiController.php');
		foreach ([
			'function saveSettings',
			'function createLog',
			'function setUnlockPin',
			'function setUnlockQr',
			'function createSite',
			'function updateSite',
			'function voidLog',
		] as $fn) {
			self::assertMatchesRegularExpression(
				'/\#\[NoAdminRequired\]\s*\n\s*public ' . preg_quote($fn, '/') . '/',
				$src,
				"$fn must stay CSRF-protected (NoAdminRequired only)"
			);
			self::assertDoesNotMatchRegularExpression(
				'/\#\[NoCSRFRequired\]\s*\n\s*public ' . preg_quote($fn, '/') . '/',
				$src,
				"$fn must NOT be NoCSRFRequired"
			);
		}
	}
}
