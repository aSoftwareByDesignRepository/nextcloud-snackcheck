// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — tall license settings must scroll to the end.
 * Guards CSS Overflow L3 unpaired overflow-x:clip truncating tablet seats / key form.
 */
const { test } = require('@playwright/test');
const { assertAtlasVerticalScrollReachable } = require('../../_shared/e2e/atlas-vertical-scroll-contract');
const { ensureAuthenticated } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

test.describe('ATLAS_VERTICAL_SCROLL_CONTRACT', () => {
	test('license settings page scrolls to kitchen tablets end', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 640 });
		await page.goto(`${BASE}/index.php/apps/snackcheck/settings/license`, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await page.waitForSelector('#snk-license-page, #snk-license-key', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#snk-term-list-title, #snk-license-key-hint, #snk-license-page',
			bottomSlopPx: 12,
		});
	});

	test('license settings stays reachable at phone height', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 667 });
		await page.goto(`${BASE}/index.php/apps/snackcheck/settings/license`, { waitUntil: 'domcontentloaded' });
		await ensureAuthenticated(page);
		await page.waitForSelector('#snk-license-page, #snk-license-key', { timeout: 45_000 });

		await assertAtlasVerticalScrollReachable(page, {
			scrollport: '#app-content',
			target: '#snk-term-list-title, #snk-license-key-hint, #snk-license-page',
			bottomSlopPx: 16,
		});
	});
});
