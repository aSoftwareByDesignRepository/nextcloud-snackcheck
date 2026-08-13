<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Db;

use OCA\SnackCheck\Db\Period;
use PHPUnit\Framework\TestCase;

final class PeriodEntityDirtyTrackingTest extends TestCase
{
	public function testStateIsIncludedWhenNoDefault(): void
	{
		$p = new Period();
		$p->setLabel('2026-08');
		$p->setState('open');
		$p->setCreatedAt(new \DateTime('now'));
		$fields = $p->getUpdatedFields();
		self::assertArrayHasKey('state', $fields);
		self::assertArrayHasKey('label', $fields);
		self::assertArrayHasKey('createdAt', $fields);
	}
}
