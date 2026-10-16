// @ts-check
/**
 * Shared hospitality_enabled flag helpers for craft specs.
 *
 * hospitality_enabled is GLOBAL app config on the shared dev instance —
 * sibling farm lanes (kiosk pairing, OFF-legs) toggle it concurrently.
 * Specs that capture hospitality surfaces must pin the flag ON via the
 * real admin settings API (CLI occ writes invalidate only CLI APCu; web
 * requests would read stale values) and re-pin on external flips instead
 * of silently capturing the /log redirect as a "hospitality" shot.
 */
const { expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { gotoApp } = require('./auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const nextcloudRoot = path.resolve(__dirname, '../../../..');
const HOSPITALITY_URL = `${BASE}/index.php/apps/snackcheck/hospitality`;

/** @param {string[]} args */
function occ(args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 });
}

function hospitalityFlag() {
	return occ(['config:app:get', 'snackcheck', 'hospitality_enabled']).trim() === '1';
}

/**
 * Toggle hospitality via the real admin settings API — NOT occ: CLI writes
 * invalidate only the CLI APCu space, web requests would read stale values
 * (memcache.local = APCu). This also exercises the app-side stale-state path.
 * @param {import('@playwright/test').Page} page  admin page on any app URL
 * @param {boolean} on
 */
async function setHospitality(page, on) {
	const res = await page.evaluate(async (enabled) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const body = new URLSearchParams({ hospitalityEnabled: enabled ? '1' : '0' });
		const r = await fetch('/index.php/apps/snackcheck/api/admin/settings', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { requesttoken: token, 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		});
		return { status: r.status, ok: r.ok };
	}, on);
	if (!res.ok) {
		throw new Error(`hospitality toggle → HTTP ${res.status}`);
	}
}

/**
 * Pin hospitality ON (admin session) and prove /hospitality actually renders
 * — a redirect to /log means a sibling lane flipped the flag between our POST
 * and the navigation; re-pin and retry. Fails loudly after retries exhaust
 * rather than capturing the log page under a hospitality filename.
 * @param {import('@playwright/test').Page} page  admin page
 */
async function ensureHospitalityOn(page, { retries = 4 } = {}) {
	for (let attempt = 0; attempt < retries; attempt++) {
		await setHospitality(page, true);
		await gotoApp(page, HOSPITALITY_URL);
		if (page.url().includes('/apps/snackcheck/hospitality')) {
			await expect(
				page.locator('#app-navigation a[href*="/apps/snackcheck/hospitality"]'),
			).toHaveCount(1);
			return;
		}
	}
	throw new Error('hospitality_enabled kept flipping off during pin retries — another lane is actively toggling it');
}

/**
 * Post-navigation guard for a hospitality shot: if the flag flipped off
 * mid-sweep the URL is /log — re-pin via `adminPage` and reload. Returns
 * when the URL is genuinely /hospitality or throws.
 * @param {import('@playwright/test').Page} page  page that navigated (any role)
 * @param {import('@playwright/test').Page} adminPage  admin session used for re-pinning
 */
async function assertOnHospitality(page, adminPage, { retries = 4 } = {}) {
	for (let attempt = 0; attempt < retries; attempt++) {
		if (page.url().includes('/apps/snackcheck/hospitality')) {
			return;
		}
		await setHospitality(adminPage, true);
		await gotoApp(page, HOSPITALITY_URL);
	}
	expect(page.url(), 'hospitality page redirected to /log — flag raced off').toContain('/apps/snackcheck/hospitality');
}

/**
 * Restore the flag to the state observed at spec entry.
 * @param {import('@playwright/test').Page} page  admin page
 * @param {string} prev  '0'/'1' from hospitalityFlag()/occ at entry
 */
async function restoreHospitality(page, prev) {
	await page.goto(`${BASE}/index.php/apps/snackcheck/log`, { waitUntil: 'domcontentloaded' }).catch(() => {});
	await setHospitality(page, prev === '1').catch(async () => {
		// CLI fallback for restore only; verify effect via a fresh fetch.
		occ(['config:app:set', 'snackcheck', 'hospitality_enabled', `--value=${prev === '1' ? '1' : '0'}`]);
	});
}

module.exports = {
	occ,
	hospitalityFlag,
	setHospitality,
	ensureHospitalityOn,
	assertOnHospitality,
	restoreHospitality,
	HOSPITALITY_URL,
};
