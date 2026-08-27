// @ts-check
/**
 * Full UX visual audit capture for SnackCheck.
 * Shots land in documentation/ (not the public app tree).
 *
 * Run from nextcloud/apps/snackcheck:
 *   npx playwright test e2e/capture-ux-audit.spec.js --project=chromium-ux-audit
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { gotoApp, dismissOpenAppNavigation } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const outDir = path.resolve(
	__dirname,
	'../../../../documentation/snackcheck/audits/ux-shots-2026-08-27',
);

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
async function shot(page, name) {
	fs.mkdirSync(outDir, { recursive: true });
	await dismissOpenAppNavigation(page);
	await page.waitForTimeout(350);
	await page.screenshot({
		path: path.join(outDir, name),
		fullPage: true,
	});
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function waitChrome(page) {
	await expect(page.locator('#app-content.snk-app, #snk-main-content').first()).toBeVisible({
		timeout: 30_000,
	});
	await page
		.locator('#snk-page-title, .snk-page-header, .snk-brand__title')
		.first()
		.waitFor({ state: 'visible', timeout: 15_000 })
		.catch(() => {});
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 * @param {string} file
 */
async function capturePage(page, url, file) {
	await gotoApp(page, url.startsWith('http') ? url : `${BASE}${url}`);
	await waitChrome(page);
	await shot(page, file);
}

test.describe('UX audit screenshots', () => {
	test.describe.configure({ timeout: 180_000 });

	test.beforeEach(async ({}, testInfo) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		test.skip(
			testInfo.project.name !== 'chromium-ux-audit' && testInfo.project.name !== 'chromium-ux-audit-mobile',
			'UX audit capture only',
		);
	});

	test('desktop: all pages + settings + dialogs', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-ux-audit', 'desktop only');
		const prefix = 'd-';

		const pages = [
			['/index.php/apps/snackcheck/log', '01-log.png'],
			['/index.php/apps/snackcheck/my-month', '02-my-month.png'],
			['/index.php/apps/snackcheck/catalog', '03-catalog.png'],
			['/index.php/apps/snackcheck/pulse', '04-pulse.png'],
			['/index.php/apps/snackcheck/periods', '05-periods.png'],
			['/index.php/apps/snackcheck/users', '06-users.png'],
			['/index.php/apps/snackcheck/hospitality', '07-hospitality.png'],
			['/index.php/apps/snackcheck/sites', '08-sites.png'],
			['/index.php/apps/snackcheck/audit', '09-audit.png'],
			['/index.php/apps/snackcheck/br-report', '10-br-report.png'],
			['/index.php/apps/snackcheck/settings/access', '11-settings-access.png'],
			['/index.php/apps/snackcheck/settings/benefits', '12-settings-benefits.png'],
			['/index.php/apps/snackcheck/settings/privacy', '13-settings-privacy.png'],
			['/index.php/apps/snackcheck/settings/pulse', '14-settings-pulse.png'],
			['/index.php/apps/snackcheck/settings/digests', '15-settings-digests.png'],
			['/index.php/apps/snackcheck/settings/unlock', '16-settings-unlock.png'],
			['/index.php/apps/snackcheck/settings/license', '17-settings-license.png'],
			['/index.php/apps/snackcheck/settings/support', '18-settings-support.png'],
		];
		for (const [url, file] of pages) {
			await capturePage(page, url, prefix + file);
		}

		// Log modes
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log`);
		await waitChrome(page);
		const colleague = page.locator('.snk-mode-chip, .snk-mode-bar label').filter({ hasText: /colleague|kolleg/i }).first();
		if (await colleague.count()) {
			await colleague.click();
			await page.waitForTimeout(300);
			await shot(page, prefix + '19-log-colleague.png');
		}
		const company = page.locator('.snk-mode-chip, .snk-mode-bar label').filter({ hasText: /company|firma|betrieb|hospital/i }).first();
		if (await company.count()) {
			await company.click();
			await page.waitForTimeout(300);
			await shot(page, prefix + '20-log-company.png');
		}

		// Users proxy
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/users`);
		await waitChrome(page);
		const proxyChip = page.locator('.snk-mode-chip').filter({ has: page.locator('input[value="proxy"]') });
		if (await proxyChip.count()) {
			await proxyChip.click();
			await expect(page.locator('#snk-mode-proxy')).toBeVisible({ timeout: 5000 }).catch(() => {});
			await shot(page, prefix + '21-users-proxy.png');
		}

		// Catalog dialogs
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/catalog`);
		await waitChrome(page);
		const editBtn = page.locator('[data-snk-action="edit-item"], button:has-text("Edit"), button:has-text("Bearbeiten")').first();
		if (await editBtn.count()) {
			await editBtn.click();
			await expect(page.locator('#snk-edit-item-dialog')).toBeVisible({ timeout: 5000 }).catch(() => {});
			await shot(page, prefix + '22-dialog-edit-item.png');
			await page.keyboard.press('Escape').catch(() => {});
			await page.locator('#snk-edit-item-dialog').waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {});
		}
		const restockBtn = page.locator('[data-snk-action="restock"]').first();
		if (await restockBtn.count()) {
			const instant = await restockBtn.getAttribute('data-instant');
			if (instant !== '1') {
				await restockBtn.click();
				await expect(page.locator('#snk-restock-dialog')).toBeVisible({ timeout: 5000 }).catch(() => {});
				await shot(page, prefix + '23-dialog-restock.png');
				await page.keyboard.press('Escape').catch(() => {});
			}
		}
		const deactivateBtn = page.locator('[data-snk-action="deactivate-item"], [data-snk-action="deactivate"]').first();
		if (await deactivateBtn.count()) {
			await deactivateBtn.click();
			await expect(page.locator('#snk-deactivate-dialog')).toBeVisible({ timeout: 5000 }).catch(() => {});
			await shot(page, prefix + '24-dialog-deactivate.png');
			await page.keyboard.press('Escape').catch(() => {});
		}

		// Periods dialogs
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/periods`);
		await waitChrome(page);
		const closeBtn = page.locator('[data-snk-action="close-period"]').first();
		if (await closeBtn.count() && await closeBtn.isVisible().catch(() => false)) {
			await closeBtn.click({ timeout: 5000 }).catch(() => {});
			await expect(page.locator('#snk-close-dialog')).toBeVisible({ timeout: 5000 }).catch(() => {});
			await shot(page, prefix + '25-dialog-close-period.png');
			await page.keyboard.press('Escape').catch(() => {});
		}

		// Confirm dialog (clear license when present, else deactivate site)
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/license`);
		await waitChrome(page);
		const clearLic = page.locator('[data-snk-action="clear-license"]').first();
		if (await clearLic.count()) {
			await clearLic.click();
			await expect(page.locator('#snk-confirm-dialog')).toBeVisible({ timeout: 5000 }).catch(() => {});
			await shot(page, prefix + '26-dialog-confirm-clear-license.png');
			await page.locator('#snk-confirm-dialog button[value="cancel"], #snk-confirm-dialog .snk-btn').first().click().catch(() => {});
		}

		const shotFiles = fs.readdirSync(outDir).filter((f) => f.startsWith(prefix) && f.endsWith('.png'));
		expect(shotFiles.length).toBeGreaterThanOrEqual(15);
	});

	test('mobile: core pages', async ({ page }, testInfo) => {
		test.skip(testInfo.project.name !== 'chromium-ux-audit-mobile', 'mobile only');
		const prefix = 'm-';
		const pages = [
			['/index.php/apps/snackcheck/log', '01-log.png'],
			['/index.php/apps/snackcheck/catalog', '02-catalog.png'],
			['/index.php/apps/snackcheck/pulse', '03-pulse.png'],
			['/index.php/apps/snackcheck/settings/license', '04-settings-license.png'],
			['/index.php/apps/snackcheck/settings/unlock', '05-settings-unlock.png'],
			['/index.php/apps/snackcheck/periods', '06-periods.png'],
			['/index.php/apps/snackcheck/users', '07-users.png'],
		];
		for (const [url, file] of pages) {
			await capturePage(page, url, prefix + file);
		}
		expect(fs.readdirSync(outDir).filter((f) => f.startsWith(prefix)).length).toBeGreaterThanOrEqual(5);
	});
});
