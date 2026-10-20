// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/snackcheck/craft/web/. The companion gate
 * .cursor/atlas-farm-v3/scripts/check-screenshot-matrix.py verifies
 * completeness — a declared cell without a real PNG fails the craft stage.
 *
 * States:
 *   populated — the live dev instance already carries real rows (catalog
 *               items, periods, logs, sites, audit events); captured as-is.
 *   empty     — honest zero-row render on a dedicated empty fixture site:
 *               "Atlas Empty Küche" (site 14) is reactivated for the capture
 *               window via the real admin API (PUT /api/sites/14) and
 *               deactivated again in afterAll — zero new rows, true
 *               .snk-empty renders, no shared-fixture mutation left behind.
 *   current   — state-invariant chrome (settings underpages, my-month,
 *               hospitality — content legitimately varies by period/flag).
 *
 * Serial: theme, hospitality_enabled and site-14 active flag are shared
 * mutable state on the one dev instance; siblings must not interleave.
 *
 * Run (from the app dir):
 *   npx playwright test -c e2e/playwright.screenshot-matrix.config.js
 */
const { test, expect, devices } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { gotoApp } = require('./helpers/auth-guard');
const { setUserTheme, resetUserTheme } = require('./helpers/theming');
const {
	hospitalityFlag,
	ensureHospitalityOn,
	restoreHospitality,
} = require('./helpers/hospitality');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const APP = '/index.php/apps/snackcheck';
const MANIFEST = JSON.parse(fs.readFileSync(path.join(__dirname, 'screenshot-matrix.json'), 'utf8'));
const OUT = path.resolve(__dirname, '../../../../.cursor/atlas-farm-v3/artifacts/snackcheck', MANIFEST.outDir);
const STORAGE = process.env.E2E_STORAGE_STATE
	? path.resolve(process.env.E2E_STORAGE_STATE)
	: path.resolve(__dirname, '..', '.auth', 'storage-state.json');

/** Dedicated empty-catalog fixture site ("Atlas Empty Küche"). */
const EMPTY_SITE_ID = 14;

/** Selector that proves the intended state actually rendered. */
const READY = {
	'log': { populated: '#snk-log-catalog', empty: '.snk-empty' },
	'my-month': { current: '.snk-statement, .snk-empty' },
	'catalog': { populated: '.snk-table', empty: '.snk-empty' },
	'pulse': { populated: '#snk-restock-card', empty: '.snk-empty' },
	'periods': { populated: '.snk-table' },
	'users': { populated: '#snk-users-proxy-controls', empty: '.snk-empty' },
	'sites': { populated: '.snk-site-list' },
	'hospitality': { current: '.snk-card' },
	'audit': { populated: '.snk-table' },
	'br-report': { populated: '.snk-table' },
	'settings-access': { current: '#snk-access-form' },
	'settings-benefits': { current: '.snk-form' },
	'settings-privacy': { current: '.snk-form' },
	'settings-pulse': { current: '.snk-form' },
	'settings-digests': { current: '.snk-form' },
	'settings-unlock': { current: '.snk-form' },
	'settings-license': { current: '.snk-form' },
	'settings-support': { current: '.snk-support-extra, .snk-support-us' },
};

const capturedFiles = [];

/** Session-bound JSON call against the app admin API (requesttoken-aware). */
async function api(page, method, apiPath, body) {
	return page.evaluate(
		async ({ method, apiPath, body }) => {
			const token =
				(typeof window.OC !== 'undefined' && window.OC.requestToken)
				|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
				|| '';
			const res = await fetch(apiPath, {
				method,
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIRequest': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			});
			const text = await res.text();
			let data = null;
			try {
				data = text ? JSON.parse(text) : null;
			} catch {
				data = { raw: text };
			}
			return { status: res.status, data };
		},
		{ method, apiPath, body },
	);
}

async function setSiteActive(page, siteId, active) {
	const res = await api(page, 'PUT', `${APP}/api/sites/${siteId}`, { active });
	expect(
		res.status,
		`site ${siteId} active=${active} → ${JSON.stringify(res.data)}`,
	).toBe(200);
}

/** Empty-driver URL: scope the surface to the empty fixture site. */
function emptyUrl(surface) {
	const base = surface.path.split('?')[0];
	return `${APP}${base}?siteId=${EMPTY_SITE_ID}`;
}

async function shot(page, name, meta) {
	fs.mkdirSync(OUT, { recursive: true });
	const file = path.join(OUT, name);
	await page.screenshot({ path: file, fullPage: false });
	const size = fs.statSync(file).size;
	expect(size, `${name} implausibly small (${size}B) — blank/error frame`).toBeGreaterThan(4096);
	capturedFiles.push({ file: name, ...meta });
}

test.describe('SnackCheck screenshot matrix (ATLAS §VISUAL)', () => {
	test.describe.configure({ mode: 'serial' });
	test.setTimeout(300_000);

	/** @type {import('@playwright/test').BrowserContext} */
	let ctx;
	/** @type {import('@playwright/test').Page} */
	let adminPage;
	let hospitalityPrev = '0';
	let site14WasReactivated = false;

	test.beforeAll(async ({ browser }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		ctx = await browser.newContext({
			...devices['Desktop Chrome'],
			storageState: STORAGE,
		});
		adminPage = await ctx.newPage();
		await gotoApp(adminPage, `${BASE}${APP}/log?siteId=1`);
		hospitalityPrev = hospitalityFlag() ? '1' : '0';
	});

	test.afterAll(async () => {
		const page = adminPage;
		// Restore order: flag + site state before theme reset — the shared
		// instance must come back as we found it even if a capture failed.
		try {
			if (page && !page.isClosed()) {
				await restoreHospitality(page, hospitalityPrev).catch(() => {});
				if (site14WasReactivated) {
					await setSiteActive(page, EMPTY_SITE_ID, false).catch(() => {});
				}
				await resetUserTheme(page).catch(() => {});
			}
		} finally {
			fs.mkdirSync(OUT, { recursive: true });
			fs.writeFileSync(
				path.join(OUT, '_screenshot-matrix-manifest.json'),
				JSON.stringify(
					{ lane: 'ds_chrome', stage: 'craft', spec: 'capture-screenshot-matrix', shots: capturedFiles },
					null,
					2,
				),
			);
			if (ctx) {
				await ctx.close().catch(() => {});
			}
		}
	});

	test('fixture: reactivate empty site + pin hospitality', async () => {
		const needsEmpty = MANIFEST.surfaces.some((s) => (s.states || []).includes('empty'));
		const needsHosp = MANIFEST.surfaces.some((s) => s.needsHospitality);
		if (needsEmpty) {
			await setSiteActive(adminPage, EMPTY_SITE_ID, true);
			site14WasReactivated = true;
		}
		if (needsHosp) {
			await ensureHospitalityOn(adminPage);
		}
	});

	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states || ['current']) {
			test(`${surface.id} ${state} — viewport sweep`, async () => {
				const url = state === 'empty' && surface.emptyDriver === 'emptySite'
					? emptyUrl(surface)
					: `${APP}${surface.path}`;
				const ready = (READY[surface.id] || {})[state] || '#snk-main-content';
				for (const vp of MANIFEST.viewports) {
					await adminPage.setViewportSize({ width: vp.width, height: vp.height });
					await gotoApp(adminPage, `${BASE}${url}`);
					if (surface.needsHospitality) {
						// A sibling lane may have flipped the flag mid-run — re-pin.
						expect(
							adminPage.url(),
							'hospitality page redirected — flag raced off',
						).toContain('/apps/snackcheck/hospitality');
					}
					await adminPage.waitForSelector(ready, { timeout: 30_000 });
					await shot(
						adminPage,
						`${MANIFEST.prefix}-${surface.id}-${state}-${vp.name}.png`,
						{ surface: surface.id, state, viewport: vp.name },
					);
				}
			});
		}
	}

	test('dark cells — desktop', async () => {
		await adminPage.setViewportSize({ width: 1280, height: 800 });
		await setUserTheme(adminPage, 'dark');
		try {
			for (const surface of MANIFEST.surfaces.filter((s) => s.dark)) {
				const firstState = (surface.states || ['current'])[0];
				const url = `${APP}${surface.path}`;
				const ready = (READY[surface.id] || {})[firstState] || '#snk-main-content';
				await gotoApp(adminPage, `${BASE}${url}`);
				if (surface.needsHospitality) {
					expect(
						adminPage.url(),
						'hospitality page redirected — flag raced off',
					).toContain('/apps/snackcheck/hospitality');
				}
				await adminPage.waitForSelector(ready, { timeout: 30_000 });
				await shot(
					adminPage,
					`${MANIFEST.prefix}-${surface.id}-${firstState}-desktop-dark.png`,
					{ surface: surface.id, state: firstState, viewport: 'desktop', theme: 'dark' },
				);
			}
		} finally {
			await resetUserTheme(adminPage);
		}
	});
});
