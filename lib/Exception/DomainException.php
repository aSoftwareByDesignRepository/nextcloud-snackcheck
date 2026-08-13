<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Exception;

class DomainException extends \RuntimeException
{
	public function __construct(
		public readonly string $errorCode,
		string $message = '',
		public readonly int $httpStatus = 400,
		/** Remaining seconds for Retry-After (unlock lockout / rate limits). */
		public readonly ?int $retryAfterSeconds = null,
	) {
		parent::__construct($message !== '' ? $message : $errorCode, $httpStatus);
	}
}
