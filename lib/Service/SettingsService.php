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
