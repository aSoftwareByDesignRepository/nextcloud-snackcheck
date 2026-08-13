<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\License;

use OCA\SnackCheck\Config\VendorPublicKey;
use OCA\SnackCheck\License\Snk2Codec;
use OCA\SnackCheck\Tests\Support\Snk2TestSigning;
use PHPUnit\Framework\TestCase;

/**
 * T1–T12 SNK2 matrix (codec-level + golden fixtures).
 */
class Snk2CodecGoldenTest extends TestCase
{
	protected function setUp(): void
	{
		putenv('SNK_VENDOR_PUBLIC_KEY_B64=' . Snk2TestSigning::publicKeyB64());
		putenv('SNK_ALLOW_VENDOR_KEY_OVERRIDE=1');
		self::assertSame(Snk2TestSigning::publicKeyB64(), VendorPublicKey::TEST_PUBLIC_KEY_B64);
	}

	public function testT11GoldenFixtureByteIdentical(): void
	{
		$path = __DIR__ . '/../../fixtures/license_snk2_golden.json';
		$data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
		putenv('SNK_VENDOR_PUBLIC_KEY_B64=' . $data['publicKeyB64']);
		$parsed = Snk2Codec::parseAndVerify($data['wireKey']);
		self::assertNotNull($parsed);
		self::assertSame($data['payload'], $parsed['payload']);
		self::assertSame($data['payloadB64'], $parsed['payloadB64']);
		self::assertSame($data['signatureB64'], $parsed['signatureB64']);
	}

	public function testT11BundleGolden(): void
	{
		$path = __DIR__ . '/../../fixtures/license_snk2_bundle_golden.json';
		$data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
		putenv('SNK_VENDOR_PUBLIC_KEY_B64=' . $data['publicKeyB64']);
		$parsed = Snk2Codec::parseAndVerify($data['wireKey']);
		self::assertNotNull($parsed);
		self::assertTrue($parsed['payload']['bundle'] ?? false);
	}

	public function testT1ValidKey(): void
	{
		$wire = Snk2TestSigning::sign([
			'v' => 2,
			'product' => 'snackcheck',
			'customerId' => 'acme-gmbh',
			'issuedAt' => '2026-01-01',
			'validUntil' => '2027-12-31',
			'mobileSeats' => 0,
			'terminalDevices' => 2,
		]);
		$parsed = Snk2Codec::parseAndVerify($wire);
		self::assertNotNull($parsed);
		self::assertSame(2, $parsed['payload']['terminalDevices']);
	}

	public function testT2TamperedPayload(): void
	{
		$wire = Snk2TestSigning::sign([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'acme-gmbh',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 1,
		]);
		$parts = explode('.', $wire);
		$bytes = VendorPublicKey::base64urlDecode($parts[1]);
		$bytes[5] = $bytes[5] === 'A' ? 'B' : 'A';
		$tampered = $parts[0] . '.' . VendorPublicKey::base64urlEncode($bytes) . '.' . $parts[2];
		self::assertNull(Snk2Codec::parseAndVerify($tampered));
		self::assertSame(Snk2Codec::ERROR_INVALID_SIGNATURE, Snk2Codec::classifyApplyError($tampered));
	}

	public function testT3ReorderedJsonRejected(): void
	{
		$payload = [
			'terminalDevices' => 1,
			'mobileSeats' => 0,
			'validUntil' => '2027-12-31',
			'issuedAt' => '2026-01-01',
			'customerId' => 'acme-gmbh',
			'product' => 'snackcheck',
			'v' => 2,
		];
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$kp = Snk2TestSigning::keyPairFromSeed();
		$sig = sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($kp));
		$wire = 'SNK2.' . VendorPublicKey::base64urlEncode($json) . '.' . VendorPublicKey::base64urlEncode($sig);
		self::assertNull(Snk2Codec::parseAndVerify($wire));
	}

	public function testT4WrongProduct(): void
	{
		$wire = Snk2TestSigning::sign([
			'v' => 2, 'product' => 'deskcheck', 'customerId' => 'acme-gmbh',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 1,
		]);
		self::assertNull(Snk2Codec::parseAndVerify($wire));
		self::assertSame(Snk2Codec::ERROR_INVALID_PAYLOAD, Snk2Codec::classifyApplyError($wire));
	}

	public function testT5Expired(): void
	{
		$wire = Snk2TestSigning::sign([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'acme-gmbh',
			'issuedAt' => '2020-01-01', 'validUntil' => '2020-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 1,
		]);
		self::assertSame(Snk2Codec::ERROR_EXPIRED, Snk2Codec::classifyApplyError($wire));
	}

	public function testT6NoProducts(): void
	{
		$wire = Snk2TestSigning::sign([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'acme-gmbh',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 0,
		]);
		// validatePayloadFields fails before sign round-trip usefully — craft invalid counters
		self::assertFalse(Snk2Codec::validatePayloadFields([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'acme-gmbh',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 0,
		]));
	}

	public function testT7ForeignPrefixRejected(): void
	{
		$wire = 'DKC2.aaa.bbb';
		self::assertSame(Snk2Codec::ERROR_INVALID_FORMAT, Snk2Codec::classifyApplyError($wire));
	}

	public function testT8Boundaries(): void
	{
		self::assertTrue(Snk2Codec::validatePayloadFields([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'abc',
			'issuedAt' => '2026-01-01', 'validUntil' => '2026-01-01',
			'mobileSeats' => 0, 'terminalDevices' => 1,
		]));
		self::assertFalse(Snk2Codec::validatePayloadFields([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'abc',
			'issuedAt' => '2026-01-01', 'validUntil' => '2026-01-01',
			'mobileSeats' => 0, 'terminalDevices' => 1001,
		]));
		self::assertFalse(Snk2Codec::validatePayloadFields([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'abc',
			'issuedAt' => '2026-01-01', 'validUntil' => '2026-01-01',
			'mobileSeats' => 10001, 'terminalDevices' => 0,
		]));
	}

	public function testT10BuildEnvelopeRoundTripShape(): void
	{
		$wire = Snk2TestSigning::sign([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'test',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 1,
		]);
		$parsed = Snk2Codec::parseAndVerify($wire);
		self::assertNotNull($parsed);
		$rebuild = Snk2Codec::FORMAT . '.' . $parsed['payloadB64'] . '.' . $parsed['signatureB64'];
		self::assertNotNull(Snk2Codec::parseAndVerify($rebuild));
	}

	public function testWhitespaceNormalized(): void
	{
		$wire = Snk2TestSigning::sign([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'test',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 1,
		]);
		$wrapped = substr($wire, 0, 20) . "\n" . substr($wire, 20);
		self::assertNotNull(Snk2Codec::parseAndVerify($wrapped));
	}

	public function testInstanceIdRejected(): void
	{
		self::assertFalse(Snk2Codec::validatePayloadFields([
			'v' => 2, 'product' => 'snackcheck', 'customerId' => 'abc',
			'issuedAt' => '2026-01-01', 'validUntil' => '2027-12-31',
			'mobileSeats' => 0, 'terminalDevices' => 1,
			'instanceId' => 'x',
		]));
	}
}
