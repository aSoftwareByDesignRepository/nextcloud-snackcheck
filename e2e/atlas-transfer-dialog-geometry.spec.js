// @ts-check
/**
 * ATLAS painted-geometry proof for the config-transfer pack dialogs
 * (native_dialog_margin_reset_uncentered — margin:auto pack fix @66327be).
 *
 * NC core's html,body,…,dialog reset zeroes the UA 'margin:auto' that
 * centers a top-layer <dialog>; css/common/transfer.css re-declares
 * `margin: auto` (and margin:0 inside the ≤480px media query). These legs
 * measure real getBoundingClientRect geometry on the live page:
 *
 *   centered  — 1280 + 768 viewports: dialog floats centered both axes
 *   fullbleed — 375 + 320 viewports: ≤480px sheet pins left/top edge, spans viewport
 *
 * The report dialog is driven through the REAL flow (export endpoint →
 * file input → debounced preview → report), the progress dialog through
 * the real apply click — no DOM hand-edits, no style injection.
 *
 * Run (from the app dir):
 *   npx playwright test e2e/atlas-transfer-dialog-geometry.spec.js
 */
const { test, expect } = require('@playwright/test');
const { writeFileSync } = require('fs');
const { tmpdir } = require('os');
const { join } = require('path');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/snackcheck';
const API = `${APP}/api`;
const MARKER = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
const TRANSFER_PASS = `Atlas-Xfer-${MARKER}-secret`;
let transferFilePath = null;

/** Real .nctransfer fixture: produced by the app's own export endpoint, so
 * the import preview decrypts honestly (no hand-crafted JSON). */
async function ensureTransferFile(page) {
	if (transferFilePath) {
		return transferFilePath;
	}
	const token = await page.evaluate(
		() => (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '');
	const resp = await page.request.post(`${API}/admin/transfer/export`, {
		data: { passphrase: TRANSFER_PASS },
		headers: { requesttoken: token, 'OCS-APIRequest': 'true' },
	});
	expect(resp.ok(), `transfer export failed: ${resp.status()}`).toBeTruthy();
	const buf = await resp.body();
	expect(buf.byteLength, 'transfer export returned a tiny body').toBeGreaterThan(200);
	transferFilePath = join(tmpdir(), `snk-geom-${MARKER}.nctransfer`);
	writeFileSync(transferFilePath, buf);
	return transferFilePath;
}

/** mode 'centered' proves the pack margin:auto restored both-axis
 * centering over the dimmed backdrop; mode 'fullbleed' proves the ≤480px
 * media query margin:0 sheet still wins at narrow widths. */
async function assertDialogTopLayerGeometry(dialog, mode, where) {
	const m = await dialog.evaluate((d) => {
		const r = d.getBoundingClientRect();
		return { l: r.left, t: r.top, w: r.width, h: r.height, vpW: window.innerWidth, vpH: window.innerHeight };
	});
	if (mode === 'centered') {
		const expL = Math.max(0, (m.vpW - m.w) / 2);
		const expT = Math.max(0, (m.vpH - m.h) / 2);
		expect(Math.abs(m.l - expL),
			`${where}: dialog left ${Math.round(m.l)} ≠ centered ${Math.round(expL)} — margin:auto did not survive the NC reset`)
			.toBeLessThanOrEqual(2);
		expect(Math.abs(m.t - expT),
			`${where}: dialog top ${Math.round(m.t)} ≠ centered ${Math.round(expT)}`)
			.toBeLessThanOrEqual(2);
		expect(m.l, `${where}: flush-left top-left anchor regression`).toBeGreaterThan(1);
	} else {
		expect(Math.abs(m.l), `${where}: ≤480 full-bleed sheet must pin to the left edge`).toBeLessThanOrEqual(1);
		expect(m.w, `${where}: ≤480 full-bleed sheet must span viewport width`).toBeGreaterThanOrEqual(m.vpW - 1);
		expect(m.t, `${where}: ≤480 full-bleed sheet must pin to the top edge`).toBeLessThanOrEqual(1);
	}
}

async function assertTransferDialogGeometry(page, selector, where) {
	const vpW = page.viewportSize()?.width ?? 0;
	await assertDialogTopLayerGeometry(
		page.locator(selector),
		vpW <= 480 ? 'fullbleed' : 'centered',
		`${where} @${vpW}px`,
	);
}

/** Upload a real export → debounced preview → report dialog open +
 * geometry asserted. Leaves the report dialog open for callers that chain
 * into the progress leg. */
async function driveTransferReport(page) {
	const file = await ensureTransferFile(page);
	await page.locator('#snk-transfer-import-passphrase').fill(TRANSFER_PASS);
	await page.locator('#snk-transfer-file').setInputFiles(file);
	await expect(page.locator('#snk-transfer-report[open]')).toBeVisible({ timeout: 30_000 });
	await assertTransferDialogGeometry(page, '#snk-transfer-report[open]', 'report');
}

test.describe('TRANSFER dialog painted geometry (margin:auto pack fix)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(5 * 60_000);

	for (const vp of [{ width: 1280, height: 800 }, { width: 768, height: 1024 }, { width: 375, height: 667 }, { width: 320, height: 568 }]) {
		test(`report dialog geometry @${vp.width}`, async ({ page }) => {
			await page.setViewportSize({ width: vp.width, height: vp.height });
			await gotoApp(page, `${BASE}${APP}/settings/transfer`);
			await driveTransferReport(page);
		});
	}

	test('progress dialog geometry @1280', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await gotoApp(page, `${BASE}${APP}/settings/transfer`);
		await driveTransferReport(page);
		await page.locator('#snk-transfer-confirm').click();
		const progress = page.locator('#snk-transfer-progress[open]');
		await expect(progress).toBeVisible({ timeout: 15_000 });
		await assertTransferDialogGeometry(page, '#snk-transfer-progress[open]', 'progress');
	});
});
