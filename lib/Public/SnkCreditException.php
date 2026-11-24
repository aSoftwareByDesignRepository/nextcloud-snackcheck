<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Public;

/**
 * Typed failure of the expense-credit write facade. Callers switch on
 * {@see SnkCreditException::$errorCode} — never on the message:
 *
 *   expense_disabled | period_closed | target_no_access | validation_failed
 *   amount_invalid | expense_reason_required | ext_ref_invalid | not_found
 */
final class SnkCreditException extends \RuntimeException
{
	public function __construct(
		public readonly string $errorCode,
		string $message = '',
		public readonly int $httpStatus = 400,
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}
}
