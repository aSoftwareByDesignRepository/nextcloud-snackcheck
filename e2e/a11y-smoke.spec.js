// @ts-check
/**
 * Shell chrome + axe WCAG 2.1 AA smoke for SnackCheck.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

const URLS = {
	log: `${BASE}/index.php/apps/snackcheck/`,
	mymonth: `${BASE}/index.php/apps/snackcheck/my-month`,
	pulse: `${BASE}/index.php/apps/snackcheck/pulse`,
	catalog: `${BASE}/index.php/apps/snackcheck/catalog`,
	periods: `${BASE}/index.php/apps/snackcheck/periods`,
	users: `${BASE}/index.php/apps/snackcheck/users`,
	sites: `${BASE}/index.php/apps/snackcheck/sites`,
	hospitality: `${BASE}/index.php/apps/snackcheck/hospitality`,
	audit: `${BASE}/index.php/apps/snackcheck/audit`,
	brreport: `${BASE}/index.php/apps/snackcheck/br-report`,
	settings: `${BASE}/index.php/apps/snackcheck/settings/access`,
	benefits: `${BASE}/index.php/apps/snackcheck/settings/benefits`,
};

test.describe('SnackCheck shell chrome a11y smoke', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	for (const [name, url] of Object.entries(URLS)) {
		test(`${name}: skip links, live regions, tokens, axe`, async ({ page }) => {
			await gotoApp(page, url);
			// Feature-gated pages may redirect (hospitality→log, sites→settings); shell must still load.
			const app = page.locator('#app-content.snk-app').first();
			await expect(app).toBeVisible();
			await expect(app.locator('a.snk-skip-link[href="#snk-main-content"]')).toBeAttached();
			await expect(app.locator('a.snk-skip-link[href="#app-navigation"]')).toBeAttached();
			await expect(page.locator('#snk-live-region')).toBeAttached();
			await expect(page.locator('#snk-alert-region')).toBeAttached();
			await expect(page.locator('#snk-main-content')).toBeAttached();
			await expect(page.locator('.snk-page-stack')).toBeAttached();
			await expect(app.getByRole('heading', { level: 1 }).first()).toBeAttached();
			await expect(page.locator('#app-navigation.snk-nav')).toBeAttached();

			const tokens = await page.evaluate(() => {
				const el = document.querySelector('#app-content.snk-app');
				const cs = el ? getComputedStyle(el) : getComputedStyle(document.body);
				return {
					bgSoft: cs.getPropertyValue('--snk-bg-soft').trim(),
					touch: cs.getPropertyValue('--snk-touch').trim(),
					focus: cs.getPropertyValue('--snk-focus').trim(),
					shellDisplay: (() => {
						const shell = document.querySelector('#app-content-wrapper.snk-shell');
						return shell ? getComputedStyle(shell).flexDirection : '';
					})(),
				};
			});
			expect(tokens.bgSoft, 'soft background token').not.toEqual('');
			expect(tokens.touch).toBe('44px');
			expect(tokens.focus).toContain('3px');
			expect(tokens.shellDisplay).toBe('column');

			const results = await new AxeBuilder({ page })
				.include('#content')
				.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
				.analyze();
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
		});
	}

	test('mobile 375: no horizontal document overflow on log', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });
		await gotoApp(page, URLS.log);
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(overflow).toBeLessThanOrEqual(2);
	});
});
