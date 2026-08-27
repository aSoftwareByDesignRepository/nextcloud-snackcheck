// @ts-check
/**
 * App Store screenshot capture for SnackCheck (snackcheck).
 * Shoots key kitchen surfaces at 1920×1040 (same canvas as HomeCheck / DutyCheck).
 *
 * Run:
 *   npx playwright test e2e/capture-store-screenshots.spec.js --project=chromium-store
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const outDir = path.resolve(__dirname, '../screenshots');

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
async function shot(page, name) {
	fs.mkdirSync(outDir, { recursive: true });
	await page.waitForTimeout(500);
	await page.screenshot({
		path: path.join(outDir, name),
		fullPage: false,
	});
}

/**
 * Prefer a settled page chrome (title + main) before shooting.
 * @param {import('@playwright/test').Page} page
 */
async function waitChrome(page) {
	await expect(page.locator('#app-content.snk-app, #snk-main-content').first()).toBeVisible({ timeout: 30_000 });
	await page.locator('#snk-page-title, .snk-page-header, .snk-brand__title').first().waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
}

test.describe('App Store screenshots', () => {
	test.beforeEach(async ({ page }, testInfo) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		test.skip(
			testInfo.project.name !== 'chromium-store',
			'App-store screenshot capture is only for chromium-store (1920×1040)',
		);
	});

	test('capture store screenshots', async ({ page }) => {
		// 01 — Log (primary one-tap surface)
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log`);
		await waitChrome(page);
		await shot(page, 'snackcheck-screenshot-01-log.png');

		// 02 — My month (personal statement)
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/my-month`);
		await waitChrome(page);
		await shot(page, 'snackcheck-screenshot-02-my-month.png');

		// 03 — Catalog
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/catalog`);
		await waitChrome(page);
		await shot(page, 'snackcheck-screenshot-03-catalog.png');

		// 04 — Kitchen pulse
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/pulse`);
		await waitChrome(page);
		await shot(page, 'snackcheck-screenshot-04-pulse.png');

		// 05 — Periods / money close
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/periods`);
		await waitChrome(page);
		await shot(page, 'snackcheck-screenshot-05-periods.png');

		// 06 — Users (colleague proxy pick)
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/users`);
		await waitChrome(page);
		const proxyChip = page.locator('.snk-mode-chip').filter({ has: page.locator('input[value="proxy"]') });
		if (await proxyChip.count()) {
			await proxyChip.click();
			await expect(page.locator('#snk-mode-proxy')).toBeVisible();
			await page.waitForTimeout(300);
		}
		await shot(page, 'snackcheck-screenshot-06-users.png');

		// 07 — Settings (access)
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/access`);
		await waitChrome(page);
		await shot(page, 'snackcheck-screenshot-07-settings.png');

		const files = [
			'snackcheck-screenshot-01-log.png',
			'snackcheck-screenshot-02-my-month.png',
			'snackcheck-screenshot-03-catalog.png',
			'snackcheck-screenshot-04-pulse.png',
			'snackcheck-screenshot-05-periods.png',
			'snackcheck-screenshot-06-users.png',
			'snackcheck-screenshot-07-settings.png',
		];
		for (const name of files) {
			const full = path.join(outDir, name);
			expect(fs.existsSync(full), `missing ${name}`).toBeTruthy();
			expect(fs.statSync(full).size).toBeGreaterThan(20_000);
		}
	});
});
