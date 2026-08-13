<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<LicenseState> */
class LicenseStateMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'snk_license_state', LicenseState::class);
	}

	public function findCurrent(): ?LicenseState
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->setMaxResults(1)
			->orderBy('id', 'DESC');
		// Never map multi-row residue to null (that falsely 402s every tablet).
		$entities = $this->findEntities($qb);
		return $entities[0] ?? null;
	}

	public function upsert(LicenseState $state): LicenseState
	{
		$this->db->beginTransaction();
		try {
			$existing = $this->findCurrent();
			if ($existing !== null) {
				$existing->setCustomerId($state->getCustomerId());
				$existing->setValidUntil($state->getValidUntil());
				$existing->setMobileSeats($state->getMobileSeats());
				$existing->setTerminalDevices($state->getTerminalDevices());
				$existing->setBundle($state->getBundle());
				$existing->setKeyAppliedAt($state->getKeyAppliedAt());
				$existing->setPayloadB64($state->getPayloadB64());
				$existing->setSignatureB64($state->getSignatureB64());
				$existing->setBoundInstanceId($state->getBoundInstanceId());
				$existing->setLicenseFingerprint($state->getLicenseFingerprint());
				$existing->setSingletonGuard(1);
				$result = $this->update($existing);
				$this->deleteOtherThan((int)$existing->getId());
			} else {
				$state->setSingletonGuard(1);
				$result = $this->insert($state);
			}
			$this->db->commit();
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Heal dual-row residue left by pre-singleton races. */
	private function deleteOtherThan(int $keepId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->neq('id', $qb->createNamedParameter($keepId)))
			->executeStatement();
	}

	public function deleteAll(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}
}
