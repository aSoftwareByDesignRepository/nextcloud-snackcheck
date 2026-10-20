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
const { test, expect } = require('@playwright/test');
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

	// button_variant_specificity_collapse regression probe (visual critic r1
	// @3.5.17): NC core `button:not(.button-vue,[class^=vs__])` (0-1-1)
	// repaints every <button> pale + borderless — single-class variant rules
	// lose. Anchors are the oracle: core never repaints them, so a <button>
	// must compute the same fill/ink/border as an <a> with identical classes.
	// Synthetic probe DOM = no fixture dependence; also covers .snk-dialog
	// (JS-built confirm/restock dialogs append to document.body).
	test('ATLAS_RENDERED_SURFACE_CONTRACT button variants paint like anchors', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);

		const report = await page.evaluate(() => {
			const host = document.querySelector('#app-content-wrapper') || document.getElementById('app-content');
			if (!host) {
				return { error: 'no app host' };
			}
			const VARIANTS = ['', 'snk-btn--primary', 'snk-btn--secondary', 'snk-btn--ghost', 'snk-btn--danger'];
			const read = (el) => {
				const cs = getComputedStyle(el);
				return {
					bg: cs.backgroundColor,
					ink: cs.color,
					border: `${cs.borderTopWidth} ${cs.borderTopStyle} ${cs.borderTopColor}`,
				};
			};
			const mount = document.createElement('div');
			mount.id = 'atlas-btn-variant-probe';
			mount.style.display = 'none';
			host.appendChild(mount);
			const rows = [];
			for (const variant of VARIANTS) {
				const cls = ('snk-btn ' + variant).trim();
				const a = document.createElement('a');
				a.className = cls;
				a.href = '#';
				const b = document.createElement('button');
				b.type = 'button';
				b.className = cls;
				mount.append(a, b);
				rows.push({ scope: 'app', variant: variant || '(base)', anchor: read(a), button: read(b) });
			}
			// Disabled state — `button:not(.button-vue):disabled` (0-2-1) is
			// the same collapse class. Anchors can't :disable; compare against
			// the aria-disabled design.
			const aDis = document.createElement('a');
			aDis.className = 'snk-btn';
			aDis.href = '#';
			aDis.setAttribute('aria-disabled', 'true');
			const bDis = document.createElement('button');
			bDis.type = 'button';
			bDis.className = 'snk-btn';
			bDis.disabled = true;
			mount.append(aDis, bDis);
			rows.push({ scope: 'app', variant: '(disabled)', anchor: read(aDis), button: read(bDis) });
			// Collapse signature: the fill core repaints <button> with.
			const pale = document.createElement('button');
			pale.style.backgroundColor = 'var(--color-primary-element-light)';
			mount.appendChild(pale);
			const paleBg = getComputedStyle(pale).backgroundColor;
			// Dialog scope — JS-built dialogs append to document.body, outside
			// #app-content (snk-confirm-dialog / snk-restock-dialog). Mount
			// first: getComputedStyle is vacuous on detached elements.
			const dlg = document.createElement('dialog');
			dlg.className = 'snk-dialog';
			document.body.appendChild(dlg);
			for (const variant of ['snk-btn--primary', 'snk-btn--secondary', 'snk-btn--danger']) {
				const a = document.createElement('a');
				a.className = `snk-btn ${variant}`;
				a.href = '#';
				const b = document.createElement('button');
				b.type = 'button';
				b.className = `snk-btn ${variant}`;
				dlg.append(a, b);
				rows.push({ scope: 'dialog', variant, anchor: read(a), button: read(b) });
			}
			const out = { rows, paleBg };
			mount.remove();
			dlg.remove();
			return out;
		});

		expect(report.error).toBeUndefined();
		for (const row of report.rows) {
			const label = `${row.scope} ${row.variant}`;
			expect(row.button.bg, `${label}: <button> bg must equal <a> (got ${row.button.bg}, anchor ${row.anchor.bg})`).toBe(row.anchor.bg);
			expect(row.button.ink, `${label}: <button> ink must equal <a>`).toBe(row.anchor.ink);
			expect(row.button.border, `${label}: <button> border must equal <a>`).toBe(row.anchor.border);
		}
		// No variant may still wear core's pale repaint.
		for (const row of report.rows.filter((r) => r.variant !== '(base)' && r.variant !== 'snk-btn--ghost')) {
			expect(row.button.bg, `${row.scope} ${row.variant}: still paints core --color-primary-element-light`).not.toBe(report.paleBg);
		}
	});
});
