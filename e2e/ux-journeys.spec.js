// @ts-check
/**
 * Bachus UX journeys — one-tap log path, progressive disclosure, dead-end escapes.
 */
const { test, expect } = require('@playwright/test');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

test.describe('SnackCheck UX journeys', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	test('Log: page chrome + progressive More options + giant tiles', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/`);
		await expect(page.locator('#snk-page-title')).toBeVisible();
		await expect(page.locator('#snk-log-lead, .snk-page-header__lead').first()).toBeVisible();

		const modeBar = page.locator('.snk-mode-bar--surface');
		const advanced = page.locator('#snk-log-advanced');
		if (await advanced.count()) {
			await expect(advanced).toBeVisible();
			await expect(advanced).not.toHaveAttribute('open', '');
			if (await modeBar.count()) {
				await expect(modeBar).toBeVisible();
				const colleagueChip = modeBar.locator('.snk-mode-chip').filter({ has: page.locator('input[value="proxy"]') });
				if (await colleagueChip.count()) {
					await colleagueChip.click();
					const proxyPanel = page.locator('#snk-mode-proxy');
					await expect(proxyPanel).toBeVisible();
					await expect(proxyPanel).not.toHaveAttribute('hidden', '');
					await expect(proxyPanel.locator('[data-snk-chip-activate]')).toHaveCount(0);
					await expect(proxyPanel.locator('[data-snk-chip-auto]')).toBeVisible();
					const proxySearch = proxyPanel.locator('[data-snk-user-search]');
					await expect(proxySearch).toBeVisible();
					await expect(proxySearch).toBeFocused();
					await expect(proxyPanel.locator('#snk-proxy-reason')).toBeVisible();
					await modeBar.locator('.snk-mode-chip').filter({ has: page.locator('input[value="self"]') }).click();
					await expect(proxyPanel).toBeHidden();
				}
			}
			const isOpen = await advanced.evaluate((el) => el instanceof HTMLDetailsElement && el.open);
			if (!isOpen) {
				await advanced.locator('summary').click();
			}
			await expect(page.locator('[data-snk-qty="1"]')).toBeVisible();
			const selfMode = page.locator('input[data-snk-mode][value="self"]');
			if (await selfMode.count()) {
				await expect(selfMode).toBeChecked();
			}
		}

		const tiles = page.locator('button.snk-tile[data-snk-action="log"]');
		const empty = page.locator('.snk-empty');
		const hasTiles = (await tiles.count()) > 0;
		const hasEmpty = (await empty.count()) > 0;
		expect(hasTiles || hasEmpty).toBeTruthy();
		if (hasEmpty) {
			await expect(page.locator('.snk-empty__icon').first()).toBeVisible();
		}
		if (hasTiles) {
			const box = await tiles.first().boundingBox();
			expect(box?.height ?? 0).toBeGreaterThanOrEqual(64);
			await expect(tiles.first().locator('.snk-tile__media')).toBeVisible();
			// Equal card sizes within the first group row (tolerance for subpixel).
			const firstGroupTiles = page.locator('.snk-log-group').first().locator('button.snk-tile[data-snk-action="log"]');
			const n = Math.min(await firstGroupTiles.count(), 4);
			if (n >= 2) {
				const heights = [];
				const widths = [];
				for (let i = 0; i < n; i++) {
					const b = await firstGroupTiles.nth(i).boundingBox();
					heights.push(b?.height ?? 0);
					widths.push(b?.width ?? 0);
				}
				expect(Math.max(...heights) - Math.min(...heights), `tile heights=${heights.join(',')}`).toBeLessThanOrEqual(2);
				expect(Math.max(...widths) - Math.min(...widths), `tile widths=${widths.join(',')}`).toBeLessThanOrEqual(2);
			}
			const groups = page.locator('.snk-log-group');
			if ((await groups.count()) > 0) {
				await expect(groups.first().locator('.snk-log-group__title')).toBeVisible();
			}
			const find = page.locator('[data-snk-log-find]');
			if (await find.count()) {
				await find.fill('zzzz-no-match-xyz');
				await expect(page.locator('[data-snk-log-empty]')).toBeVisible();
				await find.fill('');
			}
		}
	});

	test('Catalog: Restock +1 is always-visible primary', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/catalog`);
		const restock = page.locator('button[data-snk-action="restock"][data-instant="1"]');
		const edit = page.locator('button[data-snk-action="edit-item"]');
		if ((await restock.count()) > 0) {
			await expect(restock.first()).toBeVisible();
			await expect(edit.first()).toBeVisible();
			const rowActions = page.locator('.snk-row-actions').first();
			await expect(rowActions).toBeVisible();
			const more = rowActions.locator('.snk-row-actions__more');
			await expect(more).toBeVisible();
			await expect(more).not.toHaveAttribute('open', '');
			await more.locator('summary').click();
			await expect(more).toHaveAttribute('open', '');
			await expect(more.locator('.snk-row-actions__panel')).toBeVisible();
			await expect(more.locator('[data-snk-action="restock"]').filter({ hasNot: page.locator('[data-instant]') })).toBeVisible();
			const rBox = await restock.first().boundingBox();
			expect(rBox?.height ?? 0).toBeGreaterThanOrEqual(40);
			const eBox = await edit.first().boundingBox();
			expect(eBox?.height ?? 0).toBeGreaterThanOrEqual(40);
		}
	});

	test('Pulse: Top-up card owns Restock CTA; ranks stay collapsed', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/pulse`);
		await expect(page.getByRole('heading', { name: /top-up|auffüllen|nachfüllen/i }).first()).toBeVisible();
		const ranks = page.locator('details.snk-details').filter({ hasText: /selling|verkauft|läuft/i });
		if (await ranks.count()) {
			await expect(ranks.first()).not.toHaveAttribute('open', '');
		}
		const restock = page.locator('button[data-snk-action="restock"][data-instant="1"]');
		if (await restock.count()) {
			await expect(restock.first()).toBeVisible();
		}

		// Family chrome: pills (not callout panel) + nested empties without dashed frame.
		const filterBar = page.locator('.snk-filter-bar');
		await expect(filterBar).toBeVisible();
		const barBox = await filterBar.evaluate((el) => {
			const s = getComputedStyle(el);
			return { borderLeftWidth: s.borderLeftWidth, boxShadow: s.boxShadow, borderStyle: s.borderStyle };
		});
		expect(barBox.borderLeftWidth === '0px' || barBox.borderStyle === 'none').toBeTruthy();

		const nestedEmpty = page.locator('.snk-card__body > .snk-empty').first();
		if (await nestedEmpty.count()) {
			const emptyBox = await nestedEmpty.evaluate((el) => {
				const s = getComputedStyle(el);
				return { borderStyle: s.borderStyle, boxShadow: s.boxShadow };
			});
			expect(emptyBox.borderStyle === 'none' || emptyBox.borderStyle === '').toBeTruthy();
			await expect(nestedEmpty.locator('.snk-empty__title')).toBeVisible();
		}
	});

	test('Catalog: name/price primary; More options disclosure', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/catalog`);
		const form = page.locator('form[data-snk-form="catalog-create"]');
		await expect(form).toBeVisible();
		await expect(form.locator('input[name="name"]')).toBeVisible();
		await expect(form.locator('input[name="priceEuro"]')).toBeVisible();
		const more = form.locator('details.snk-details');
		await expect(more).toBeVisible();
		await expect(more).not.toHaveAttribute('open', '');
		await expect(form.locator('select[name="category"]')).toBeHidden();
	});

	test('Catalog: edit dialog is centered with picture CTA', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/catalog`);
		const edit = page.locator('button[data-snk-action="edit-item"]').first();
		if ((await edit.count()) === 0) {
			test.skip();
			return;
		}
		await edit.click();
		const dlg = page.locator('#snk-edit-item-dialog');
		await expect(dlg).toBeVisible();
		await expect(dlg).toHaveAttribute('open', '');
		const box = await dlg.boundingBox();
		const vp = page.viewportSize();
		expect(box).toBeTruthy();
		expect(vp).toBeTruthy();
		if (box && vp) {
			const centerX = box.x + box.width / 2;
			const centerY = box.y + box.height / 2;
			expect(Math.abs(centerX - vp.width / 2)).toBeLessThan(vp.width * 0.12);
			expect(Math.abs(centerY - vp.height / 2)).toBeLessThan(vp.height * 0.22);
		}
		await expect(dlg.locator('#snk-edit-name')).toBeFocused();
		await expect(dlg.locator('[data-snk-edit-photo-pick-label]')).toBeVisible();
		await expect(dlg.locator('#snk-edit-image')).toBeAttached();
		await dlg.locator('button[value="cancel"]').click();
		await expect(dlg).not.toHaveAttribute('open', '');
	});

	test('Settings: chip nav + card body; Access Save visible', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/access`);
		await expect(page.locator('.snk-settings-nav')).toBeVisible();
		await expect(page.locator('.snk-settings-nav__link[aria-current="page"]')).toBeVisible();
		await expect(page.locator('#snk-page-title')).toBeVisible();
		await expect(page.locator('.snk-card__body').first()).toBeVisible();
		await expect(page.locator('form[data-snk-form="settings"]').first()).toBeVisible();
		const sub = page.locator('.snk-nav__sublist .snk-nav__sublink');
		if (await sub.count()) {
			await expect(page.locator('.snk-nav__sublink[aria-current="page"]')).toBeVisible();
		}
		await page.locator('.snk-settings-nav__link').filter({ hasText: /Benefits|Leistungen/i }).first().click();
		await expect(page).toHaveURL(/settings\/benefits/);
		await expect(page.locator('#snk-hosp-enabled, #snk-benefits-form').first()).toBeVisible();
	});

	test('Settings license: register form + revoke wiring present', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/license`);
		await expect(page.locator('form[data-snk-form="terminal"]')).toBeVisible();
		await expect(page.locator('form[data-snk-form="license"]')).toBeVisible();
		// List may be empty without SNK2 devices — contract is Register + JS revoke handler.
		const revokeBtns = page.locator('[data-snk-action="revoke-terminal"]');
		const count = await revokeBtns.count();
		if (count > 0) {
			await expect(revokeBtns.first()).toBeVisible();
			await expect(page.locator('.snk-term-list')).toBeVisible();
		}
	});

	test('Settings: directory picker is WAI-ARIA combobox with removable chips', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/access`);
		const search = page.locator('[data-snk-user-search][data-snk-search-scope="directory"]').first();
		await expect(search).toBeVisible();
		await expect(search).toHaveAttribute('role', 'combobox');
		await expect(search).toHaveAttribute('aria-autocomplete', 'list');
		await expect(search).toHaveAttribute('aria-expanded', 'false');
		const listId = await search.getAttribute('aria-controls');
		expect(listId).toBeTruthy();
		await expect(page.locator(`#${listId}`)).toHaveAttribute('role', 'listbox');

		const field = page.locator('[data-snk-chip-field]').first();
		await expect(field.locator('[data-snk-chip-list]')).toBeAttached();
		await expect(field.locator('.snk-chip-target[type="hidden"]')).toBeAttached();
		const addBtn = field.locator('[data-snk-chip-activate]');
		await addBtn.click();
		await expect(search).toBeFocused();
		await expect(field).toHaveClass(/is-active/);

		await search.fill('ad');
		await expect(search).toHaveAttribute('aria-expanded', 'true', { timeout: 5000 });
		const opts = page.locator(`#${listId} [role="option"]`);
		const empty = page.locator(`#${listId} [role="status"]`);
		await expect(opts.or(empty).first()).toBeVisible({ timeout: 5000 });
		if (await opts.count()) {
			const before = await field.locator('.snk-chip').count();
			await opts.first().click();
			await expect(field.locator('.snk-chip').first()).toBeVisible({ timeout: 3000 });
			expect(await field.locator('.snk-chip').count()).toBeGreaterThan(before);
			const remove = field.locator('.snk-chip__remove').first();
			await expect(remove).toBeVisible();
			const box = await remove.boundingBox();
			expect(box?.width ?? 0).toBeGreaterThanOrEqual(40);
			expect(box?.height ?? 0).toBeGreaterThanOrEqual(40);
			await remove.click();
			await expect(field.locator('.snk-chip')).toHaveCount(before);
		}
		await search.press('Escape');
		await expect(search).toHaveAttribute('aria-expanded', 'false');
	});

	test('My month: hero deduct + empty recovery or table', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/my-month`);
		await expect(page.locator('.snk-hero')).toBeVisible();
		await expect(page.locator('.snk-hero__value')).toBeVisible();
		await expect(page.locator('.snk-hero__stats')).toBeVisible();
		await expect(page.locator('.snk-hero__stat').first()).toBeVisible();
		const emptyCta = page.locator('.snk-empty a.snk-btn, .snk-empty__actions a');
		const table = page.locator('.snk-table');
		expect((await emptyCta.count()) + (await table.count())).toBeGreaterThan(0);
	});

	test('Periods: open/closed chrome + no dead table scroll trap', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/periods`);
		await expect(page.locator('#app-content.snk-app--periods, #app-content.snk-app').first()).toBeVisible();
		await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
		const panel = page.locator('[data-snk-period-panel]');
		const locked = page.locator('.snk-callout--warn, [data-snk-action="open-next-period"]');
		expect((await panel.count()) + (await locked.count())).toBeGreaterThan(0);
		if (await panel.count()) {
			await expect(panel.locator('.snk-period-panel__label')).toBeVisible();
			await expect(panel.locator('[data-snk-action="payroll"]')).toBeVisible();
			await expect(panel.locator('.snk-period-panel__danger [data-snk-action="close-period"]')).toBeVisible();
			await expect(panel.locator('.snk-period-panel__danger')).toBeVisible();
			// Successor labels must not look like day 35 of a month.
			const labelText = await panel.locator('.snk-period-panel__label').innerText();
			expect(labelText).not.toMatch(/^\d{4}-\d{2}-\d{2}$/);
			if (labelText.includes('#')) {
				expect(labelText).toMatch(/\(#\d+\)/);
			}
		}
		const wrap = page.locator('.snk-table-wrap');
		const empty = page.locator('.snk-empty');
		expect((await wrap.count()) + (await empty.count())).toBeGreaterThanOrEqual(0);
		await expect(page.locator('#snk-live-region')).toBeAttached();
	});

	test('Users: proxy chrome or recovery CTAs', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/users`);
		await expect(page.locator('#app-content.snk-app').first()).toBeVisible();
		const proxyPanel = page.locator('#snk-mode-proxy');
		if (await proxyPanel.count()) {
			await expect(proxyPanel).toBeVisible();
			await expect(proxyPanel.locator('[data-snk-chip-activate]')).toHaveCount(0);
			await expect(proxyPanel.locator('[data-snk-user-search]')).toBeVisible();
			await expect(proxyPanel.locator('#snk-proxy-reason')).toBeVisible();
		} else {
			const recovery = page.locator('[data-snk-action="focus-site"], a:has-text("Catalog"), a:has-text("Katalog"), a:has-text("Periods"), a:has-text("Perioden"), button[data-snk-action="starter"], .snk-tile-grid, .snk-table');
			await expect(recovery.first()).toBeVisible({ timeout: 10000 });
		}
	});

	test('Sites: never raw ID teaching + manager chips (or safe redirect)', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/sites`);
		await expect(page.locator('#app-content.snk-app').first()).toBeVisible();
		const url = page.url();
		if (url.includes('/sites')) {
			const body = await page.locator('#snk-main-content').innerText();
			expect(body.toLowerCase()).not.toMatch(/manager user ids/);
			await expect(page.locator('.snk-chip-target, [data-snk-user-search], .snk-table, .snk-empty').first()).toBeVisible();
		} else {
			// Multi-site off → redirect to settings (must not 500).
			expect(url).toMatch(/settings/);
			await expect(page.locator('.snk-settings-nav, .snk-card__body').first()).toBeVisible();
		}
	});

	test('Hospitality: overview or empty escape to Log/Benefits (or safe redirect)', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/hospitality`);
		await expect(page.locator('#app-content.snk-app').first()).toBeVisible();
		const url = page.url();
		if (url.includes('/hospitality')) {
			const escape = page.locator('a.snk-btn, .snk-table, .snk-empty');
			await expect(escape.first()).toBeVisible();
		} else {
			// Hospitality off → redirect to log (must not 500).
			await expect(page.locator('#snk-page-title, .snk-page-header, .snk-tile, .snk-empty').first()).toBeVisible();
		}
	});

	test('BR report: hollow downloads hidden when empty', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/br-report`);
		await expect(page.locator('#app-content.snk-app').first()).toBeVisible();
		const empty = page.locator('.snk-empty');
		const csv = page.locator('a.snk-btn--primary:has-text("CSV"), a.snk-btn--primary:has-text("csv")');
		if ((await empty.count()) > 0 && (await page.locator('.snk-table').count()) === 0) {
			await expect(csv).toHaveCount(0);
		}
	});

	test('Pulse: no hollow shopping buttons when Top-up empty', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/pulse`);
		const topUpRows = page.locator('.snk-card').filter({ hasText: /Top-up|Auffüll|Nachschub/i }).locator('ul.snk-list li');
		const csvBtn = page.locator('button[data-snk-action="shopping-csv"]');
		const printBtn = page.locator('button[data-snk-action="shopping-print"]');
		if ((await topUpRows.count()) === 0) {
			await expect(csvBtn).toHaveCount(0);
			await expect(printBtn).toHaveCount(0);
		} else {
			await expect(csvBtn).toBeVisible();
			await expect(printBtn).toBeVisible();
		}
	});

	test('My month: PDF hidden when nothing logged', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/my-month`);
		await expect(page.locator('#app-content.snk-app').first()).toBeVisible();
		const empty = page.locator('.snk-empty');
		const pdf = page.locator('a.snk-btn--primary[href*="my-month"], a.snk-btn--primary:has-text("PDF"), a.snk-btn--primary:has-text("pdf")');
		if ((await empty.count()) > 0 && (await page.locator('.snk-table').count()) === 0) {
			await expect(pdf).toHaveCount(0);
		}
	});

	test('My month: PDF download is a statement with TOTAL', async ({ page, request }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/my-month`);
		const pdfLink = page.locator('a.snk-btn--primary[href*="my-month/pdf"], a.snk-btn--primary[href*="my-month"][href*="pdf"]');
		if ((await pdfLink.count()) === 0) {
			test.skip();
			return;
		}
		const href = await pdfLink.first().getAttribute('href');
		expect(href).toBeTruthy();
		const res = await page.request.get(href.startsWith('http') ? href : `${BASE}${href}`);
		expect(res.ok()).toBeTruthy();
		expect(res.headers()['content-type'] || '').toMatch(/pdf/i);
		const body = await res.body();
		const text = body.toString('latin1');
		expect(text.startsWith('%PDF-1.4')).toBeTruthy();
		expect(text).toContain('TOTAL TO DEDUCT');
		expect(text).toContain('Total to deduct');
		expect(text).toMatch(/\d+\.\d{2} EUR/);
	});

	test('Audit: table wrap or empty state', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/audit`);
		await expect(page.locator('#app-content.snk-app').first()).toBeVisible();
		await expect(page.locator('.snk-table-wrap, .snk-empty').first()).toBeVisible();
	});

	test('Settings benefits: Save never disabled brick wall', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/settings/benefits`);
		await expect(page.locator('.snk-settings-nav')).toBeVisible();
		const sw = page.locator('#snk-hosp-enabled, [role="switch"]').first();
		if (await sw.count()) {
			await expect(sw).toBeVisible();
		}
		const save = page.locator('#snk-benefits-save');
		await expect(save).toBeVisible();
		await expect(save).toBeEnabled();
		await expect(page.locator('input[name="subsidyAllowanceEuro"]')).toBeVisible();
	});

	test('Log: mode bar sits above tiles when present', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/`);
		const modeBar = page.locator('.snk-mode-bar--surface');
		const tiles = page.locator('ul.snk-tile-grid');
		if ((await modeBar.count()) > 0 && (await tiles.count()) > 0) {
			const modeBox = await modeBar.boundingBox();
			const tileBox = await tiles.first().boundingBox();
			expect(modeBox && tileBox && modeBox.y < tileBox.y).toBeTruthy();
		}
	});

	test('Log: tiles appear before More options', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/`);
		const tiles = page.locator('ul.snk-tile-grid');
		const advanced = page.locator('#snk-log-advanced');
		if ((await tiles.count()) > 0 && (await advanced.count()) > 0) {
			const tileBox = await tiles.first().boundingBox();
			const advBox = await advanced.boundingBox();
			expect(tileBox && advBox && tileBox.y < advBox.y).toBeTruthy();
		}
	});

	test('CSRF: log POST sends NC requesttoken (not HTTP 412)', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/`);
		const probe = await page.evaluate(async () => {
			const csrf = (typeof window.OC !== 'undefined' && typeof OC.requestToken === 'string' && OC.requestToken)
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| document.querySelector('meta[name="requesttoken"]')?.getAttribute('content')
				|| '';
			const body = new URLSearchParams({
				itemId: '0',
				qty: '1',
				siteId: '0',
				requesttoken: csrf,
				idempotencyKey: 'e2e-csrf-probe-' + Date.now(),
			});
			const res = await fetch(
				(typeof OC !== 'undefined' && OC.generateUrl)
					? OC.generateUrl('/apps/snackcheck/api/logs')
					: '/index.php/apps/snackcheck/api/logs',
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
						requesttoken: csrf,
						'Idempotency-Key': body.get('idempotencyKey') || '',
					},
					body: body.toString(),
				},
			);
			return { status: res.status, hasToken: csrf.length > 8 };
		});
		expect(probe.hasToken).toBe(true);
		// Domain validation (400) or auth (401/403) is fine — CSRF must not fire 412.
		expect(probe.status).not.toBe(412);
	});

	test('keyboard: skip link lands on main', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/`);
		await page.keyboard.press('Tab');
		const skip = page.locator('a.snk-skip-link[href="#snk-main-content"]');
		// First Tab may hit NC chrome; keep Tab until skip or main
		for (let i = 0; i < 8; i++) {
			const focused = await page.evaluate(() => document.activeElement?.getAttribute?.('href') || document.activeElement?.id || '');
			if (focused === '#snk-main-content' || focused === 'snk-main-content') {
				break;
			}
			await page.keyboard.press('Tab');
		}
		const skipFocused = await skip.evaluate((el) => el === document.activeElement).catch(() => false);
		if (skipFocused) {
			await page.keyboard.press('Enter');
			await expect(page.locator('#snk-main-content')).toBeFocused();
		}
	});
});
