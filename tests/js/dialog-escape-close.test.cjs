#!/usr/bin/env node
/**
 * Contract test: native <dialog> opened via openSnkDialog must close on
 * Escape keydown even when the native `cancel` event is suppressed —
 * NC's notifications app preventDefault()s global Escape, so `cancel`
 * never fires (Atlas lesson host_app_escape_preventdefault).
 *
 * Run: node tests/js/dialog-escape-close.test.cjs
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

// 1. A keydown Escape path exists on the dialog inside openSnkDialog.
const helperIdx = SRC.indexOf('function openSnkDialog');
assert(helperIdx >= 0, 'openSnkDialog helper missing');
const helperBody = SRC.slice(helperIdx, helperIdx + 4000);
assert(/addEventListener\('keydown'/.test(helperBody),
	'openSnkDialog does not bind a keydown listener on the dialog');
assert(/key\s*===?\s*'Escape'/.test(helperBody),
	'keydown listener does not check e.key === Escape');
assert(/\.close\(\)/.test(helperBody),
	'Escape path does not close the dialog');

// 2. Idempotent: binding guarded so re-opening cannot stack listeners.
assert(/_snkEscapeBound/.test(helperBody),
	'Escape binding is not guarded — re-opening would stack listeners');

// 3. Escape must still run through the shared close path (focus restore).
assert(/addEventListener\('close'/.test(helperBody),
	'close listener (focus restore) missing from openSnkDialog');

if (failures > 0) {
	process.stderr.write(`dialog-escape-close: ${failures} failure(s)\n`);
	process.exit(1);
}
process.stdout.write('dialog-escape-close OK\n');
