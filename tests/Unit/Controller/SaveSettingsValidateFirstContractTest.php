<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Aristoteles MF — saveSettings must validate hospitality/subsidy before any write.
 */
final class SaveSettingsValidateFirstContractTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 3);
	}

	public function testValidateBeforeSubsidyOrHospitalityWrites(): void
	{
		$src = (string)file_get_contents($this->root() . '/lib/Controller/ApiController.php');
		self::assertStringContainsString('validate the full payload before any appconfig', $src);
		self::assertStringContainsString('parseEuroToCents', $src);
		self::assertStringContainsString('Projected hospitality state', $src);
		// First hospitality enable rejection must appear before setSubsidyAllowanceCents.
		$failPos = strpos($src, "Company user and allowlist required'");
		$subsidyWrite = strpos($src, 'setSubsidyAllowanceCents($subsidyCents)');
		self::assertNotFalse($failPos);
		self::assertNotFalse($subsidyWrite);
		self::assertLessThan($subsidyWrite, $failPos);
		self::assertStringContainsString('function parseEuroToCents', $src);
	}

	public function testCssRestockSubHasNoOpacityFade(): void
	{
		$css = (string)file_get_contents($this->root() . '/css/app.css');
		self::assertMatchesRegularExpression('/\.snk-btn__sub\s*\{[^}]*color:\s*inherit/', $css);
		self::assertDoesNotMatchRegularExpression('/\.snk-btn__sub\s*\{[^}]*opacity:\s*0\.92/', $css);
	}
}
