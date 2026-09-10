// @ts-check
/**
 * Atlas craft: capture SnackCheck web pages into artifacts/snackcheck/craft/web/
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const outDir = path.resolve(
	__dirname,
	'../../../../.cursor/atlas-farm-v3/artifacts/snackcheck/craft/web',
);

const PAGES = [
	// siteId=1 required for populated log tiles (empty "Choose a site" shell is not craft proof)
	{ id: 'log', path: '/index.php/apps/snackcheck/log?siteId=1' },
	{ id: 'catalog', path: '/index.php/apps/snackcheck/catalog?siteId=1' },
	{ id: 'settings', path: '/index.php/apps/snackcheck/settings' },
	{ id: 'my-month', path: '/index.php/apps/snackcheck/my-month' },
	{ id: 'pulse', path: '/index.php/apps/snackcheck/pulse?siteId=1' },
	{ id: 'periods', path: '/index.php/apps/snackcheck/periods' },
	{ id: 'hospitality', path: '/index.php/apps/snackcheck/hospitality' },
	{ id: 'sites', path: '/index.php/apps/snackcheck/sites' },
	{ id: 'audit', path: '/index.php/apps/snackcheck/audit' },
	{ id: 'users', path: '/index.php/apps/snackcheck/users' },
	{ id: 'root', path: '/index.php/apps/snackcheck/?siteId=1' },
];

test.describe('Atlas web craft screenshots', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS');
		// Tall enough that Log glyph tiles (icon + name + price) clear the bottom chrome.
		await page.setViewportSize({ width: 1280, height: 1100 });
	});

	test('capture web pages + log toast step', async ({ page }) => {
		fs.mkdirSync(outDir, { recursive: true });
		for (const p of PAGES) {
			await gotoApp(page, `${BASE}${p.path}`);
			await expect(page.locator('#app-content.snk-app, #snk-main-content, #content').first()).toBeVisible({
				timeout: 30_000,
			});
			if (p.id === 'log') {
				// Drinks-only: full glyph tiles (icon+name+price) with no second-row clip.
				const drinksFilter = page.getByRole('button', { name: /^Drinks$/i }).first();
				if (await drinksFilter.count()) {
					await drinksFilter.click();
					await page.waitForTimeout(200);
				}
				const tile = page.locator('button.snk-tile[data-snk-action="log"]').first();
				await expect(tile).toBeVisible({ timeout: 30_000 });
				await expect(tile.locator('.snk-tile__icon-svg, .snk-tile__icon .snk-icon').first()).toBeVisible({
					timeout: 15_000,
				});
				await expect(tile.locator('.snk-tile__name').first()).toBeVisible();
				await expect(tile.locator('.snk-tile__price').first()).toBeVisible();
				await expect(tile.locator('.snk-tile__img')).toHaveCount(0);
				const group = page.locator('.snk-log-group').first();
				await group.evaluate((el) => {
					el.scrollIntoView({ block: 'start', inline: 'nearest' });
				});
				// Ensure last visible tile bottom clears the viewport (no Instant REJECT clip).
				const lastTile = page.locator('button.snk-tile[data-snk-action="log"]').last();
				await lastTile.evaluate((el) => {
					const rect = el.getBoundingClientRect();
					const overflow = rect.bottom - (window.innerHeight - 24);
					if (overflow > 0) {
						const scroller =
							document.querySelector('#app-content') ||
							document.scrollingElement ||
							document.documentElement;
						scroller.scrollTop += overflow + 8;
					}
				});
				await page.waitForTimeout(300);
			}
			const file = path.join(outDir, `snackcheck-web-${p.id}.png`);
			await page.screenshot({ path: file, fullPage: false });
			console.log('craft', file);
		}
		// Log happy path toast craft
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
		const active = page.locator('button.snk-tile[data-snk-action="log"]:not([aria-disabled="true"])').first();
		if (await active.count()) {
			await active.click();
			const toast = page.locator('#snk-toast');
			await expect(toast).toBeVisible({ timeout: 10_000 });
			await page.screenshot({ path: path.join(outDir, 'snackcheck-web-log-toast.png'), fullPage: false });
		}
	});
});
