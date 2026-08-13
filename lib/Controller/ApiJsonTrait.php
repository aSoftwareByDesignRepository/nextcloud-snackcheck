<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Controller;

use OCA\SnackCheck\Exception\DomainException;
use OCA\SnackCheck\Exception\PaymentRequiredException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

trait ApiJsonTrait
{
	protected function ok(mixed $data = null, int $status = Http::STATUS_OK): JSONResponse
	{
		return new JSONResponse(['ok' => true, 'data' => $data], $status);
	}

	protected function fail(string $code, int $status, ?string $message = null): JSONResponse
	{
		$body = [
			'ok' => false,
			'error' => [
				'code' => $code,
				'message' => $message ?? $code,
			],
		];
		if ($status === Http::STATUS_PAYMENT_REQUIRED) {
			$body['error']['type'] = 'payment_required';
		}
		return new JSONResponse($body, $status);
	}

	protected function fromDomain(\Throwable $e): JSONResponse
	{
		// Web AGPL surface must NEVER return HTTP 402 (SNK2 is device-only).
		if ($e instanceof PaymentRequiredException) {
			return $this->fail($e->getMessage() ?: 'license_required', Http::STATUS_BAD_REQUEST);
		}
		if ($e instanceof DomainException) {
			$status = $e->httpStatus;
			if ($status === Http::STATUS_PAYMENT_REQUIRED) {
				$status = Http::STATUS_BAD_REQUEST;
			}
			$res = $this->fail($e->errorCode, $status, $e->getMessage());
			if ($status === Http::STATUS_TOO_MANY_REQUESTS || $e->errorCode === 'rate_limited') {
				$retry = $e->retryAfterSeconds ?? \OCA\SnackCheck\Service\RateLimitService::RETRY_AFTER_SECONDS;
				$res->addHeader('Retry-After', (string)$retry);
			}
			return $res;
		}
		return $this->fail('server_error', Http::STATUS_INTERNAL_SERVER_ERROR, 'Unexpected error');
	}
}
