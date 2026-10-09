// @ts-check
/**
 * Atlas 3.5.14 ds_chrome — web craft captures: roles × themes × widths,
 * hospitality on/off (qty-tile seed non-regression), field-error, dialogs.
 * PNGs land in .cursor/atlas-farm-v3/artifacts/snackcheck/craft/web/.
 *
 * Serial: theme + hospitality_enabled are shared mutable state.
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { gotoApp } = require('./helpers/auth-guard');
const { setUserTheme } = require('./helpers/theming');
const { execFileSync } = require('child_process');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const craftDir = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/snackcheck/craft/web');
const nextcloudRoot = path.resolve(__dirname, '../../../');

const VIEWS = [
	{ name: 'log', url: `${BASE}/index.php/apps/snackcheck/log?siteId=1` },
	{ name: 'catalog', url: `${BASE}/index.php/apps/snackcheck/catalog?siteId=1` },
	{ name: 'pulse', url: `${BASE}/index.php/apps/snackcheck/pulse?siteId=1` },
	{ name: 'periods', url: `${BASE}/index.php/apps/snackcheck/periods` },
	{ name: 'hospitality', url: `${BASE}/index.php/apps/snackcheck/hospitality` },
	{ name: 'settings', url: `${BASE}/index.php/apps/snackcheck/settings` },
];
const THEMES = ['light', 'dark', 'light-highcontrast'];
const WIDTHS = [320, 768, 1440];
const MEMBER_VIEWS = [
	{ name: 'log', url: `${BASE}/index.php/apps/snackcheck/log?siteId=1` },
	{ name: 'my-month', url: `${BASE}/index.php/apps/snackcheck/my-month` },
];
const MANAGER_VIEWS = [
	{ name: 'hospitality', url: `${BASE}/index.php/apps/snackcheck/hospitality` },
	{ name: 'pulse', url: `${BASE}/index.php/apps/snackcheck/pulse` },
];

/** @type {Array<Record<string, string>>} */
const manifest = [];

/** @param {string[]} args */
function occ(args) {
	return execFileSync('docker', [
		'compose', 'exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
	], { cwd: nextcloudRoot, encoding: 'utf8', timeout: 60_000 });
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 * @param {Record<string, string>} meta
 */
async function shot(page, name, meta = {}) {
	fs.mkdirSync(craftDir, { recursive: true });
	const file = path.join(craftDir, name);
	await page.screenshot({ path: file, fullPage: false });
	manifest.push({ file: name, ...meta });
	return file;
}

/**
 * Fresh context + login as an arbitrary user (admin session is untouched).
 * @param {import('@playwright/test').Browser} browser
 * @param {string} user
 * @param {string} pass
 */
async function loginAs(browser, user, pass) {
	// browser.newContext() inherits `use` options (incl. admin storageState) —
	// force an empty jar so the login form actually appears.
	const ctx = await browser.newContext({
		viewport: { width: 1440, height: 900 },
		storageState: { cookies: [], origins: [] },
	});
	const page = await ctx.newPage();
	await page.goto(`${BASE}/index.php/login`, { waitUntil: 'domcontentloaded' });
	// structural selectors — NC core login chrome (same ids as e2e/global-setup.js)
	await page.locator('input#user, input[name="user"]').first().fill(user);
	await page.locator('input#password, input[name="password"]').first().fill(pass);
	await page.locator('button[type="submit"], input[type="submit"]').first().click();
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 });
	return { ctx, page };
}

/**
 * setUserTheme wipes `enabled-themes` for E2E_USER only; role users need the
 * same wipe under their own uid to dodge the NC34 TypeConflictException.
 * @param {import('@playwright/test').Page} page
 * @param {string} themeId
 * @param {string} uid
 */
async function setThemeFor(page, themeId, uid) {
	try {
		occ(['user:setting', uid, 'theming', 'enabled-themes', '--delete']);
	} catch { /* unset is fine */ }
	if (uid === (process.env.E2E_USER || 'admin')) {
		await setUserTheme(page, themeId);
		return;
	}
	const failures = await page.evaluate(async ({ target, all }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const headers = { requesttoken: token, 'OCS-APIRequest': 'true', Accept: 'application/json' };
		const problems = [];
		for (const id of all.filter((t) => t !== target)) {
			const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${id}`, {
				method: 'DELETE', credentials: 'same-origin', headers,
			});
			if (!res.ok && res.status !== 400) problems.push(`disable ${id}: HTTP ${res.status}`);
		}
		const res = await fetch(`/ocs/v2.php/apps/theming/api/v1/theme/${target}/enable`, {
			method: 'PUT', credentials: 'same-origin', headers,
		});
		if (!res.ok && res.status !== 400) problems.push(`enable ${target}: HTTP ${res.status}`);
		return problems;
	}, { target: themeId, all: ['light', 'dark', 'light-highcontrast', 'dark-highcontrast'] });
	if (failures.length > 0) {
		throw new Error(`Theme switch to "${themeId}" for ${uid} failed: ${failures.join('; ')}`);
	}
	await page.reload({ waitUntil: 'domcontentloaded' });
	await page.waitForSelector(`body[data-theme-${themeId}]`, { timeout: 15_000 });
}

test.describe('SnackCheck ds_chrome web craft (Atlas 3.5.14)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	test.afterAll(async () => {
		fs.mkdirSync(craftDir, { recursive: true });
		fs.writeFileSync(
			path.join(craftDir, '_ds-web-manifest.json'),
			JSON.stringify({ lane: 'ds_chrome', stage: 'craft', shots: manifest }, null, 2),
		);
	});

	test('theme × width sweep — admin primary views', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1440, height: 900 });
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log`);

		for (const theme of THEMES) {
			await setUserTheme(page, theme);
			for (const width of WIDTHS) {
				await page.setViewportSize({ width, height: width < 480 ? 740 : 1000 });
				for (const view of VIEWS) {
					await gotoApp(page, view.url);
					await shot(page, `snk-web-${view.name}-${theme}-${width}.png`, {
						view: view.name, theme, width: String(width), role: 'admin',
					});
				}
			}
		}
		await setUserTheme(page, 'light');
	});

	test('role crafts — member / manager / denied', async ({ browser }) => {
		const memberUser = process.env.SNK_MEMBER_USER;
		const managerUser = process.env.SNK_MANAGER_USER;
		const deniedUser = process.env.SNK_DENIED_USER;
		test.skip(!memberUser || !managerUser || !deniedUser, 'snk_* fixture users required');

		// Member: kitchen-nav items must be absent (pulse/catalog/users gated).
		const member = await loginAs(browser, /** @type {string} */ (memberUser), process.env.SNK_MEMBER_PASS || '');
		try {
			for (const theme of THEMES) {
				await gotoApp(member.page, `${BASE}/index.php/apps/snackcheck/log`);
				await setThemeFor(member.page, theme, /** @type {string} */ (memberUser));
				for (const view of MEMBER_VIEWS) {
					await gotoApp(member.page, view.url);
					const nav = member.page.locator('#app-navigation');
					if (await nav.count()) {
						for (const forbidden of ['pulse', 'catalog', 'users', 'periods', 'settings']) {
							const link = nav.locator(`a[href*="/apps/snackcheck/${forbidden}"]`);
							expect(await link.count(), `member must not see ${forbidden} nav`).toBe(0);
						}
					}
					await shot(member.page, `snk-web-${view.name}-member-${theme}-1440.png`, {
						view: view.name, theme, width: '1440', role: 'member',
					});
				}
			}
		} finally {
			await member.ctx.close();
		}

		// Kitchen manager: hospitality + pulse access (site-scoped).
		const manager = await loginAs(browser, /** @type {string} */ (managerUser), process.env.SNK_MANAGER_PASS || '');
		try {
			for (const theme of THEMES) {
				await gotoApp(manager.page, `${BASE}/index.php/apps/snackcheck/hospitality`);
				await setThemeFor(manager.page, theme, /** @type {string} */ (managerUser));
				for (const view of MANAGER_VIEWS) {
					await gotoApp(manager.page, view.url);
					const nav = manager.page.locator('#app-navigation');
					if (await nav.count()) {
						for (const forbidden of ['periods', 'settings', 'audit']) {
							const link = nav.locator(`a[href*="/apps/snackcheck/${forbidden}"]`);
							expect(await link.count(), `manager must not see ${forbidden} nav`).toBe(0);
						}
					}
					await shot(manager.page, `snk-web-${view.name}-manager-${theme}-1440.png`, {
						view: view.name, theme, width: '1440', role: 'kitchen_manager',
					});
				}
			}
		} finally {
			await manager.ctx.close();
		}

		// Denied user: uniform 403 surface, light only (role chrome, not theme).
		const denied = await loginAs(browser, /** @type {string} */ (deniedUser), process.env.SNK_DENIED_PASS || '');
		try {
			await denied.page.goto(`${BASE}/index.php/apps/snackcheck/log`, { waitUntil: 'domcontentloaded' });
			await expect(denied.page.locator('.snk-denied')).toBeVisible({ timeout: 15_000 });
			await shot(denied.page, 'snk-web-denied-light-1440.png', {
				view: 'denied', theme: 'light', width: '1440', role: 'denied',
			});
		} finally {
			await denied.ctx.close();
		}
	});

	test('state crafts — field-error row, edit dialog, qty bar', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1440, height: 1000 });
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log`);

		for (const theme of THEMES) {
			await setUserTheme(page, theme);
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/catalog?siteId=1`);

			// Edit dialog open (a11y native <dialog>, focus trap).
			const editBtn = page.locator('[data-snk-action="edit-item"]').first();
			await expect(editBtn).toBeVisible({ timeout: 10_000 });
			await editBtn.click();
			const dialog = page.locator('#snk-edit-item-dialog');
			await expect(dialog).toBeVisible({ timeout: 10_000 });
			await shot(page, `snk-web-dialog-edit-item-${theme}-1440.png`, {
				view: 'catalog', state: 'dialog-open', theme, width: '1440', role: 'admin',
			});

			// Field-error row: invalid price → aria-invalid + error toast, dialog stays.
			await dialog.locator('input[name="priceEuro"]').fill('abc,,,');
			await dialog.locator('button[type="submit"][value="confirm"]').click();
			const price = dialog.locator('input[name="priceEuro"]');
			await expect(price).toHaveAttribute('aria-invalid', 'true');
			await shot(page, `snk-web-field-error-catalog-edit-${theme}-1440.png`, {
				view: 'catalog', state: 'field-error', theme, width: '1440', role: 'admin',
			});

			// Close cleanly for the next theme.
			const cancel = dialog.locator('button[value="cancel"]');
			if (await cancel.count()) {
				await cancel.first().click().catch(() => {});
			}
			await page.keyboard.press('Escape').catch(() => {});
			await expect(dialog).toBeHidden({ timeout: 8000 }).catch(() => {});
		}

		// Qty chips (sole quantity control — qty-tile seed non-regression).
		await setUserTheme(page, 'light');
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
		await page.evaluate(() => {
			const det = document.getElementById('snk-log-advanced');
			if (det instanceof HTMLDetailsElement) det.open = true;
		});
		const qtyChip = page.locator('.snk-qty-chip').first();
		if (await qtyChip.count()) {
			await expect(qtyChip).toBeVisible({ timeout: 5000 });
			await qtyChip.click();
			await shot(page, 'snk-web-log-qty-light-1440.png', {
				view: 'log', state: 'qty-chip', theme: 'light', width: '1440', role: 'admin',
			});
		}
	});

	/**
	 * Toggle hospitality via the real admin settings API — NOT occ: CLI writes
	 * invalidate only the CLI APCu space, web requests would read stale values
	 * (memcache.local = APCu). This also exercises the app-side stale-state path.
	 * @param {import('@playwright/test').Page} page
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

	test('hospitality OFF — no stale mode chip / panel / nav; route redirects', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		const prev = occ(['config:app:get', 'snackcheck', 'hospitality_enabled']).trim();
		await page.setViewportSize({ width: 1440, height: 1000 });
		try {
			// Baseline: admin is allowlisted → Company mode chip must exist while ON.
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
			await setUserTheme(page, 'light');
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
			await expect(page.locator('[data-snk-mode][value="hospitality"]')).toHaveCount(1);

			await setHospitality(page, false);
			await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);

			await expect(page.locator('[data-snk-mode][value="hospitality"]')).toHaveCount(0);
			expect(await page.locator('#snk-mode-hospitality').count(), 'stale hospitality panel').toBe(0);
			const navHosp = page.locator('#app-navigation a[href*="/apps/snackcheck/hospitality"]');
			expect(await navHosp.count(), 'stale hospitality nav').toBe(0);
			await shot(page, 'snk-web-log-hospitality-off-light-1440.png', {
				view: 'log', state: 'hospitality-off', theme: 'light', width: '1440', role: 'admin',
			});

			// Direct /hospitality URL must redirect to log — no orphan surface.
			await page.goto(`${BASE}/index.php/apps/snackcheck/hospitality`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#app-content.snk-app, #snk-main-content', { timeout: 30_000 });
			expect(page.url(), '/hospitality must redirect to /log when disabled').toContain('/apps/snackcheck/log');
			await shot(page, 'snk-web-hospitality-off-redirect-light-1440.png', {
				view: 'hospitality', state: 'off-redirect', theme: 'light', width: '1440', role: 'admin',
			});
		} finally {
			if (prev === '1') {
				// Re-enable via the same web API (server-side APCu invalidation).
				await page.goto(`${BASE}/index.php/apps/snackcheck/log`, { waitUntil: 'domcontentloaded' }).catch(() => {});
				await setHospitality(page, true).catch(async () => {
					// CLI fallback for restore only; verify effect via a fresh fetch.
					occ(['config:app:set', 'snackcheck', 'hospitality_enabled', '--value=1']);
				});
			}
		}
	});
});
