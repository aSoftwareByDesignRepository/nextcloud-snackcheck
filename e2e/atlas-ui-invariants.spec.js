// @ts-check
/**
 * Atlas UI invariants — shared-contract coverage for snackcheck web surfaces.
 *
 * Wires nextcloud/apps/_shared/e2e/atlas-ui-invariants.js onto representative
 * surfaces. Covered classes: a11y-dom (icon-only names, <24px targets,
 * focusable-in-aria-hidden), stored-xss (fixture item with payload name
 * rendered on /catalog), console errors, raw i18n keys, n+1 request count.
 * Double-submit/form-loss-on-5xx are covered by dialogs-confirm-cancel +
 * ux-journeys assertions (confirm-gate + requesttoken checks).
 */
const { test, expect } = require('@playwright/test');
const {
	assertA11yDom,
	assertNoConsoleErrors,
	assertNoInjection,
	assertNoRawI18nKeys,
	countApiRequests,
	trackConsoleErrors,
} = require('../../_shared/e2e/atlas-ui-invariants');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const APP = `${BASE}/index.php/apps/snackcheck`;
const CONTENT = '#snk-main-content';

/** session-cookie JSON call into the app API (same pattern as craft spec). */
async function api(page, method, path, body) {
	return page.evaluate(async ({ method: m, path: p, body: b }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const res = await fetch(p, {
			method: m,
			credentials: 'same-origin',
			headers: {
				requesttoken: token,
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: b === undefined ? undefined : JSON.stringify(b),
		});
		return { status: res.status, body: await res.text() };
	}, { method, path: `${APP}${path}`, body });
}

test.describe('SnackCheck UI invariants (atlas-ui-invariants)', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	for (const [label, url] of [['log', `${APP}/`], ['catalog', `${APP}/catalog`]]) {
		test(`a11y-dom sweep /${label}`, async ({ page }) => {
			await gotoApp(page, url);
			// .snk-check inputs are native checkboxes wrapped in their own <label>
			// — the label (text + box, full row height) is the real pointer target.
			const findings = await assertA11yDom(page, { content: CONTENT, sizeAllow: '.snk-check input' });
			expect(findings, `a11y-dom findings on /${label}:\n${findings.join('\n')}`).toEqual([]);
			// the allow-list must not hide a genuinely small label target
			const smallLabels = await page.evaluate(() => {
				const out = [];
				document.querySelectorAll('label.snk-check').forEach((el) => {
					const r = el.getBoundingClientRect();
					if (r.width === 0) return;
					if (r.width < 24 || r.height < 24) out.push(`label.snk-check ${Math.round(r.width)}x${Math.round(r.height)}`);
				});
				return out;
			});
			expect(smallLabels, `sub-24px label targets on /${label}`).toEqual([]);
		});

		test(`console errors + raw i18n keys /${label}`, async ({ page }) => {
			const errs = trackConsoleErrors(page);
			await gotoApp(page, url);
			assertNoConsoleErrors(errs);
			await assertNoRawI18nKeys(page, { content: CONTENT });
		});
	}

	test('n+1: /catalog issues a bounded number of app API requests', async ({ page }) => {
		const hits = await countApiRequests(page, async () => {
			await gotoApp(page, `${APP}/catalog`);
			await page.waitForLoadState('networkidle').catch(() => {});
		}, '/apps/snackcheck/api/');
		expect(hits.length, `catalog fired ${hits.length} app API requests (N+1 suspect): ${hits.map((h) => `${h.method} ${h.url}`).join(' | ')}`).toBeLessThanOrEqual(10);
	});

	test('stored-xss: payload item name renders escaped on /catalog', async ({ page }) => {
		// multi-site instances require an explicit siteId — ask for site 1, the
		// catalog page then carries it on the create form's hidden input.
		await gotoApp(page, `${APP}/catalog?siteId=1`);
		const siteId = await page.locator('input[name="siteId"]').first().inputValue().catch(() => '');
		test.skip(!siteId || siteId === '0', 'no active site on /catalog — cannot stage xss fixture');
		// snkp-* prefix: live_api_probe's SQL cleanup sweeps this prefix, so a
		// fixture is never stranded even if the soft-delete below fails.
		const stamp = `snkp-xss-${Date.now()}`;
		const payload = `${stamp}<img src="x" onerror="window.__atlasXss='img-onerror'">`;
		const created = await api(page, 'POST', '/api/catalog', {
			siteId: Number(siteId),
			name: payload,
			priceCents: 150,
		});
		test.skip(created.status !== 201 && created.status !== 200,
			`catalog create returned ${created.status}: ${created.body.slice(0, 160)}`);
		const id = JSON.parse(created.body)?.id ?? JSON.parse(created.body)?.data?.id;
		try {
			await page.reload({ waitUntil: 'domcontentloaded' });
			await assertNoInjection(page);
		} finally {
			if (id) await api(page, 'DELETE', `/api/catalog/${id}`);
		}
	});
});
