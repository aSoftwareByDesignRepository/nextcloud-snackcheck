// @ts-check
/**
 * Theme × viewport × WCAG 2.1 AA gauntlet for SnackCheck.
 *
 * Proves for every selectable NC theme and key routes:
 *  - theme actually switched (body[data-theme-*]),
 *  - design tokens resolve from Nextcloud --color-* (tints mix into main-bg),
 *  - zero horizontal overflow from 320 px up to 4K,
 *  - primary chrome touch targets ≥ 44×44,
 *  - zero axe WCAG 2.1 A/AA violations on the app shell,
 *  - default shell is not locked to a fixed 72rem / 1200px max-width.
 */
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { gotoApp, dismissOpenAppNavigation } = require('./helpers/auth-guard');
const {
	setUserTheme,
	resetUserTheme,
	setAccentColor,
	resetAccentColor,
	USER_THEMES,
} = require('./helpers/theming');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

const routes = [
	{ id: 'log', url: `${BASE}/index.php/apps/snackcheck/` },
	{ id: 'pulse', url: `${BASE}/index.php/apps/snackcheck/pulse` },
	{ id: 'catalog', url: `${BASE}/index.php/apps/snackcheck/catalog` },
	{ id: 'mymonth', url: `${BASE}/index.php/apps/snackcheck/my-month` },
	{ id: 'settings', url: `${BASE}/index.php/apps/snackcheck/settings/access` },
];

const overflowViewports = [
	{ width: 320, height: 640 },
	{ width: 375, height: 812 },
	{ width: 768, height: 1024 },
	{ width: 1024, height: 768 },
	{ width: 1440, height: 900 },
	{ width: 2560, height: 1440 },
];

const axeViewports = [
	{ width: 320, height: 640 },
	{ width: 768, height: 1024 },
	{ width: 1280, height: 800 },
];

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function expectNoHorizontalOverflow(page, label) {
	await dismissOpenAppNavigation(page);
	const overflow = await page.evaluate(() => {
		const doc = document.documentElement;
		const app = document.querySelector('#app-content.snk-app');
		const shell = document.querySelector('#app-content-wrapper.snk-shell, #app-content-wrapper');
		const shellOx = shell ? getComputedStyle(shell).overflowX : '';
		return {
			doc: doc.scrollWidth - doc.clientWidth,
			app: app ? app.scrollWidth - app.clientWidth : 0,
			shell: shell ? shell.scrollWidth - shell.clientWidth : 0,
			shellClipped: shellOx === 'hidden' || shellOx === 'clip',
		};
	});
	expect(overflow.doc, `document horizontal overflow at ${label}`).toBeLessThanOrEqual(2);
	expect(overflow.app, `#app-content overflow at ${label}`).toBeLessThanOrEqual(2);
	if (!overflow.shellClipped) {
		expect(overflow.shell, `.snk-shell overflow at ${label}`).toBeLessThanOrEqual(2);
	}
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertThemeTokensResolved(page) {
	const tokens = await page.evaluate(() => {
		const bodyCs = getComputedStyle(document.body);
		const rootCs = getComputedStyle(document.documentElement);
		const el = document.querySelector('#app-content.snk-app');
		const cs = el ? getComputedStyle(el) : bodyCs;
		const shell = document.querySelector('#app-content-wrapper.snk-shell, #app-content.snk-app');
		const nav = document.querySelector('#app-navigation.snk-nav');
		return {
			ncBg: bodyCs.getPropertyValue('--color-main-background').trim(),
			ncText: bodyCs.getPropertyValue('--color-main-text').trim(),
			ncPrimary: bodyCs.getPropertyValue('--color-primary-element').trim(),
			bodyPrimary: bodyCs.getPropertyValue('--snk-primary').trim(),
			bodyMuted: bodyCs.getPropertyValue('--snk-muted').trim(),
			productStage: bodyCs.getPropertyValue('--snk-product-stage').trim(),
			rootRadiusMd: rootCs.getPropertyValue('--snk-radius-md').trim(),
			rootTouch: rootCs.getPropertyValue('--snk-touch').trim(),
			bgSoft: cs.getPropertyValue('--snk-bg-soft').trim() || bodyCs.getPropertyValue('--snk-bg-soft').trim(),
			text: cs.getPropertyValue('--snk-text').trim() || bodyCs.getPropertyValue('--snk-text').trim(),
			muted: cs.getPropertyValue('--snk-muted').trim() || bodyCs.getPropertyValue('--snk-muted').trim(),
			primary: cs.getPropertyValue('--snk-primary').trim() || bodyCs.getPropertyValue('--snk-primary').trim(),
			tintInfo: cs.getPropertyValue('--snk-tint-info').trim() || bodyCs.getPropertyValue('--snk-tint-info').trim(),
			tintSuccess: cs.getPropertyValue('--snk-tint-success').trim() || bodyCs.getPropertyValue('--snk-tint-success').trim(),
			dangerFill: cs.getPropertyValue('--snk-danger-fill').trim() || bodyCs.getPropertyValue('--snk-danger-fill').trim(),
			dangerOnFill: cs.getPropertyValue('--snk-danger-on-fill').trim() || bodyCs.getPropertyValue('--snk-danger-on-fill').trim(),
			dangerInk: cs.getPropertyValue('--snk-danger-ink').trim() || bodyCs.getPropertyValue('--snk-danger-ink').trim(),
			scrim: cs.getPropertyValue('--snk-scrim').trim() || bodyCs.getPropertyValue('--snk-scrim').trim(),
			shadowSm: cs.getPropertyValue('--snk-shadow-sm').trim() || bodyCs.getPropertyValue('--snk-shadow-sm').trim(),
			touch: cs.getPropertyValue('--snk-touch').trim() || rootCs.getPropertyValue('--snk-touch').trim(),
			focus: cs.getPropertyValue('--snk-focus').trim() || bodyCs.getPropertyValue('--snk-focus').trim(),
			navBg: nav ? getComputedStyle(nav).backgroundColor : '',
			shellMax: shell ? getComputedStyle(shell).maxWidth : '',
			shellDisplay: shell ? getComputedStyle(shell).flexDirection : '',
		};
	});
	expect(tokens.ncBg, 'NC --color-main-background').not.toEqual('');
	expect(tokens.ncText, 'NC --color-main-text').not.toEqual('');
	expect(tokens.ncPrimary, 'NC --color-primary-element').not.toEqual('');
	expect(tokens.bodyPrimary, 'body --snk-primary (sidebar/dialogs inherit)').not.toEqual('');
	expect(tokens.bodyMuted, 'body --snk-muted').not.toEqual('');
	expect(tokens.rootRadiusMd === '12px' || parseFloat(tokens.rootRadiusMd) === 12, 'root radius-md').toBeTruthy();
	expect(tokens.rootTouch === '44px' || parseFloat(tokens.rootTouch) >= 44, 'root touch').toBeTruthy();
	expect(tokens.bgSoft, 'snk-bg-soft').not.toEqual('');
	expect(tokens.text, 'snk-text').not.toEqual('');
	expect(tokens.primary, 'snk-primary').not.toEqual('');
	expect(tokens.muted, 'snk-muted').not.toEqual('');
	expect(tokens.productStage, '--snk-product-stage').not.toEqual('');
	expect(tokens.tintInfo, 'tint-info must resolve').not.toEqual('');
	expect(tokens.tintSuccess, 'tint-success must resolve').not.toEqual('');
	expect(tokens.dangerFill, 'danger-fill must resolve').not.toEqual('');
	expect(tokens.dangerOnFill, 'danger-on-fill must resolve').not.toEqual('');
	expect(tokens.dangerInk, 'danger-ink must resolve').not.toEqual('');
	expect(
		/,\s*transparent\s*\)\s*$/i.test(tokens.tintInfo),
		`tint-info must mix into main-background, got: ${tokens.tintInfo}`,
	).toBeFalsy();
	expect(tokens.scrim, 'scrim token').not.toEqual('');
	expect(tokens.shadowSm, 'shadow-sm token').not.toEqual('');
	expect(tokens.touch === '44px' || parseFloat(tokens.touch) >= 44, 'touch target token ≥44px').toBeTruthy();
	expect(tokens.focus).toContain('3px');
	expect(tokens.navBg, 'sidebar must resolve themed background').not.toEqual('');
	expect(
		tokens.shellMax === 'none'
			|| tokens.shellMax === ''
			|| tokens.shellMax === '100%'
			|| parseFloat(tokens.shellMax) >= 2000,
		`default shell must not be a fixed 72rem/1200px lock (got ${tokens.shellMax})`,
	).toBeTruthy();
}

/**
 * Log tile product wells must follow NC theme tokens — never a hardcoded white slab in dark mode.
 * @param {import('@playwright/test').Page} page
 * @param {string} theme
 */
async function assertProductStageThemeAware(page, theme) {
	const result = await page.evaluate(() => {
		const media = document.querySelector('#app-content.snk-app .snk-tile__media');
		const bodyCs = getComputedStyle(document.body);
		if (!media) {
			return { skip: true };
		}
		const mediaCs = getComputedStyle(media);
		return {
			skip: false,
			stageToken: bodyCs.getPropertyValue('--snk-product-stage').trim(),
			mediaBg: mediaCs.backgroundColor,
		};
	});
	if (result.skip) {
		return;
	}
	expect(result.stageToken, '--snk-product-stage must resolve').not.toEqual('');
	expect(result.mediaBg, 'tile media background').not.toEqual('');
	if (theme === 'dark' || theme === 'dark-highcontrast') {
		expect(
			result.mediaBg,
			`dark theme product stage must not be pure white (got ${result.mediaBg})`,
		).not.toMatch(/^rgb\(255,\s*255,\s*255\)$/);
	}
}

/**
 * @param {import('@playwright/test').Page} page
 */
async function assertChromeTouchTargets(page) {
	const result = await page.evaluate(() => {
		const nodes = [
			...document.querySelectorAll(
				'#app-content.snk-app .snk-btn--primary, #app-content.snk-app button.snk-tile, #app-content.snk-app .snk-qty-chip, #app-content.snk-app .snk-mode-chip, #app-content.snk-app .snk-filter, #app-content.snk-app .snk-settings-nav__link',
			),
		].slice(0, 40);
		const undersized = [];
		for (const el of nodes) {
			const style = getComputedStyle(el);
			if (style.display === 'none' || style.visibility === 'hidden') continue;
			// Skip controls inside collapsed progressive disclosure.
			const details = el.closest('details');
			if (details && !details.open) continue;
			const rect = el.getBoundingClientRect();
			if (rect.width < 2 && rect.height < 2) continue;
			const minH = Math.max(rect.height, parseFloat(style.minHeight) || 0);
			const minW = Math.max(rect.width, parseFloat(style.minWidth) || 0);
			const isBar = rect.width >= 120;
			if (minH < 44 || (!isBar && minW < 44)) {
				undersized.push({
					tag: el.tagName,
					cls: String(el.className).slice(0, 80),
					w: Math.round(minW),
					h: Math.round(minH),
					minHcss: style.minHeight,
				});
			}
		}
		return { ok: undersized.length === 0, undersized };
	});
	expect(result.ok, JSON.stringify(result.undersized)).toBeTruthy();
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
async function runAxe(page, label) {
	await page.locator('#snk-toast, .toast, .toastify').evaluateAll((nodes) => {
		nodes.forEach((n) => n.remove());
	}).catch(() => {});
	const results = await new AxeBuilder({ page })
		.include('#content')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
		.exclude('#snk-toast')
		.analyze();
	expect(
		results.violations,
		`axe violations at ${label}:\n${JSON.stringify(results.violations, null, 2)}`,
	).toEqual([]);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 */
async function gotoReady(page, url) {
	await gotoApp(page, url);
	await expect(page.locator('#snk-main-content, #app-content.snk-app').first()).toBeAttached({ timeout: 30_000 });
	await page.waitForFunction(() => {
		const body = getComputedStyle(document.body);
		return body.getPropertyValue('--color-main-text').trim() !== ''
			&& body.getPropertyValue('--color-main-background').trim() !== '';
	}, null, { timeout: 10_000 }).catch(() => {});
}

test.describe('SnackCheck theme × viewport a11y matrix', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	for (const theme of USER_THEMES) {
		for (const route of routes) {
			test(`${theme}: ${route.id}`, async ({ page }) => {
				test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');

				await page.setViewportSize({ width: 1280, height: 800 });
				await gotoReady(page, route.url);
				await setUserTheme(page, theme);
				await dismissOpenAppNavigation(page);
				await expect(page.locator(`body[data-theme-${theme}]`)).toBeAttached();
				await expect(page.locator('#app-content.snk-app').first()).toBeVisible();

				await assertThemeTokensResolved(page);
				await assertProductStageThemeAware(page, theme);
				await assertChromeTouchTargets(page);
				await expectNoHorizontalOverflow(page, `${theme}/${route.id}@1280`);

				for (const vp of axeViewports) {
					await page.setViewportSize(vp);
					await dismissOpenAppNavigation(page);
					await expectNoHorizontalOverflow(page, `${theme}/${route.id}@${vp.width}`);
					await runAxe(page, `${theme}/${route.id}@${vp.width}`);
				}
			});
		}
	}

	test('overflow matrix @ light (all breakpoints)', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await gotoReady(page, routes[0].url);
		await setUserTheme(page, 'light');
		for (const vp of overflowViewports) {
			await page.setViewportSize(vp);
			await dismissOpenAppNavigation(page);
			await expectNoHorizontalOverflow(page, `light@${vp.width}x${vp.height}`);
		}
	});

	test('Log tiles use equal columns (2-up @600, 1-up @400)', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await gotoReady(page, routes[0].url);
		await setUserTheme(page, 'light');
		await dismissOpenAppNavigation(page);

		await page.setViewportSize({ width: 600, height: 800 });
		const cols600 = await page.evaluate(() => {
			const grid = document.querySelector('#app-content.snk-app .snk-tile-grid');
			if (!grid) return null;
			return getComputedStyle(grid).gridTemplateColumns;
		});
		if (cols600 === null) {
			test.info().annotations.push({ type: 'note', description: 'no tile grid on this fixture' });
			return;
		}
		expect(cols600.split(' ').length, `grid@600=${cols600}`).toBe(2);

		await page.setViewportSize({ width: 400, height: 800 });
		const cols400 = await page.evaluate(() => {
			const grid = document.querySelector('#app-content.snk-app .snk-tile-grid');
			if (!grid) return null;
			return getComputedStyle(grid).gridTemplateColumns;
		});
		expect(cols400.split(' ').length, `grid@400=${cols400}`).toBe(1);
	});

	test('Log tiles in a row share equal widths', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
		await gotoReady(page, routes[0].url);
		await setUserTheme(page, 'light');
		await dismissOpenAppNavigation(page);
		const widths = await page.evaluate(() => {
			const group = document.querySelector('#app-content.snk-app .snk-log-group');
			if (!group) return [];
			return Array.from(group.querySelectorAll('button.snk-tile')).slice(0, 4).map((el) => {
				const r = el.getBoundingClientRect();
				return Math.round(r.width * 10) / 10;
			});
		});
		if (widths.length < 2) {
			test.info().annotations.push({ type: 'note', description: 'need ≥2 tiles to compare widths' });
			return;
		}
		const max = Math.max(...widths);
		const min = Math.min(...widths);
		expect(max - min, `tile widths=${widths.join(',')}`).toBeLessThanOrEqual(2);
	});

	test('custom accent primary resolves into snk-primary', async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		const accent = '#c45c26';
		try {
			setAccentColor(accent);
			await page.setViewportSize({ width: 1280, height: 800 });
			await gotoReady(page, routes[0].url);
			await setUserTheme(page, 'light');
			await page.reload({ waitUntil: 'domcontentloaded' });
			await expect(page.locator('#app-content.snk-app').first()).toBeVisible();
			const primary = await page.evaluate(() => {
				const el = document.querySelector('#app-content.snk-app');
				const cs = el ? getComputedStyle(el) : getComputedStyle(document.body);
				return {
					snk: cs.getPropertyValue('--snk-primary').trim(),
					nc: getComputedStyle(document.body).getPropertyValue('--color-primary-element').trim(),
				};
			});
			expect(primary.nc, 'NC primary after accent').not.toEqual('');
			expect(primary.snk, 'snk-primary after accent').not.toEqual('');
			// Design tokens must track the NC accent (normalize rgb/hex loosely).
			const norm = (v) => v.replace(/\s+/g, '').toLowerCase();
			expect(norm(primary.snk), 'snk-primary equals NC primary-element').toEqual(norm(primary.nc));
			await runAxe(page, 'accent-light-log');
		} finally {
			resetAccentColor();
			await resetUserTheme(page).catch(() => {});
		}
	});

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage();
		try {
			if (process.env.E2E_USER) {
				await gotoReady(page, routes[0].url);
				await resetUserTheme(page).catch(() => {});
			}
		} finally {
			await page.close();
			try {
				resetAccentColor();
			} catch {
				/* occ may be unavailable offline */
			}
		}
	});
});
