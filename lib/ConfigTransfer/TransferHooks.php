<?php

declare(strict_types=1);

/**
 * SnackCheck wiring for the shared transfer engine.
 *
 *  - validateValue/normalizeValue delegate to SettingsService::normalizeForWrite
 *    — the EXACT normaliser the settings API uses (validator parity, NN-08).
 *  - SnackCheck has no platform restriction mirror: the access door is
 *    app-local appconfig, so no `mirrored` keys exist; writeMirrored is a
 *    defensive no-op contractually required by the interface.
 *  - createSafetySnapshot delegates to UpgradeBackupService (FS-2).
 */

namespace OCA\SnackCheck\ConfigTransfer;

use OCA\SnackCheck\Service\AccessControlService;
use OCA\SnackCheck\Service\AuditService;
use OCA\SnackCheck\Service\SettingsService;
use OCA\SnackCheck\Service\UpgradeBackupService;
use OCP\IGroupManager;
use OCP\IUserManager;

final class TransferHooks implements ConfigTransferHooks
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly AccessControlService $access,
		private readonly UpgradeBackupService $backups,
		private readonly AuditService $audit,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		$this->access->assertAppAdmin($uid);
	}

	public function validateValue(string $key, string $value): ?string
	{
		if ($key === 'unlock_pin_pepper') {
			return strlen($value) >= 32 && strlen($value) <= 512 ? null : 'INVALID_PEPPER';
		}
		try {
			$this->settings->normalizeForWrite($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			if ($e->getMessage() === 'UNKNOWN_SETTING_KEY') {
				// Keys with no settings-form validator: bounded sanity check
				// only, never a blanket reject that bricks the import.
				return strlen($value) <= 65536 ? null : 'TOO_LONG';
			}
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->settings->normalizeForWrite($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	public function validateMirrored(array $keyValues): ?string
	{
		// No mirrored keys in snackcheck — the engine only calls this when a
		// file carries mirrored-class values, which the manifest never emits.
		return $keyValues === [] ? null : 'NO_MIRRORED_KEYS';
	}

	public function writeMirrored(array $keyValues): void
	{
		if ($keyValues !== []) {
			throw new \InvalidArgumentException('NO_MIRRORED_KEYS');
		}
	}

	public function securityRelevantKeys(): array
	{
		return [
			'access_mode',
			'access_users_json',
			'access_groups_json',
			'app_admins_json',
			'privacy_totals_only',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match (true) {
			str_starts_with($key, 'access_') || $key === 'app_admins_json' => 'access',
			str_starts_with($key, 'hospitality_') || $key === 'subsidy_allowance_cents' => 'benefits',
			str_contains($key, 'digest') || str_contains($key, 'topup') => 'digests',
			str_contains($key, 'unlock') => 'unlock',
			$key === 'privacy_totals_only' => 'privacy',
			default => 'benefits',
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		try {
			$this->audit->record(
				$actor === '' ? 'cli' : $actor,
				'config_transfer_' . $action,
				'config_transfer',
				null,
				$details,
			);
		} catch (\Throwable) {
			// audit must never fail the transfer itself
		}
	}
}
