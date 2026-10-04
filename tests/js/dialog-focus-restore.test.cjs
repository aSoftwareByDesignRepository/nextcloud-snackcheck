#!/usr/bin/env node
/**
 * Contract test: dialog close must restore focus to a LIVE element — never
 * document.body and never a disconnected trigger (WCAG 2.4.3 / Atlas a11y).
 *
 * Regression probe for the ds_chrome 3.5.14 finding: openSnkDialog previously
 * called `prev.focus()` unconditionally, so a re-rendered trigger (or a dialog
 * opened while nothing was focused) dumped focus on <body>.
 *
 * Run: node tests/js/dialog-focus-restore.test.cjs
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

// 1. Dedicated resolver exists and is wired into the close handler.
assert(/function resolveSnkDialogRestoreTarget\(prev\)/.test(SRC),
	'resolveSnkDialogRestoreTarget helper missing in js/app.js');
assert(/resolveSnkDialogRestoreTarget\(prev\)/.test(SRC),
	'openSnkDialog close handler does not call the resolver');

// 2. The resolver rejects body + disconnected nodes.
assert(/prev\s*!==\s*document\.body/.test(SRC),
	'resolver does not exclude document.body (focus must never land on body)');
assert(/prev\.isConnected\s*!==\s*false|prev\.isConnected\b/.test(SRC),
	'resolver does not check isConnected (dead trigger would swallow focus)');

// 3. Fallback chain: page actions → page title (tabindex -1) → main landmark.
const resolverBody = SRC.slice(SRC.indexOf('function resolveSnkDialogRestoreTarget'));
const chainOrder = [
	resolverBody.indexOf("getElementById('snk-page-actions')"),
	resolverBody.indexOf("getElementById('snk-page-title')"),
	resolverBody.indexOf("getElementById('snk-main-content')"),
];
assert(chainOrder.every((i) => i >= 0), 'resolver fallback chain incomplete');
assert(chainOrder[0] < chainOrder[1] && chainOrder[1] < chainOrder[2],
	'fallback chain order wrong — want page-actions → page-title → main');
assert(/setAttribute\('tabindex',\s*'-1'\)/.test(resolverBody),
	'page-title fallback must be made focusable (tabindex=-1)');

// 4. onClose no longer calls prev.focus() directly.
const closeIdx = SRC.indexOf('const onClose = function () {\n\t\t\tdlg.removeEventListener');
const closeBody = closeIdx >= 0 ? SRC.slice(closeIdx, closeIdx + 900) : '';
assert(!/prev\.focus\(\)/.test(closeBody),
	'onClose still calls prev.focus() directly — bypasses the live-element guard');

if (failures === 0) {
	process.stdout.write('OK dialog-focus-restore contract: 4 assertion groups pass\n');
} else {
	process.exit(1);
}
