// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — core NC toggle must open nav at phone width (SnackCheck DS choice) */
const { test } = require('@playwright/test');
const { assertAtlasMobileNav } = require('../../_shared/e2e/atlas-mobile-nav-contract');
const { ensureAuthenticated } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

test('ATLAS_MOBILE_NAV_CONTRACT core toggle opens drawer', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto(`${BASE}/apps/snackcheck/`, { waitUntil: 'domcontentloaded' });
	await ensureAuthenticated(page);
	await page.waitForSelector('#app-navigation', { timeout: 30000 });
	const toggle = page.locator('#app-navigation-toggle, .app-navigation-toggle').first();
	await assertAtlasMobileNav(page, {
		toggle,
		nav: page.locator('#app-navigation'),
	});
});
