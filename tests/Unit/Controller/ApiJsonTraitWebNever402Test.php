<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Controller;

use OCA\SnackCheck\Controller\ApiJsonTrait;
use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Exception\PaymentRequiredException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

final class ApiJsonTraitWebNever402Test extends TestCase
{
	private function subject(): object
	{
		return new class {
			use ApiJsonTrait;

			public function expose(\Throwable $e): JSONResponse
			{
				return $this->fromDomain($e);
			}
		};
	}

	public function testPaymentRequiredMapsToBadRequestNot402(): void
	{
		$res = $this->subject()->expose(new PaymentRequiredException('license_required'));
		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$body = $res->getData();
		self::assertFalse($body['ok']);
		self::assertSame('license_required', $body['error']['code']);
		self::assertArrayNotHasKey('type', $body['error']);
	}

	public function testDomain402AlsoCollapsedToBadRequest(): void
	{
		$res = $this->subject()->expose(new DomainException('license_required', 'no', Http::STATUS_PAYMENT_REQUIRED));
		self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}

	public function testOtherDomainStatusesPreserved(): void
	{
		$res = $this->subject()->expose(new DomainException('period_closed', 'closed', 409));
		self::assertSame(409, $res->getStatus());
		self::assertSame('period_closed', $res->getData()['error']['code']);
	}
}
