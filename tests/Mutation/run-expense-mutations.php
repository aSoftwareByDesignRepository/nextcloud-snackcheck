<?php

declare(strict_types=1);

/**
 * Mutation battery for the expense-credits paths (SubsidyService credit math +
 * ConsumptionLogService::createExpense guards). A surviving mutant means the
 * suite does not actually protect that behaviour.
 * Run: php tests/Mutation/run-expense-mutations.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

/** @var list<array{file:string,name:string,search:string,replace:string}> $mutations */
$mutations = [
	// ── SubsidyService credit math ──────────────────────────────────
	['file' => 'lib/Service/SubsidyService.php', 'name' => 'credit_sign_flip',
		'search' => 'if ($total < 0) {
					$credits += -$total;
				}',
		'replace' => 'if ($total < 0) {
					$credits += $total;
				}'],
	['file' => 'lib/Service/SubsidyService.php', 'name' => 'deduct_adds_credits',
		'search' => '$deduct = $gross - $applied - $credits;',
		'replace' => '$deduct = $gross - $applied + $credits;'],
	['file' => 'lib/Service/SubsidyService.php', 'name' => 'clamp_polarity',
		'search' => 'if ($clampNegativeDeduct && $deduct < 0) {',
		'replace' => 'if ($clampNegativeDeduct && $deduct > 0) {'],
	['file' => 'lib/Service/SubsidyService.php', 'name' => 'unused_sign',
		'search' => '$unused = -$deduct;',
		'replace' => '$unused = $deduct;'],
	['file' => 'lib/Service/SubsidyService.php', 'name' => 'credit_bucket_skipped',
		'search' => 'if ($bucket === self::BUCKET_PERSONAL_CREDIT) {
				if ($total < 0) {
					$credits += -$total;
				}
				continue;
			}
			if ($bucket !== self::BUCKET_PERSONAL) {
				continue;
			}
			if ($total <= 0) {
				continue; // complimentary / free never consume subsidy
			}
			$gross += $total;',
		'replace' => 'if ($bucket === self::BUCKET_PERSONAL_CREDIT) {
				continue;
			}
			if ($bucket !== self::BUCKET_PERSONAL) {
				continue;
			}
			if ($total <= 0) {
				continue; // complimentary / free never consume subsidy
			}
			$gross += $total;'],
	// ── createExpense guards ────────────────────────────────────────
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'positive_ledger_row',
		'search' => '$log->setLineTotalCents(-$amount);',
		'replace' => '$log->setLineTotalCents($amount);'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'reason_min_boundary',
		'search' => 'if (mb_strlen($reason) < 3 || mb_strlen($reason) > self::REASON_MAX_LEN) {',
		'replace' => 'if (mb_strlen($reason) <= 3 || mb_strlen($reason) > self::REASON_MAX_LEN) {'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'amount_lower_bound',
		'search' => 'if ($amount <= 0 || $amount > self::EXPENSE_MAX_CENTS) {',
		'replace' => 'if ($amount < 0 || $amount > self::EXPENSE_MAX_CENTS) {'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'amount_upper_bound',
		'search' => 'if ($amount <= 0 || $amount > self::EXPENSE_MAX_CENTS) {',
		'replace' => 'if ($amount <= 0 || $amount >= self::EXPENSE_MAX_CENTS) {'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'ext_key_inverted',
		'search' => 'if (!$facade && str_starts_with($key, \'ext:\')) {',
		'replace' => 'if (!$facade && !str_starts_with($key, \'ext:\')) {'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'facade_ext_open',
		'search' => 'if (!$facade && str_starts_with($key, \'ext:\')) {',
		'replace' => 'if (str_starts_with($key, \'ext:\')) {'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'self_cap_boundary',
		'search' => 'if ($cap > 0 && $amount > $cap) {',
		'replace' => 'if ($cap > 0 && $amount >= $cap) {'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'credit_bucket_personal',
		'search' => '$log->setBillingBucket(SubsidyService::BUCKET_PERSONAL_CREDIT);',
		'replace' => '$log->setBillingBucket(SubsidyService::BUCKET_PERSONAL);'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'hash_equals_negated',
		'search' => 'if (!hash_equals($fingerprint, $this->storedExpenseFingerprint($existing))) {',
		'replace' => 'if (hash_equals($fingerprint, $this->storedExpenseFingerprint($existing))) {'],
	['file' => 'lib/Service/ConsumptionLogService.php', 'name' => 'enabled_gate_inverted',
		'search' => 'if (!$this->settings->isExpenseEnabled()) {
			throw new DomainException(\'expense_disabled\', \'Expenses disabled\', 403);
		}',
		'replace' => 'if ($this->settings->isExpenseEnabled()) {
			throw new DomainException(\'expense_disabled\', \'Expenses disabled\', 403);
		}'],

	// ── ATLAS round fixes: period labels, rate-limit retry, claim dedupe ──
	['file' => 'lib/Service/PeriodService.php', 'name' => 'successor_suffix_no_increment',
		'search' => '$label = $maxSuffix === 0 ? $baseLabel : $baseLabel . \'-\' . ($maxSuffix + 1);',
		'replace' => '$label = $maxSuffix === 0 ? $baseLabel : $baseLabel . \'-\' . $maxSuffix;'],
	['file' => 'lib/Service/RateLimitService.php', 'name' => 'lock_fail_falls_through',
		'search' => 'if (!$acquired) {
			throw new DomainException(\'rate_limited\', \'Rate limited\', 429);
		}',
		'replace' => 'if (false) {
			throw new DomainException(\'rate_limited\', \'Rate limited\', 429);
		}'],
	['file' => 'lib/Service/RateLimitService.php', 'name' => 'retry_loop_unconditional',
		'search' => 'for ($attempt = 0; $attempt < 20 && !$acquired; $attempt++) {',
		'replace' => 'for ($attempt = 0; $attempt < 20; $attempt++) {'],
	['file' => 'lib/Service/ExpenseClaimsBridge.php', 'name' => 'fingerprint_or_to_and',
		'search' => 'if ((int)$claim->getTotalMinor() !== $amountCents || (string)$claim->getTitle() !== $title) {',
		'replace' => 'if ((int)$claim->getTotalMinor() !== $amountCents && (string)$claim->getTitle() !== $title) {'],
	['file' => 'lib/Controller/ApiController.php', 'name' => 'keyless_claim_accepted',
		'search' => 'if ($idem === \'\') {
			// Same contract as the ledger path: no key, no write. A generated
			// ref would mint a fresh claim per retry — silent duplicates.
			throw new DomainException(\'validation_failed\', \'Idempotency key required\', 422);
		}',
		'replace' => ''],
];

function runPhpunit(): bool
{
	$root = dirname(__DIR__, 2);
	$filter = 'ExpenseCreditMathTest|ConsumptionLogExpenseTest|PeriodEnsureOpenTest|RateLimitServiceTest|ExpenseClaimsBridgeIntegrationTest|ExpenseClaimsRouteContractTest';
	if (is_file('/var/www/html/lib/base.php')) {
		$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($root . '/vendor/bin/phpunit')
			. ' -c ' . escapeshellarg($root . '/phpunit.xml')
			. ' --do-not-cache-result --filter ' . escapeshellarg($filter);
	} else {
		$appId = basename($root);
		$cmd = 'docker compose -f ' . escapeshellarg(dirname($root, 2) . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '--do-not-cache-result --filter ' . escapeshellarg($filter);
	}
	exec($cmd . ' 2>&1', $out, $code);
	return $code === 0;
}

require_once __DIR__ . '/MutationBaseline.php';
mutationBaselineSelfCheck(__FILE__);

$killed = 0;
$survived = [];
foreach ($mutations as $m) {
	$sourcePath = dirname(__DIR__, 2) . '/' . $m['file'];
	$original = file_get_contents($sourcePath);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$m['file']}\n");
		exit(1);
	}
	if (!str_contains($original, $m['search'])) {
		fwrite(STDERR, "SKIP {$m['name']}: search not found in {$m['file']}\n");
		continue;
	}
	if (file_put_contents($sourcePath, str_replace($m['search'], $m['replace'], $original)) === false) {
		fwrite(STDERR, "SKIP {$m['name']}: cannot write {$m['file']}\n");
		continue;
	}
	$ok = runPhpunit();
	if (file_put_contents($sourcePath, $original) === false) {
		fwrite(STDERR, "RESTORE-FAIL {$m['name']}: {$m['file']} left mutated\n");
		exit(2);
	}
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
