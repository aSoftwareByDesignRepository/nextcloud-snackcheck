<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Org settings stored in appconfig (no snk_settings table).
 */
class SettingsService
{
	public const APP_ID = 'snackcheck';

	public const KEY_SUBSIDY = 'subsidy_allowance_cents';
	public const KEY_HOSP_ENABLED = 'hospitality_enabled';
	public const KEY_HOSP_COMPANY = 'hospitality_company_user_id';
	public const KEY_MULTI_SITE = 'multi_site_enabled';
	public const KEY_PRIVACY_TOTALS = 'privacy_totals_only';
	public const KEY_PACE_WINDOW = 'pace_window_days';
	public const KEY_RESTOCK_HORIZON = 'restock_horizon_days';
	public const KEY_WEEKLY_TOPUP = 'weekly_topup_email';
	public const KEY_ACCESS_MODE = 'access_mode'; // open|listed
	public const KEY_ACCESS_GROUPS = 'access_groups_json';
	public const KEY_ACCESS_USERS = 'access_users_json';
	public const KEY_APP_ADMINS = 'app_admins_json';
	public const KEY_DIGEST_ENABLED = 'personal_digest_enabled';
	public const KEY_DIGEST_SKIP_ZERO = 'personal_digest_skip_zero';
	public const KEY_EXPENSE_ENABLED = 'expense_entries_enabled';
	public const KEY_EXPENSE_SELF_ENTRY = 'expense_self_entry';
	public const KEY_EXPENSE_SELF_MAX = 'expense_self_entry_max_cents';
	public const KEY_EXPENSE_CLAMP = 'expense_clamp_negative_deduct';
	public const KEY_EXPENSE_ROUTING = 'expense_routing'; // ledger_credit|expense_claim
	public const KEY_EXPENSE_KIOSK = 'expense_kiosk_entry';
	public const EXPENSE_ROUTING_LEDGER = 'ledger_credit';
	public const EXPENSE_ROUTING_CLAIM = 'expense_claim';
	public const KEY_UNLOCK_PEPPER = 'unlock_pin_pepper';
	private const PEPPER_LOCK = 'snackcheck/unlock_pepper';

	public function __construct(
		private readonly IConfig $config,
		private readonly ILockingProvider $locking,
	) {
	}

	/**
	 * Server-side pepper so DB dumps alone cannot rainbow 4–8 digit PINs.
	 * Mint is exclusive-locked so concurrent cold starts cannot diverge hashes.
	 */
	public function getUnlockPepper(): string
	{
		$pepper = (string)$this->config->getAppValue(self::APP_ID, self::KEY_UNLOCK_PEPPER, '');
		if ($pepper !== '' && strlen($pepper) >= 32) {
			return $pepper;
		}

		try {
			$this->locking->acquireLock(self::PEPPER_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			usleep(50_000);
			$pepper = (string)$this->config->getAppValue(self::APP_ID, self::KEY_UNLOCK_PEPPER, '');
			if ($pepper !== '' && strlen($pepper) >= 32) {
				return $pepper;
			}
			throw new \OCA\SnackCheck\Exception\DomainException(
				'unlock_busy',
				'Unlock pepper initialization busy',
				429,
			);
		}

		try {
			$pepper = (string)$this->config->getAppValue(self::APP_ID, self::KEY_UNLOCK_PEPPER, '');
			if ($pepper === '' || strlen($pepper) < 32) {
				$pepper = bin2hex(random_bytes(32));
				$this->config->setAppValue(self::APP_ID, self::KEY_UNLOCK_PEPPER, $pepper);
			}
			return $pepper;
		} finally {
			$this->locking->releaseLock(self::PEPPER_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function getSubsidyAllowanceCents(): int
	{
		return max(0, (int)$this->config->getAppValue(self::APP_ID, self::KEY_SUBSIDY, '0'));
	}

	public function setSubsidyAllowanceCents(int $cents): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_SUBSIDY, (string)max(0, $cents));
	}

	public function isHospitalityEnabled(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_HOSP_ENABLED, '0') === '1';
	}

	public function setHospitalityEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_HOSP_ENABLED, $on ? '1' : '0');
	}

	public function getHospitalityCompanyUserId(): string
	{
		return (string)$this->config->getAppValue(self::APP_ID, self::KEY_HOSP_COMPANY, '');
	}

	public function setHospitalityCompanyUserId(string $uid): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_HOSP_COMPANY, $uid);
	}

	public function isMultiSiteEnabled(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_MULTI_SITE, '0') === '1';
	}

	public function setMultiSiteEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_MULTI_SITE, $on ? '1' : '0');
	}

	public function isPrivacyTotalsOnly(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_PRIVACY_TOTALS, '0') === '1';
	}

	public function setPrivacyTotalsOnly(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_PRIVACY_TOTALS, $on ? '1' : '0');
	}

	public function getPaceWindowDays(): int
	{
		$v = (int)$this->config->getAppValue(self::APP_ID, self::KEY_PACE_WINDOW, '14');
		return in_array($v, [7, 14, 30], true) ? $v : 14;
	}

	public function setPaceWindowDays(int $days): void
	{
		if (!in_array($days, [7, 14, 30], true)) {
			$days = 14;
		}
		$this->config->setAppValue(self::APP_ID, self::KEY_PACE_WINDOW, (string)$days);
	}

	public function getRestockHorizonDays(): int
	{
		return max(1, (int)$this->config->getAppValue(self::APP_ID, self::KEY_RESTOCK_HORIZON, '3'));
	}

	public function setRestockHorizonDays(int $days): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_RESTOCK_HORIZON, (string)max(1, $days));
	}

	public function isWeeklyTopUpEmailEnabled(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_WEEKLY_TOPUP, '0') === '1';
	}

	public function setWeeklyTopUpEmailEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_WEEKLY_TOPUP, $on ? '1' : '0');
	}

	public function isPersonalDigestEnabled(): bool
	{
		// Pack B default ON (EXEC / OPPORTUNITY)
		return $this->config->getAppValue(self::APP_ID, self::KEY_DIGEST_ENABLED, '1') === '1';
	}

	public function setPersonalDigestEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_DIGEST_ENABLED, $on ? '1' : '0');
	}

	/** AC-OPP-B4: skip personal digests when to_deduct is €0 (default ON). */
	public function isPersonalDigestSkipZeroEnabled(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_DIGEST_SKIP_ZERO, '1') === '1';
	}

	public function setPersonalDigestSkipZeroEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_DIGEST_SKIP_ZERO, $on ? '1' : '0');
	}

	/** Expense credits master toggle (default OFF). */
	public function isExpenseEnabled(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_EXPENSE_ENABLED, '0') === '1';
	}

	public function setExpenseEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_EXPENSE_ENABLED, $on ? '1' : '0');
	}

	/** Members may record their own expenses (default ON under master toggle). */
	public function isExpenseSelfEntryEnabled(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_EXPENSE_SELF_ENTRY, '1') === '1';
	}

	public function setExpenseSelfEntryEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_EXPENSE_SELF_ENTRY, $on ? '1' : '0');
	}

	/**
	 * Effective per-entry self-entry cap in cents. Stored 0 (or an
	 * out-of-range legacy value) means "no explicit cap" — the platform
	 * hard maximum still applies, so the effective value is never 0.
	 */
	public function getExpenseSelfEntryMaxCents(): int
	{
		$stored = (int)$this->config->getAppValue(self::APP_ID, self::KEY_EXPENSE_SELF_MAX, '0');
		if ($stored <= 0 || $stored > ConsumptionLogService::EXPENSE_MAX_CENTS) {
			return ConsumptionLogService::EXPENSE_MAX_CENTS;
		}
		return $stored;
	}

	public function setExpenseSelfEntryMaxCents(int $cents): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_EXPENSE_SELF_MAX, (string)max(0, $cents));
	}

	/** Raw stored cap (0 = no explicit limit) — for form fields, not consumers. */
	public function getExpenseSelfEntryMaxCentsStored(): int
	{
		return max(0, (int)$this->config->getAppValue(self::APP_ID, self::KEY_EXPENSE_SELF_MAX, '0'));
	}

	/** Clamp negative deduct at 0 and report the remainder (default OFF = payout). */
	public function isExpenseClampNegativeDeduct(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_EXPENSE_CLAMP, '0') === '1';
	}

	public function setExpenseClampNegativeDeduct(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_EXPENSE_CLAMP, $on ? '1' : '0');
	}

	/**
	 * Stored routing preference. The effective routing additionally requires the
	 * check:expense-claims capability — see getExpenseRouting(bool $claimsPresent).
	 */
	public function getExpenseRoutingStored(): string
	{
		$r = (string)$this->config->getAppValue(self::APP_ID, self::KEY_EXPENSE_ROUTING, self::EXPENSE_ROUTING_LEDGER);
		return $r === self::EXPENSE_ROUTING_CLAIM ? self::EXPENSE_ROUTING_CLAIM : self::EXPENSE_ROUTING_LEDGER;
	}

	/** Effective routing: claim routing force-degrades to ledger when EXP is absent. */
	public function getExpenseRouting(bool $claimsCapabilityPresent = false): string
	{
		$r = $this->getExpenseRoutingStored();
		if ($r === self::EXPENSE_ROUTING_CLAIM && !$claimsCapabilityPresent) {
			return self::EXPENSE_ROUTING_LEDGER;
		}
		return $r;
	}

	public function setExpenseRouting(string $routing): void
	{
		$this->config->setAppValue(
			self::APP_ID,
			self::KEY_EXPENSE_ROUTING,
			$routing === self::EXPENSE_ROUTING_CLAIM ? self::EXPENSE_ROUTING_CLAIM : self::EXPENSE_ROUTING_LEDGER,
		);
	}

	/** Kitchen-tablet expense entry (default OFF). */
	public function isExpenseKioskEntryEnabled(): bool
	{
		return $this->config->getAppValue(self::APP_ID, self::KEY_EXPENSE_KIOSK, '0') === '1';
	}

	public function setExpenseKioskEntryEnabled(bool $on): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_EXPENSE_KIOSK, $on ? '1' : '0');
	}

	public function getAccessMode(): string
	{
		$m = $this->config->getAppValue(self::APP_ID, self::KEY_ACCESS_MODE, 'open');
		return $m === 'listed' ? 'listed' : 'open';
	}

	public function setAccessMode(string $mode): void
	{
		$this->config->setAppValue(self::APP_ID, self::KEY_ACCESS_MODE, $mode === 'listed' ? 'listed' : 'open');
	}

	/** @return list<string> */
	public function getAccessGroups(): array
	{
		return $this->decodeList(self::KEY_ACCESS_GROUPS);
	}

	/** @param list<string> $groups */
	public function setAccessGroups(array $groups): void
	{
		$this->encodeList(self::KEY_ACCESS_GROUPS, $groups);
	}

	/** @return list<string> */
	public function getAccessUsers(): array
	{
		return $this->decodeList(self::KEY_ACCESS_USERS);
	}

	/** @param list<string> $users */
	public function setAccessUsers(array $users): void
	{
		$this->encodeList(self::KEY_ACCESS_USERS, $users);
	}

	/** @return list<string> */
	public function getAppAdmins(): array
	{
		return $this->decodeList(self::KEY_APP_ADMINS);
	}

	/** @param list<string> $users */
	public function setAppAdmins(array $users): void
	{
		$this->encodeList(self::KEY_APP_ADMINS, $users);
	}

	/**
	 * Per-key write normalisation shared with the config-transfer import
	 * (validator parity, spec NN-08): the exact clamp/enum logic the typed
	 * setters apply, addressable by appconfig key. Throws
	 * \InvalidArgumentException for unknown keys so invalid file values are
	 * rejected identically to what the settings API would accept.
	 */
	public function normalizeForWrite(string $key, mixed $raw): string
	{
		$v = is_string($raw) ? trim($raw) : '';
		switch ($key) {
			case self::KEY_SUBSIDY:
				return (string)max(0, (int)$v);
			case self::KEY_HOSP_ENABLED:
			case self::KEY_MULTI_SITE:
			case self::KEY_PRIVACY_TOTALS:
			case self::KEY_WEEKLY_TOPUP:
			case self::KEY_DIGEST_ENABLED:
			case self::KEY_DIGEST_SKIP_ZERO:
			case self::KEY_EXPENSE_ENABLED:
			case self::KEY_EXPENSE_SELF_ENTRY:
			case self::KEY_EXPENSE_CLAMP:
			case self::KEY_EXPENSE_KIOSK:
				// '' is a legitimately stored state (unset-but-present key the
				// getters read as false) — normalise it like the getters do.
				if ($v === '') {
					return '0';
				}
				if (!in_array($v, ['0', '1'], true)) {
					throw new \InvalidArgumentException('INVALID_BOOL');
				}
				return $v;
			case self::KEY_HOSP_COMPANY:
				return $v;
			case self::KEY_PACE_WINDOW:
				if ($v === '') {
					return '14';
				}
				$d = (int)$v;
				if (!in_array($d, [7, 14, 30], true)) {
					throw new \InvalidArgumentException('INVALID_PACE_WINDOW');
				}
				return (string)$d;
			case self::KEY_RESTOCK_HORIZON:
				return (string)max(1, (int)$v);
			case self::KEY_EXPENSE_SELF_MAX:
				return (string)max(0, (int)$v);
			case self::KEY_EXPENSE_ROUTING:
				if ($v === '') {
					return self::EXPENSE_ROUTING_LEDGER;
				}
				if (!in_array($v, [self::EXPENSE_ROUTING_LEDGER, self::EXPENSE_ROUTING_CLAIM], true)) {
					throw new \InvalidArgumentException('INVALID_EXPENSE_ROUTING');
				}
				return $v;
			case 'personal_digest_days_before':
				if ($v === '') {
					return '3';
				}
				$d = (int)$v;
				if ($d < 1 || $d > 14) {
					throw new \InvalidArgumentException('INVALID_DAYS_BEFORE');
				}
				return (string)$d;
			case self::KEY_ACCESS_MODE:
				if (!in_array($v, ['open', 'listed'], true)) {
					throw new \InvalidArgumentException('INVALID_ACCESS_MODE');
				}
				return $v;
			case self::KEY_ACCESS_GROUPS:
			case self::KEY_ACCESS_USERS:
			case self::KEY_APP_ADMINS:
				$list = json_decode($v, true, 8, JSON_THROW_ON_ERROR);
				if (!is_array($list) || array_is_list($list) === false) {
					throw new \InvalidArgumentException('INVALID_ID_LIST');
				}
				$clean = [];
				foreach ($list as $id) {
					if (is_string($id) && $id !== '') {
						$clean[] = $id;
					}
				}
				return json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR);
			default:
				throw new \InvalidArgumentException('UNKNOWN_SETTING_KEY');
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getAll(): array
	{
		return [
			'subsidyAllowanceCents' => $this->getSubsidyAllowanceCents(),
			'hospitalityEnabled' => $this->isHospitalityEnabled(),
			'hospitalityCompanyUserId' => $this->getHospitalityCompanyUserId(),
			'multiSiteEnabled' => $this->isMultiSiteEnabled(),
			'privacyTotalsOnly' => $this->isPrivacyTotalsOnly(),
			'paceWindowDays' => $this->getPaceWindowDays(),
			'restockHorizonDays' => $this->getRestockHorizonDays(),
			'weeklyTopUpEmail' => $this->isWeeklyTopUpEmailEnabled(),
			'personalDigestEnabled' => $this->isPersonalDigestEnabled(),
			'personalDigestSkipZero' => $this->isPersonalDigestSkipZeroEnabled(),
			'personalDigestDaysBefore' => max(1, min(14, (int)$this->config->getAppValue(self::APP_ID, 'personal_digest_days_before', '3'))),
			'expenseEnabled' => $this->isExpenseEnabled(),
			'expenseSelfEntry' => $this->isExpenseSelfEntryEnabled(),
			'expenseSelfEntryMaxCents' => $this->getExpenseSelfEntryMaxCents(),
			'expenseClampNegativeDeduct' => $this->isExpenseClampNegativeDeduct(),
			'expenseRouting' => $this->getExpenseRoutingStored(),
			'expenseKioskEntry' => $this->isExpenseKioskEntryEnabled(),
			'accessMode' => $this->getAccessMode(),
			'accessGroups' => $this->getAccessGroups(),
			'accessUsers' => $this->getAccessUsers(),
			'appAdmins' => $this->getAppAdmins(),
		];
	}

	/** @return list<string> */
	private function decodeList(string $key): array
	{
		$raw = $this->config->getAppValue(self::APP_ID, $key, '[]');
		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $v) {
			if (is_string($v) && $v !== '') {
				$out[] = $v;
			}
		}
		return array_values(array_unique($out));
	}

	/** @param list<string> $values */
	private function encodeList(string $key, array $values): void
	{
		$clean = [];
		foreach ($values as $v) {
			if (is_string($v) && $v !== '') {
				$clean[] = $v;
			}
		}
		$this->config->setAppValue(self::APP_ID, $key, json_encode(array_values(array_unique($clean)), JSON_THROW_ON_ERROR));
	}
}
