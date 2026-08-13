<?php

declare(strict_types=1);

/**
 * Lightweight mutation battery for SubsidyCalculator — proves tests catch broken logic.
 * Run: php tests/Mutation/run-subsidy-mutations.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use OCA\SnackCheck\Service\SubsidyCalculator;

$sourcePath = __DIR__ . '/../../lib/Service/SubsidyCalculator.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read SubsidyCalculator\n");
	exit(1);
}

/** @var list<array{name:string,search:string,replace:string}> $mutations */
$mutations = [
	['name' => 'subsidy_min_to_max', 'search' => '$subsidy = min($allowance, $gross);', 'replace' => '$subsidy = max($allowance, $gross);'],
	['name' => 'deduct_plus', 'search' => "'deduct_cents' => \$gross - \$subsidy,", 'replace' => "'deduct_cents' => \$gross + \$subsidy,"],
	['name' => 'days_max1_to_0', 'search' => '$days = max(1, $daysWithData);', 'replace' => '$days = max(0, $daysWithData);'],
	['name' => 'topup_or_to_and', 'search' => 'if ($daysLeft <= $restockHorizonDays || $onHand <= $parLevel)', 'replace' => 'if ($daysLeft <= $restockHorizonDays && $onHand <= $parLevel)'],
	['name' => 'suggested_buy_flip', 'search' => 'return max(0, $parLevel - $onHand);', 'replace' => 'return max(0, $onHand - $parLevel);'],
	['name' => 'line_total_add', 'search' => 'return $qty * $unitPriceCents;', 'replace' => 'return $qty + $unitPriceCents;'],
	['name' => 'complimentary_neq', 'search' => 'return $lineTotalCents === 0;', 'replace' => 'return $lineTotalCents !== 0;'],
];

function runPhpunit(): bool
{
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../vendor/bin/phpunit')
		. ' --filter SubsidyCalculatorTest --no-output';
	exec($cmd . ' 2>&1', $out, $code);
	return $code === 0;
}

$killed = 0;
$survived = [];
foreach ($mutations as $m) {
	if (!str_contains($original, $m['search'])) {
		fwrite(STDERR, "SKIP {$m['name']}: search not found\n");
		continue;
	}
	$mutated = str_replace($m['search'], $m['replace'], $original);
	file_put_contents($sourcePath, $mutated);
	$ok = runPhpunit();
	file_put_contents($sourcePath, $original);
	if ($ok) {
		$survived[] = $m['name'];
		echo "SURVIVED {$m['name']}\n";
	} else {
		$killed++;
		echo "KILLED {$m['name']}\n";
	}
}

$total = $killed + count($survived);
$msi = $total > 0 ? ($killed / $total) * 100 : 0.0;
echo sprintf("MSI: %.1f%% (%d/%d killed)\n", $msi, $killed, $total);
if ($survived !== []) {
	fwrite(STDERR, 'Survivors: ' . implode(', ', $survived) . "\n");
	exit(1);
}
if ($msi < 70.0) {
	fwrite(STDERR, "MSI below 70%\n");
	exit(1);
}
echo "Mutation battery OK\n";
