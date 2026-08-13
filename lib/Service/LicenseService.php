<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Config\InstanceId;
use OCA\SnackCheck\Db\LicenseState;
use OCA\SnackCheck\Db\LicenseStateMapper;
use OCA\SnackCheck\License\LicenseFingerprint;
use OCA\SnackCheck\License\Snk2Codec;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * SNK2 org license. Web UI is never gated by this service.
 */
class LicenseService
{
	private const APPLY_LOCK = 'snackcheck/license_apply';
	private string $lastApplyError = '';

	public function __construct(
		private readonly LicenseStateMapper $licenseStateMapper,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly InstanceId $instanceId,
		private readonly ILockingProvider $locking,
	) {
	}

	public function getLastApplyErrorCode(): string
	{
		return $this->lastApplyError;
	}

	public function applyLicenseKey(string $wireKey): bool
	{
		$this->lastApplyError = '';
		$error = Snk2Codec::classifyApplyError($wireKey);
		if ($error !== '') {
			$this->lastApplyError = $error;
			$this->logger->warning('SNK2 license apply rejected', ['code' => $error]);
			return false;
		}

		$parsed = Snk2Codec::parseAndVerify($wireKey);
		if ($parsed === null) {
			$this->lastApplyError = Snk2Codec::ERROR_INVALID_FORMAT;
			return false;
		}

		$payload = $parsed['payload'];
		$fingerprint = LicenseFingerprint::fromWireParts($parsed['payloadB64'], $parsed['signatureB64']);
		$now = $this->timeFactory->getDateTime();

		$state = new LicenseState();
		$state->setCustomerId((string)$payload['customerId']);
		$state->setValidUntil(new \DateTime((string)$payload['validUntil']));
		$state->setMobileSeats((int)$payload['mobileSeats']);
		$state->setTerminalDevices((int)$payload['terminalDevices']);
		$state->setBundle(!empty($payload['bundle']) ? 1 : 0);
		$state->setKeyAppliedAt($now);
		$state->setPayloadB64($parsed['payloadB64']);
		$state->setSignatureB64($parsed['signatureB64']);
		$state->setLicenseFingerprint($fingerprint);
		$state->setBoundInstanceId($this->instanceId->get());

		try {
			$this->locking->acquireLock(self::APPLY_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			$this->lastApplyError = 'license_busy';
			$this->logger->warning('SNK2 license apply busy');
			return false;
		}
		try {
			$this->licenseStateMapper->upsert($state);
		} finally {
			$this->locking->releaseLock(self::APPLY_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}

		$this->logger->info('SNK2 license applied', [
			'customerId' => $state->getCustomerId(),
			'terminalDevices' => $state->getTerminalDevices(),
		]);
		return true;
	}

	/** @return array{format: string, payloadB64: string, signatureB64: string}|null */
	public function buildEnvelope(): ?array
	{
		$state = $this->getCurrentStateIfFullyValid();
		if ($state === null) {
			return null;
		}
		return [
			'format' => Snk2Codec::FORMAT,
			'payloadB64' => $state->getPayloadB64(),
			'signatureB64' => $state->getSignatureB64(),
		];
	}

	public function isTerminalPlanActive(): bool
	{
		$state = $this->getCurrentStateIfFullyValid();
		return $state !== null && $state->getTerminalDevices() > 0;
	}

	public function getTerminalDeviceLimit(): int
	{
		$state = $this->getCurrentStateIfFullyValid();
		return $state === null ? 0 : max(0, $state->getTerminalDevices());
	}

	/** @return array<string, mixed>|null */
	public function getLicenseSummary(): ?array
	{
		$state = $this->licenseStateMapper->findCurrent();
		if ($state === null) {
			return null;
		}
		$dateValid = $this->isLicenseValid($state);
		$cryptoValid = $this->isStateCryptographicallyValid($state);
		$instanceValid = $this->isBoundToThisInstance($state);
		return [
			'customerId' => $state->getCustomerId(),
			'validUntil' => $state->getValidUntil()?->format('Y-m-d'),
			'mobileSeats' => $state->getMobileSeats(),
			'terminalDevices' => $state->getTerminalDevices(),
			'bundle' => $state->getBundle() === 1,
			'active' => $dateValid && $cryptoValid && $instanceValid,
			'dateValid' => $dateValid,
			'cryptographicallyValid' => $cryptoValid,
			'instanceValid' => $instanceValid,
			'keyAppliedAt' => $state->getKeyAppliedAt()?->format('c'),
			'boundInstanceId' => $state->getBoundInstanceId(),
		];
	}

	public function clearLicense(): void
	{
		$this->licenseStateMapper->deleteAll();
	}

	public function hasStoredLicense(): bool
	{
		return $this->licenseStateMapper->findCurrent() !== null;
	}

	public function requireTerminalLicense(): void
	{
		if (!$this->isTerminalPlanActive()) {
			throw new \OCA\SnackCheck\Exception\PaymentRequiredException('license_required');
		}
	}

	private function isLicenseValid(LicenseState $state): bool
	{
		$until = $state->getValidUntil();
		if ($until === null) {
			return false;
		}
		$today = $this->timeFactory->getDateTime()->setTime(0, 0, 0);
		$expiry = (clone $until)->setTime(0, 0, 0);
		return $expiry >= $today;
	}

	private function getCurrentStateIfFullyValid(): ?LicenseState
	{
		$state = $this->licenseStateMapper->findCurrent();
		if ($state === null || !$this->isLicenseValid($state)) {
			return null;
		}
		if (!$this->isStateCryptographicallyValid($state)) {
			return null;
		}
		// Fail closed: restored/copied license rows from another instance must not unlock tablets.
		if (!$this->isBoundToThisInstance($state)) {
			$this->logger->warning('SNK2 license rejected: bound instance mismatch', [
				'bound' => $state->getBoundInstanceId(),
			]);
			return null;
		}
		return $state;
	}

	private function isBoundToThisInstance(LicenseState $state): bool
	{
		$bound = trim($state->getBoundInstanceId());
		if ($bound === '') {
			return false;
		}
		return hash_equals($bound, $this->instanceId->get());
	}

	private function isStateCryptographicallyValid(LicenseState $state): bool
	{
		$wire = Snk2Codec::FORMAT . '.' . $state->getPayloadB64() . '.' . $state->getSignatureB64();
		return Snk2Codec::parseAndVerify($wire) !== null;
	}
}
