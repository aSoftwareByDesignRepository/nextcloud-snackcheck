<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Support;

use OCA\SnackCheck\Config\VendorPublicKey;
use OCA\SnackCheck\License\Snk2Codec;

final class Snk2TestSigning
{
	public const SEED_LABEL = 'snackcheck-snk2-test-signing-v1';

	public static function keyPairFromSeed(): string
	{
		$seed = hash('sha256', self::SEED_LABEL, true);
		return sodium_crypto_sign_seed_keypair($seed);
	}

	public static function publicKeyB64(): string
	{
		$kp = self::keyPairFromSeed();
		return VendorPublicKey::base64urlEncode(sodium_crypto_sign_publickey($kp));
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function sign(array $payload): string
	{
		$json = Snk2Codec::canonicalJson($payload);
		$kp = self::keyPairFromSeed();
		$sig = sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($kp));
		return Snk2Codec::FORMAT . '.'
			. VendorPublicKey::base64urlEncode($json) . '.'
			. VendorPublicKey::base64urlEncode($sig);
	}
}
