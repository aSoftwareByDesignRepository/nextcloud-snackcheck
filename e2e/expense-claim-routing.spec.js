// @ts-check
/**
 * F-SN2 claim routing — the REAL ExpenseCheck round-trip:
 * admin configures routing + EXP mapping → an expense submit becomes an EXP
 * draft claim (no ledger row) → resubmit dedupes onto the same draft →
 * settings restored afterwards. Skips cleanly when expensecheck is absent.
 */
const { test, expect } = require('@playwright/test');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

/** Same-origin fetch inside the admin page session (requesttoken + cookies). */
async function api(page, method, path, params) {
	return page.evaluate(async ({ method, path, params }) => {
		const token = (typeof window.OC !== 'undefined' && window.OC.requestToken)
			|| document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken')
			|| '';
		const opts = { method, credentials: 'same-origin', headers: { requesttoken: token } };
		if (params !== undefined) {
			opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
			opts.body = new URLSearchParams(params).toString();
		}
		const r = await fetch(path, opts);
		let json = null;
		try { json = await r.json(); } catch { /* non-json */ }
		return { status: r.status, ok: r.ok, json };
	}, { method, path, params });
}

/** Discover a non-attendee EXP category + any cost center via the admin API. */
async function discoverExpMapping(page) {
	const cats = await api(page, 'GET', '/index.php/apps/expensecheck/api/admin/categories');
	if (cats.status === 404 || cats.status === 412) return null;
	const ccs = await api(page, 'GET', '/index.php/apps/expensecheck/api/admin/cost-centers');
	if (!cats.ok || !ccs.ok) return null;
	const catList = cats.json?.categories || cats.json?.items || cats.json?.data || [];
	const ccList = ccs.json?.cost_centers || ccs.json?.items || ccs.json?.data || [];
	const cat = catList.find((c) => Number(c.requires_attendees) === 0 && Number(c.enabled ?? 1) === 1);
	const cc = ccList[0];
	if (!cat || !cc) return null;
	return { categoryId: Number(cat.id), costCenterId: Number(cc.id) };
}

test.describe('SnackCheck → ExpenseCheck claim routing (F-SN2)', () => {
	let mapping = null;

	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
		mapping = await discoverExpMapping(page);
		test.skip(!mapping, 'expensecheck absent or has no usable category/cost-center');
	});

	test.afterEach(async ({ page }) => {
		// Restore ledger routing — a stranded claim mode would silently reroute
		// every later suite's expense fixtures.
		await api(page, 'POST', '/index.php/apps/snackcheck/api/admin/settings', {
			expenseRouting: 'ledger_credit',
			expenseClaimCategoryId: '0',
			expenseClaimCostCenterId: '0',
		});
	});

	test('expense submit becomes an EXP draft claim and dedupes on retry', async ({ page }) => {
		const saved = await api(page, 'POST', '/index.php/apps/snackcheck/api/admin/settings', {
			expenseRouting: 'expense_claim',
			expenseClaimCategoryId: String(mapping.categoryId),
			expenseClaimCostCenterId: String(mapping.costCenterId),
		});
		expect(saved.ok).toBeTruthy();
		expect(saved.json?.data?.expenseRouting || saved.json?.expenseRouting).toBe('expense_claim');

		const idem = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
		const submit = await api(page, 'POST', '/index.php/apps/snackcheck/api/expenses', {
			siteId: '1',
			amountCents: '1337',
			expenseReason: 'E2E claim routing',
			idempotencyKey: idem,
		});
		expect(submit.status).toBe(200);
		const data = submit.json?.data || submit.json;
		expect(data.routed).toBe('expense_claim');
		expect(data.ref).toBeTruthy();
		expect(data.url).toContain('/apps/expensecheck/claims/');

		// The draft exists in EXP with provenance on its line.
		const claimId = data.url.split('/claims/')[1];
		const claim = await api(page, 'GET', `/index.php/apps/expensecheck/api/claims/${claimId}`);
		expect(claim.ok).toBeTruthy();
		const c = claim.json?.claim || claim.json?.data || claim.json;
		expect(c.status).toBe('draft');
		expect(Number(c.total_minor)).toBe(1337);
		const line = (c.lines || [])[0];
		expect(line).toBeTruthy();
		expect(line.source_app).toBe('snackcheck');
		expect(line.source_ref).toBe(`snkexp:${idem}`);
		expect(Number(line.amount_minor)).toBe(1337);

		// Retry with the SAME idempotency key → same draft, never a second claim.
		const retry = await api(page, 'POST', '/index.php/apps/snackcheck/api/expenses', {
			siteId: '1',
			amountCents: '1337',
			expenseReason: 'E2E claim routing',
			idempotencyKey: idem,
		});
		const retryData = retry.json?.data || retry.json;
		expect(retryData.routed).toBe('expense_claim');
		expect(retryData.ref).toBe(data.ref);
		expect(retryData.url).toBe(data.url);

		// No key, no write — same contract as the ledger path. A generated
		// source_ref would mint a fresh claim per retry: silent duplicates.
		const keyless = await api(page, 'POST', '/index.php/apps/snackcheck/api/expenses', {
			siteId: '1',
			amountCents: '500',
			expenseReason: 'E2E keyless submit',
		});
		expect(keyless.status).toBe(422);
	});

	test('concurrent submits with the same key mint exactly one claim (TOCTOU)', async ({ page }) => {
		const saved = await api(page, 'POST', '/index.php/apps/snackcheck/api/admin/settings', {
			expenseRouting: 'expense_claim',
			expenseClaimCategoryId: String(mapping.categoryId),
			expenseClaimCostCenterId: String(mapping.costCenterId),
		});
		expect(saved.ok).toBeTruthy();

		const idem = `e2e-race-${Date.now()}`;
		const body = {
			siteId: '1',
			amountCents: '777',
			expenseReason: 'E2E concurrent claim',
			idempotencyKey: idem,
		};
		// Two parallel submits racing the request_key dedupe — the loser must
		// land on the winner's draft, not on an HTTP 500 or a second claim.
		const [a, b] = await Promise.all([
			api(page, 'POST', '/index.php/apps/snackcheck/api/expenses', body),
			api(page, 'POST', '/index.php/apps/snackcheck/api/expenses', body),
		]);
		expect(a.status).toBe(200);
		expect(b.status).toBe(200);
		const ra = a.json?.data || a.json;
		const rb = b.json?.data || b.json;
		expect(ra.routed).toBe('expense_claim');
		expect(rb.routed).toBe('expense_claim');
		expect(rb.ref).toBe(ra.ref);

		// Ground truth is the DB, not either response: one claim, one line.
		const claimId = ra.url.split('/claims/')[1];
		const claim = await api(page, 'GET', `/index.php/apps/expensecheck/api/claims/${claimId}`);
		const c = claim.json?.claim || claim.json?.data || claim.json;
		expect((c.lines || []).length).toBe(1);
	});

	test('stale mapping hard-fails submit — never a silent ledger credit (INT-05)', async ({ page }) => {
		// Claim routing stored but mapping points nowhere: the settings GET
		// reports the degraded effective routing, yet the WRITE path must
		// refuse — a silent ledger row would lie about where the money went.
		const saved = await api(page, 'POST', '/index.php/apps/snackcheck/api/admin/settings', {
			expenseRouting: 'expense_claim',
			expenseClaimCategoryId: '0',
			expenseClaimCostCenterId: '0',
		});
		expect(saved.ok).toBeTruthy();
		const effective = saved.json?.data?.expenseRouting || saved.json?.expenseRouting;
		expect(effective).toBe('ledger_credit');

		const submit = await api(page, 'POST', '/index.php/apps/snackcheck/api/expenses', {
			siteId: '1',
			amountCents: '200',
			expenseReason: 'E2E degraded route',
			idempotencyKey: `e2e-deg-${Date.now()}`,
		});
		expect(submit.status).toBe(409);
		const err = submit.json?.error || submit.json;
		expect(err.code || err.error).toBe('expense_route_unavailable');
	});
});
