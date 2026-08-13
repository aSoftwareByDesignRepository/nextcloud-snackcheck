<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\License;

use OCA\SnackCheck\License\Snk2Codec;
use PHPUnit\Framework\TestCase;

final class Snk2CodecTest extends TestCase
{
	private string $wire = '';
	private string $publicKey = '';

	protected function setUp(): void
	{
		putenv('SNK_ALLOW_VENDOR_KEY_OVERRIDE=1');
		$fixture = json_decode(
			(string)file_get_contents(__DIR__ . '/../../fixtures/license_snk2_golden.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$this->wire = (string)$fixture['wireKey'];
		$this->publicKey = (string)$fixture['publicKeyB64'];
		putenv('SNK_VENDOR_PUBLIC_KEY_B64=' . $this->publicKey);
	}

	protected function tearDown(): void
	{
		putenv('SNK_VENDOR_PUBLIC_KEY_B64');
		putenv('SNK_ALLOW_VENDOR_KEY_OVERRIDE');
	}

	public function testGoldenAccepted(): void
	{
		$parsed = Snk2Codec::parseAndVerify($this->wire);
		self::assertNotNull($parsed);
		self::assertSame('snackcheck', $parsed['payload']['product']);
		self::assertSame(0, $parsed['payload']['mobileSeats']);
		self::assertSame(1, $parsed['payload']['terminalDevices']);
		self::assertSame('', Snk2Codec::classifyApplyError($this->wire));
	}

	public function testWhitespaceNormalized(): void
	{
		$wrapped = substr($this->wire, 0, 40) . "\n" . substr($this->wire, 40);
		self::assertNotNull(Snk2Codec::parseAndVerify($wrapped));
	}

	public function testWrongPrefixRejected(): void
	{
		$bad = 'DKC2' . substr($this->wire, 4);
		self::assertNull(Snk2Codec::parseAndVerify($bad));
		self::assertSame(Snk2Codec::ERROR_INVALID_FORMAT, Snk2Codec::classifyApplyError($bad));
	}

	public function testTamperedPayloadRejected(): void
	{
		$parts = explode('.', $this->wire);
		$bytes = base64_decode(strtr($parts[1], '-_', '+/'));
		$bytes[5] = $bytes[5] === 'A' ? 'B' : 'A';
		$parts[1] = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		$bad = implode('.', $parts);
		self::assertNull(Snk2Codec::parseAndVerify($bad));
		self::assertSame(Snk2Codec::ERROR_INVALID_SIGNATURE, Snk2Codec::classifyApplyError($bad));
	}

	public function testCanonicalJsonOrder(): void
	{
		$payload = [
			'terminalDevices' => 1,
			'mobileSeats' => 0,
			'validUntil' => '2027-12-31',
			'issuedAt' => '2026-01-01',
			'customerId' => 'test',
			'product' => 'snackcheck',
			'v' => 2,
		];
		$json = Snk2Codec::canonicalJson($payload);
		self::assertSame(
			'{"v":2,"product":"snackcheck","customerId":"test","issuedAt":"2026-01-01","validUntil":"2027-12-31","mobileSeats":0,"terminalDevices":1}',
			$json,
		);
	}
}
