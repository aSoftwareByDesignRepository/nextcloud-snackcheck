#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerate l10n/*.js from l10n/*.json (SnackCheck).
 *
 * Usage: php scripts/regenerate-l10n-js.php
 */

$root = realpath(__DIR__ . '/../../..');
if ($root === false) {
	fwrite(STDERR, "Cannot resolve nextcloud root\n");
	exit(1);
}
$cmd = PHP_BINARY . ' ' . escapeshellarg($root . '/scripts/l10n/regenerate-l10n-js.php')
	. ' --app=snackcheck';
passthru($cmd, $code);
exit($code);
