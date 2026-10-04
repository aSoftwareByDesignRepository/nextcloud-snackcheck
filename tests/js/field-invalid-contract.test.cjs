#!/usr/bin/env node
/**
 * Contract test: client-side validation failures must mark the offending
 * control aria-invalid (WCAG 3.3.1) in addition to the assertive toast —
 * the aria-describedby hint already describes the rule.
 *
 * Run: node tests/js/field-invalid-contract.test.cjs
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

assert(/function markFieldInvalid\(field\)/.test(SRC),
	'markFieldInvalid helper missing');
assert(/setAttribute\('aria-invalid', 'true'\)/.test(SRC),
	'markFieldInvalid does not set aria-invalid');
// Self-clearing on next user edit — covers text (input) and file (change).
assert(/addEventListener\('input', clear\)/.test(SRC) && /addEventListener\('change', clear\)/.test(SRC),
	'aria-invalid must clear on input AND change');

// Every client-side reject path that toasts + returns must mark a field.
// Count toast-only validation returns that still lack markFieldInvalid.
const rejectSites = [
	/!uid\)[\s\S]{0,400}?markFieldInvalid\(search\)/,
	/proxy[\s\S]{0,1200}?why\.length < 3[\s\S]{0,200}?markFieldInvalid\(reason\)/,
	/hospitality[\s\S]{0,800}?why\.length < 3[\s\S]{0,200}?markFieldInvalid\(reason\)/,
];
const labels = ['missing-colleague', 'proxy-reason-length', 'hospitality-reason-length'];
rejectSites.forEach((rx, i) => {
	assert(rx.test(SRC), 'client validation "' + labels[i] + '" does not mark the field aria-invalid');
});
// priceEuro parse rejects (create + update) and photo rejects mark inputs.
assert((SRC.match(/markFieldInvalid\(form\.querySelector\(/g) || []).length >= 2,
	'priceEuro parse rejects must mark the price input (create + update)');
assert((SRC.match(/markFieldInvalid\(fileInput\)/g) || []).length >= 2,
	'photo validation rejects must mark the file input (create + update)');
assert(/markFieldInvalid\(input\)/.test(SRC),
	'edit-photo change reject must mark the file input');

if (failures === 0) {
	process.stdout.write('OK field-invalid contract: aria-invalid wired on all client-side rejects\n');
} else {
	process.exit(1);
}
