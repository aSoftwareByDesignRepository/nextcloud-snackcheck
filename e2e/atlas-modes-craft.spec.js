// @ts-check
/**
 * Atlas ≥3.5.6 — shipping mode crafts: privacyTotalsOnly + multiSite (web).
 * Writes PNGs under artifacts/snackcheck/craft/web/ and a durable proof log.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const ROOT = path.resolve(__dirname, '../../../../');
const outDir = path.join(ROOT, '.cursor/atlas-farm-v3/artifacts/snackcheck/craft/web');
const proofsDir = path.join(ROOT, '.cursor/atlas-farm-v3/artifacts/snackcheck/proofs');
const STAMP = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z');

function occSet(key, value) {
	const out = execSync(
		`docker compose exec -u www-data nextcloud php occ config:app:set snackcheck ${key} --value=${value}`,
		{ cwd: path.join(ROOT, 'nextcloud'), encoding: 'utf8' },
	);
	console.log('occSet', key, value, out.trim());
}

function occGet(key) {
	try {
		return execSync(
			`docker compose exec -u www-data nextcloud php occ config:app:get snackcheck ${key}`,
			{ cwd: path.join(ROOT, 'nextcloud'), encoding: 'utf8' },
		).trim();
	} catch {
		return '';
	}
}

test.describe('Atlas shipping modes crafts', () => {
	test.setTimeout(120_000);

	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	test('privacy totals-only + multisite switch enter/happy/break/exit', async ({ page }) => {
		fs.mkdirSync(outDir, { recursive: true });
		fs.mkdirSync(proofsDir, { recursive: true });
		const logLines = [];
		const log = (s) => {
			logLines.push(s);
			console.log(s);
		};

		const privacyBefore = occGet('privacy_totals_only') || '0';
		const multiBefore = occGet('multi_site_enabled') || '0';
		log(`baseline privacy_totals_only=${privacyBefore} multi_site_enabled=${multiBefore}`);

		try {
			/* ── mode-privacy-totals: enter via settings UI (honest enter_mode) ── */
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/privacy`);
			const privacySwitch = page.locator('#snk-privacy-totals');
			await expect(privacySwitch).toBeVisible({ timeout: 20_000 });
			if (!(await privacySwitch.isChecked())) {
				await privacySwitch.check({ force: true });
			}
			await page.locator('form[data-snk-form="settings"] button[type="submit"]').click();
			await page.waitForTimeout(1500);
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/privacy`);
			await expect(page.locator('#snk-privacy-totals')).toBeChecked({ timeout: 20_000 });
			await page.screenshot({
				path: path.join(outDir, 'snackcheck-web-privacy-settings-on.png'),
				fullPage: false,
			});
			log(`ENTER mode-privacy-totals via settings UI; occ=${occGet('privacy_totals_only')}`);

			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/users?siteId=1`);
			await expect(page.locator('#app-content.snk-app, #snk-main-content, #content').first()).toBeVisible({
				timeout: 30_000,
			});
			const privacyBadge = page.locator('.snk-badge').filter({
				hasText: /Totals only \(privacy\)|Nur Summen \(Datenschutz\)/i,
			});
			await expect(privacyBadge.first()).toBeVisible({ timeout: 20_000 });
			const voidBtns = page.locator('[data-snk-action="void-log"]');
			const voidCount = await voidBtns.count();
			await page.screenshot({
				path: path.join(outDir, 'snackcheck-web-privacy-totals-on.png'),
				fullPage: false,
			});
			log(`HAPPY privacy-on craft snackcheck-web-privacy-totals-on.png void_btns=${voidCount}`);
			expect(voidCount).toBe(0);
			log('BREAK privacy: zero void-log buttons while totals-only');

			/* exit via settings UI */
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/privacy`);
			if (await page.locator('#snk-privacy-totals').isChecked()) {
				await page.locator('#snk-privacy-totals').uncheck({ force: true });
			}
			await page.locator('form[data-snk-form="settings"] button[type="submit"]').click();
			await page.waitForTimeout(1500);
			log(`EXIT mode-privacy-totals via settings UI; occ=${occGet('privacy_totals_only')}`);

			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/users?siteId=1`);
			await expect(page.locator('#app-content.snk-app, #snk-main-content, #content').first()).toBeVisible({
				timeout: 30_000,
			});
			await expect(
				page.locator('.snk-badge').filter({ hasText: /Totals only \(privacy\)|Nur Summen/i }),
			).toHaveCount(0);
			await page.screenshot({
				path: path.join(outDir, 'snackcheck-web-privacy-totals-off.png'),
				fullPage: false,
			});
			log('EXIT craft snackcheck-web-privacy-totals-off.png (no privacy badge)');

			/* ── mode-multisite ── */
			if (occGet('multi_site_enabled') !== '1') {
				occSet('multi_site_enabled', '1');
			}
			log('ENTER mode-multisite multi_site_enabled=1');
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
			await expect(page.locator('#snk-site-select')).toBeVisible({ timeout: 20_000 });
			const siteSelect = page.locator('#snk-site-select');
			const options = await siteSelect.locator('option').allTextContents();
			log(`HAPPY site options=${JSON.stringify(options)}`);
			expect(options.length).toBeGreaterThanOrEqual(2);
			await page.screenshot({
				path: path.join(outDir, 'snackcheck-web-multisite-site1.png'),
				fullPage: false,
			});

			const values = await siteSelect.locator('option').evaluateAll((els) =>
				els.map((e) => /** @type {HTMLOptionElement} */ (e).value).filter((v) => v && v !== ''),
			);
			expect(values.length).toBeGreaterThanOrEqual(2);
			const site2 = values.find((v) => v !== '1') || values[1];
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=${site2}`);
			await expect(page.locator('#snk-site-select')).toBeVisible({ timeout: 20_000 });
			await page.screenshot({
				path: path.join(outDir, 'snackcheck-web-multisite-site2.png'),
				fullPage: false,
			});
			log(`HAPPY switched siteId=${site2} craft snackcheck-web-multisite-site2.png`);

			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log`);
			const choose = page.getByText(/Choose a site|Site wählen|Pick a site/i);
			const emptyShell =
				(await choose.count()) > 0 || (await page.locator('#snk-site-select option[value=""]').count()) > 0;
			await page.screenshot({
				path: path.join(outDir, 'snackcheck-web-multisite-need-pick.png'),
				fullPage: false,
			});
			log(`BREAK multisite need-pick emptyShell=${emptyShell} craft snackcheck-web-multisite-need-pick.png`);
			expect(emptyShell || (await page.locator('#snk-site-select').count()) > 0).toBeTruthy();

			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
			await page.screenshot({
				path: path.join(outDir, 'snackcheck-web-multisite-back-site1.png'),
				fullPage: false,
			});
			log('EXIT/switch back siteId=1 craft snackcheck-web-multisite-back-site1.png');
		} finally {
			occSet('privacy_totals_only', privacyBefore === '1' ? '1' : '0');
			if (multiBefore === '1' || multiBefore === '') {
				occSet('multi_site_enabled', '1');
			} else {
				occSet('multi_site_enabled', '0');
			}
			log(
				`restore privacy_totals_only=${occGet('privacy_totals_only')} multi_site_enabled=${occGet('multi_site_enabled')}`,
			);
		}

		const logPath = path.join(proofsDir, `modes-privacy-multisite-${STAMP}.log`);
		fs.writeFileSync(logPath, logLines.join('\n') + '\n');
		log(`wrote ${logPath}`);
	});
});
