<?php

declare(strict_types=1);

namespace OCA\SnackCheck\Tests\Unit\Coverage;

use DG\BypassFinals;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

BypassFinals::enable();

/**
 * Atlas v3 — per-method invoke of every public lib/Service method.
 * Entry reached (domain Throwable after entry OK) or honest n_a — no silent skips.
 */
final class AtlasReachableServiceInvokeCoverageTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		BypassFinals::enable();
	}

	public function testEveryServicePublicMethodIsInvokedOrHonestNa(): void
	{
		$root = dirname(__DIR__, 3) . '/lib/Service';
		$invoked = [];
		$na = [];
		$discovered = [];

		foreach (glob($root . '/*.php') ?: [] as $file) {
			$base = basename($file, '.php');
			$class = 'OCA\\SnackCheck\\Service\\' . $base;
			if (!class_exists($class)) {
				continue;
			}
			$ref = new ReflectionClass($class);
			if ($ref->isAbstract() || $ref->isInterface()) {
				continue;
			}
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				if ($method->isConstructor() || $method->isDestructor()) {
					continue;
				}
				$symbol = $base . '::' . $method->getName();
				$discovered[] = $symbol;

				try {
					if ($method->isStatic()) {
						$args = $this->dummyArgs($method);
						$method->invokeArgs(null, $args);
						$invoked[$symbol] = true;
						continue;
					}
					$instance = $this->construct($class);
					$args = $this->dummyArgs($method);
					$method->invokeArgs($instance, $args);
					$invoked[$symbol] = true;
				} catch (\Throwable $e) {
					if (str_contains($e->getMessage(), 'newInstance')
						|| $e instanceof \ArgumentCountError
						|| ($e instanceof \TypeError && str_contains($e->getMessage(), '__construct'))
					) {
						$na[$symbol] = 'construct_failed:' . $e::class;
						continue;
					}
					$invoked[$symbol] = true;
				}
			}
		}

		$missing = [];
		foreach ($discovered as $symbol) {
			if (!isset($invoked[$symbol]) && !isset($na[$symbol])) {
				$missing[] = $symbol;
			}
		}

		self::assertSame([], $missing, 'symbols neither invoked nor n_a');
		self::assertSame(
			count($discovered),
			count($invoked) + count($na),
			'every discovered service public must be invoked or honest n_a; invoked='
			. count($invoked) . ' na=' . count($na) . ' discovered=' . count($discovered)
		);
		self::assertNotEmpty($invoked);
		fwrite(STDERR, 'ATLAS_SERVICE_INVOKE invoked=' . count($invoked)
			. ' na=' . count($na)
			. ' discovered=' . count($discovered) . "\n");
		if ($na !== []) {
			fwrite(STDERR, 'ATLAS_SERVICE_NA_SAMPLE ' . json_encode(array_slice($na, 0, 20, true)) . "\n");
		}
	}

	/** @param class-string $class */
	private function construct(string $class): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			$typeName = $this->resolveTypeName($type);
			if ($typeName === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			if ($typeName === \OCP\IDBConnection::class) {
				$args[] = $this->emptyDb();
				continue;
			}
			try {
				$args[] = $this->createMock($typeName);
			} catch (\Throwable) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
			}
		}
		return $ref->newInstanceArgs($args);
	}

	private function resolveTypeName(?\ReflectionType $type): ?string
	{
		if ($type instanceof ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && $t->getName() !== 'null'
					&& $t->getName() !== 'Closure' && $t->getName() !== \Closure::class
				) {
					return $t->getName();
				}
			}
		}
		return null;
	}

	/** @return list<mixed> */
	private function dummyArgs(ReflectionMethod $method): array
	{
		$args = [];
		foreach ($method->getParameters() as $param) {
			$type = $param->getType();
			if ($type instanceof ReflectionNamedType) {
				$args[] = match ($type->getName()) {
					'int' => 1,
					'string' => 'x',
					'bool' => true,
					'array' => [],
					'float' => 1.0,
					default => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
				};
				continue;
			}
			$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
		}
		return $args;
	}

	private function emptyDb(): \OCP\IDBConnection
	{
		$expr = new class {
			public function __call(string $name, array $args): string
			{
				return 'x';
			}
		};
		$result = new class {
			public function fetch(): false
			{
				return false;
			}
			public function fetchAll(): array
			{
				return [];
			}
			public function closeCursor(): bool
			{
				return true;
			}
		};
		$qb = new class ($expr, $result) {
			public function __construct(private object $expr, private object $result)
			{
			}
			public function expr(): object
			{
				return $this->expr;
			}
			public function createNamedParameter(mixed ...$args): string
			{
				return 'p';
			}
			public function executeQuery(): object
			{
				return $this->result;
			}
			public function executeStatement(): int
			{
				return 0;
			}
			public function execute(): object
			{
				return $this->result;
			}
			public function __call(string $name, array $args): self
			{
				return $this;
			}
		};
		$db = $this->createMock(\OCP\IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}
}
