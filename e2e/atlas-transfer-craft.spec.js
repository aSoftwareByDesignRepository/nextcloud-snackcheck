// @ts-check
/**
 * ATLAS craft: real transfer apply flow → two DISTINCT-state captures.
 *
 *   snk-web-transfer-progress-light-1280.png — progress dialog open while the
 *     queued TransferApplyJob is still pending/running (phase text + bar).
 *   snk-web-transfer-applied-light-1280.png — post-apply settings surface after
 *     the JS reload: "Last transfer" receipt row visible (result 'applied'
 *     asserted via the status API, not painted text — locale-safe).
 *
 * The previous pair was byte-identical (apply raced the capture) — evidence-
 * integrity FAIL. This spec executes the queued job via occ between the two
 * captures so each PNG renders its own state.
 *
 * Run (from the app dir):
 *   npx playwright test e2e/atlas-transfer-craft.spec.js
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const { writeFileSync, mkdirSync } = require('fs');
const { tmpdir } = require('os');
const path = require('path');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/snackcheck';
const API = `${APP}/api`;
const MARKER = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
const TRANSFER_PASS = `Atlas-Xfer-${MARKER}-secret`;
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/snackcheck/craft/web');

function occ(args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: path.resolve(__dirname, '../../..'), encoding: 'utf8' });
}

async function requestToken(page) {
	return page.evaluate(
		() => (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '');
}

test('transfer progress vs applied craft — distinct states', async ({ page }) => {
	test.setTimeout(5 * 60_000);
	mkdirSync(OUT, { recursive: true });
	await page.setViewportSize({ width: 1280, height: 800 });
	await gotoApp(page, `${BASE}${APP}/settings/transfer`);

	// Real export → real import preview → report dialog.
	const token = await requestToken(page);
	const exported = await page.request.post(`${API}/admin/transfer/export`, {
		data: { passphrase: TRANSFER_PASS },
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	});
	expect(exported.ok(), `transfer export failed: ${exported.status()}`).toBeTruthy();
	const buf = await exported.body();
	expect(buf.byteLength).toBeGreaterThan(200);
	const filePath = path.join(tmpdir(), `snk-craft-${MARKER}.nctransfer`);
	writeFileSync(filePath, buf);

	await page.locator('#snk-transfer-import-passphrase').fill(TRANSFER_PASS);
	await page.locator('#snk-transfer-file').setInputFiles(filePath);
	await expect(page.locator('#snk-transfer-report[open]')).toBeVisible({ timeout: 30_000 });

	// Confirm → queued job; progress dialog paints the pending phase.
	await page.locator('#snk-transfer-confirm').click();
	const progress = page.locator('#snk-transfer-progress[open]');
	await expect(progress).toBeVisible({ timeout: 15_000 });
	await page.screenshot({ path: path.join(OUT, 'snk-web-transfer-progress-light-1280.png') });

	// Run the queued TransferApplyJob so the poll loop reaches 'done'.
	const runAt = new Date().toISOString();
	const list = occ(['background-job:list']);
	const jobIds = [...list.matchAll(/^\|?\s*(\d+)\s*\|\s*OCA\\SnackCheck\\BackgroundJob\\TransferApplyJob/gm)]
		.map((m) => m[1]);
	expect(jobIds.length, 'no queued TransferApplyJob found').toBeGreaterThan(0);
	occ(['background-job:execute', jobIds[jobIds.length - 1]]);

	// JS closes the dialog and reloads on done → receipt row paints applied.
	// Wait for the REAL close + reload — a fixed sleep races the 1.5s poll.
	await expect(progress).toBeHidden({ timeout: 60_000 });
	await page.waitForLoadState('load');
	await expect(page.locator('#snk-transfer-last')).toBeVisible({ timeout: 30_000 });
	const status = await page.request.get(`${API}/admin/transfer/status`, {
		headers: { requesttoken: await requestToken(page), 'OCS-APIRequest': 'true' },
	});
	const body = await status.json();
	const receipt = body?.data?.lastReceipt ?? body?.lastReceipt;
	expect(receipt?.result, `apply did not reach applied: ${JSON.stringify(body).slice(0, 300)}`)
		.toBe('applied');
	expect(receipt?.at >= runAt.slice(0, 19), `receipt ${receipt?.at} predates run start ${runAt}`).toBeTruthy();
	// Frame the receipt row — top-of-page alone doesn't show the applied state.
	await page.locator('#snk-transfer-last').scrollIntoViewIfNeeded();
	await page.screenshot({ path: path.join(OUT, 'snk-web-transfer-applied-light-1280.png') });
});
