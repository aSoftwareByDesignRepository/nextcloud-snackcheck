#!/usr/bin/env node
/**
 * Contract test: assertive (error) toasts must offer the family "Report this
 * problem" mailto (_shared/app-feedback README: "Error toasts get Report this
 * problem via the JS helper").
 *
 * SnackCheck renders its own #snk-toast with .snk-toast--error — the shared
 * app-feedback.js selector (.toast--error) and the *Components.showToast /
 * showError wrappers never fire for it, so app.js must attach the link itself
 * via window.SbdAppFeedback.buildMailto.
 *
 * Run: node tests/js/error-toast-report-link.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// Locate the toast() function body.
const toastIdx = SRC.indexOf('function toast(');
assert(toastIdx >= 0, 'toast() not found in js/app.js');
const toastBody = toastIdx >= 0 ? SRC.slice(toastIdx, toastIdx + 4000) : '';

// 1. Assertive branch builds the report link via the shared helper.
assert(/if\s*\(assertive\)/.test(toastBody),
	'toast() has no assertive branch for the report link');
assert(/SbdAppFeedback/.test(toastBody),
	'toast() does not reach window.SbdAppFeedback (shared helper)');
assert(/buildMailto\('problem'/.test(toastBody),
	"toast() does not call buildMailto('problem', …)");
assert(/snk-nav-footer__toast-link/.test(toastBody),
	'toast() report link misses the snk-nav-footer__toast-link class (touch-target CSS)');
assert(/Report this problem/.test(toastBody),
	'toast() report link lacks the "Report this problem" label');
// Failure of the helper must never break the toast itself.
assert(/catch\s*\(/.test(toastBody),
	'toast() report-link attach is not wrapped in try/catch');

// 2. Dedup contract (api-security-patterns §6): one toast element, timer reset.
assert(/clearTimeout\(el\._snkHide\)/.test(toastBody),
	'toast() does not reset the hide timer (dedup/timer-reset contract)');

if (failures === 0) {
	process.stdout.write('OK error-toast-report-link contract: assertions pass\n');
} else {
	process.exit(1);
}
