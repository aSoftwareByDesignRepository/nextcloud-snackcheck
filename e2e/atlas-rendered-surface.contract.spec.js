// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — SnackCheck page surfaces.
 *
 * Asserts the *rendered* truth of each app page: content lists keep markers,
 * selects vertically centre their value, icons render non-zero, form controls
 * are not centered by shell leaks. DOM-only specs pass on visually broken
 * pages (marker resets, sunken selects, 0×0 icons) — this catches them.
 *
 * Every SnackCheck page surface is listed in SURFACES. Marker-less list
 * designs (tile grids, chip rows, rank lists, nav lists) are SnackCheck
 * design language and opt out via listAllow — never silently skipped.
 */
const { test } = require('@playwright/test');
const { gotoApp } = require('./helpers/auth-guard');
const { assertAtlasRenderedSurface } = require('../../_shared/e2e/atlas-rendered-surface-contract');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

// Pages where a site scope must be explicit append ?siteId=1 (Default site
// always exists — EnsureSnackCheckSchema seeds it).
const SURFACES = [
	['/index.php/apps/snackcheck/log?siteId=1', 'log'],
	['/index.php/apps/snackcheck/my-month', 'my-month'],
	['/index.php/apps/snackcheck/catalog?siteId=1', 'catalog'],
	['/index.php/apps/snackcheck/pulse?siteId=1', 'pulse'],
	['/index.php/apps/snackcheck/periods', 'periods'],
	['/index.php/apps/snackcheck/sites', 'sites'],
	['/index.php/apps/snackcheck/users', 'users'],
	['/index.php/apps/snackcheck/audit', 'audit'],
	['/index.php/apps/snackcheck/br-report', 'br-report'],
	['/index.php/apps/snackcheck/settings/access', 'settings-access'],
	['/index.php/apps/snackcheck/settings/benefits', 'settings-benefits'],
	['/index.php/apps/snackcheck/settings/privacy', 'settings-privacy'],
	['/index.php/apps/snackcheck/settings/pulse', 'settings-pulse'],
	['/index.php/apps/snackcheck/settings/digests', 'settings-digests'],
	['/index.php/apps/snackcheck/settings/unlock', 'settings-unlock'],
	['/index.php/apps/snackcheck/settings/license', 'settings-license'],
	['/index.php/apps/snackcheck/settings/support', 'settings-support'],
];

const CONTRACT_OPTS = {
	content: '#snk-main-content, #app-content.snk-app',
	navExclude:
		'#app-navigation, nav, .snk-nav, .snk-settings-nav, .snk-breadcrumb, .snk-nav-footer',
	// Marker-less by design (tile/card/row design language — css/app.css sets
	// list-style:none intentionally on these components).
	listAllow:
		'.snk-list, .snk-tile-grid, .snk-rank-list, .snk-chip-list, .snk-nav__list, ' +
		'.snk-nav__sublist, .snk-site-list, .snk-term-list, .snk-user-results, ' +
		'.snk-nav-footer__menu',
};

test.describe('ATLAS_RENDERED_SURFACE_CONTRACT', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
	});

	for (const [path, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name}`, async ({ page }) => {
			await gotoApp(page, `${BASE}${path}`);
			await assertAtlasRenderedSurface(page, CONTRACT_OPTS);
		});
	}

	// Hospitality redirects to /log when the flag is off — prove the surface
	// only when the page actually renders (no silent pass on a redirect).
	test('ATLAS_RENDERED_SURFACE_CONTRACT hospitality (when enabled)', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/hospitality`);
		if (!page.url().includes('/hospitality')) {
			test.skip(true, 'Hospitality mode disabled — page redirects to log');
		}
		await assertAtlasRenderedSurface(page, CONTRACT_OPTS);
	});
});
