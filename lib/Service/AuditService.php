<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Service;

use OCA\SnackCheck\Db\AuditEvent;
use OCA\SnackCheck\Db\AuditEventMapper;
use OCP\AppFramework\Utility\ITimeFactory;

class AuditService
{
	public function __construct(
		private readonly AuditEventMapper $mapper,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<string, mixed>|null $payload
	 */
	public function record(string $actorUid, string $action, string $entityType, ?string $entityId = null, ?array $payload = null): void
	{
		$event = new AuditEvent();
		$event->setCreatedAt($this->timeFactory->getDateTime());
		$event->setActorUid($actorUid);
		$event->setAction($action);
		$event->setEntityType($entityType);
		$event->setEntityId($entityId);
		$event->setPayloadJson($payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
		$this->mapper->insert($event);
	}

	/** @return list<AuditEvent> */
	public function recent(int $limit = 100): array
	{
		return $this->mapper->findRecent($limit);
	}
}
