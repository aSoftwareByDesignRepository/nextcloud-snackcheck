#!/usr/bin/env node
/**
 * Contract test: the expense dialog submit must build the full
 * POST /api/expenses payload (amountCents + expenseReason + idempotencyKey,
 * conditional targetUserId) and keep the dialog open for correction on
 * server rejects — the web side of the NN-15 replay contract.
 *
 * Run: node tests/js/expense-submit-contract.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'app.js'), 'utf8');
const TPL = fs.readFileSync(path.join(ROOT, 'templates', 'pages', 'log.php'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

// ── Template side: dialog fields exist ────────────────────────────────
assert(/id="snk-expense-dialog"/.test(TPL), 'expense dialog missing from log.php');
assert(/name="amountEuro" id="snk-expense-amount"/.test(TPL), 'amount field missing');
assert(/name="expenseReason" id="snk-expense-reason"/.test(TPL), 'reason field missing');
assert(/name="siteId"/.test(TPL), 'hidden siteId missing');
assert(/data-snk-expense-key/.test(TPL) && /name="idempotencyKey"/.test(TPL),
	'hidden idempotencyKey carrier missing');
assert(/data-snk-action="open-expense-dialog"/.test(TPL), 'entry button missing');

// ── Open handler mints a fresh key per open (NN-15 replay dedupe) ──────
const openBlock = /open-expense-dialog[\s\S]{0,900}/.exec(SRC);
assert(openBlock !== null, 'open-expense-dialog handler missing');
if (openBlock) {
	assert(/value\s*=\s*['"`][^'"`]*-/.test(openBlock[0]) || /Math\.random|crypto|Date\.now/.test(openBlock[0]),
		'dialog open does not mint a fresh idempotency key');
}

// ── Submit builds the contract payload ────────────────────────────────
const submitBlock = /kind === 'expense'[\s\S]{0,3000}?toast\(t\('Expense recorded'/.exec(SRC);
assert(submitBlock !== null, 'expense submit branch missing');
if (submitBlock) {
	const b = submitBlock[0];
	assert(/amountCents:\s*String\(cents\)/.test(b), 'payload.amountCents missing');
	assert(/expenseReason:\s*reason/.test(b), 'payload.expenseReason missing');
	assert(/idempotencyKey:\s*String\(body\.idempotencyKey/.test(b), 'payload.idempotencyKey missing');
	assert(/payload\.targetUserId\s*=\s*target/.test(b), 'conditional targetUserId missing');
	assert(/'\/apps\/snackcheck\/api\/expenses'/.test(b), 'POST endpoint mismatch');
	assert(/markFieldInvalid\(form\.querySelector\('\[name="amountEuro"\]'\)\)/.test(b),
		'amount reject does not mark field');
	assert(/markFieldInvalid\(form\.querySelector\('\[name="expenseReason"\]'\)\)/.test(b),
		'reason reject does not mark field');
	// Claim-routed responses must not offer a ledger undo id.
	assert(/routed === 'expense_claim'/.test(b), 'expense_claim routing branch missing');
	// Undo toast carries the created log id (60 s self-undo window).
	assert(/toast\(t\('Expense recorded'[\s\S]{0,200}?created\.data\.id/.test(SRC),
		'undo toast does not pass the created log id');
}

if (failures === 0) {
	process.stdout.write('OK expense-submit contract: dialog fields + payload + invalids + undo wiring verified\n');
} else {
	process.exit(1);
}
