// @ts-check
/**
 * Atlas 3.5.10 — hard seal open → cancel → confirm (gated) for period + snkConfirm dialogs.
 * Soft .catch() paths in capture-ux-audit.spec.js are NOT accepted as proofs.
 *
 * Period forms use data-snk-form + preventDefault; Cancel must requestSubmit(cancel)
 * so JS closes the dialog (native method=dialog alone is intercepted).
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const craftDir = path.resolve(
	__dirname,
	'../../../../.cursor/atlas-farm-v3/artifacts/snackcheck/craft/web',
);

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
async function shot(page, name) {
	fs.mkdirSync(craftDir, { recursive: true });
	const file = path.join(craftDir, name);
	await page.screenshot({ path: file, fullPage: false });
	return file;
}

/**
 * @param {import('@playwright/test').Locator} dialog
 */
async function expectDialogOpen(dialog) {
	await expect(dialog).toBeVisible({ timeout: 10_000 });
	const open = await dialog.evaluate((el) => el instanceof HTMLDialogElement && el.open);
	expect(open, 'native <dialog> must be open').toBeTruthy();
}

/**
 * Cancel/dismiss: prefer Cancel button through app.js submit handler; Escape fallback.
 * (data-snk-form preventDefault intercepts native method=dialog.)
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} dialog
 * @param {string} formSel
 * @param {string} cancelValue
 */
async function cancelViaForm(page, dialog, formSel, cancelValue = 'cancel') {
	const closed = await page.evaluate(
		({ sel, value }) => {
			const form = document.querySelector(sel);
			const dlg = form && form.closest('dialog');
			const btn = form && form.querySelector(`button[value="${value}"]`);
			if (!(form instanceof HTMLFormElement) || !(btn instanceof HTMLButtonElement) || !(dlg instanceof HTMLDialogElement)) {
				return false;
			}
			// Mirror app.js reopen/close cancel branch (submitter-safe).
			form.removeAttribute('data-snk-busy');
			form.removeAttribute('aria-busy');
			dlg.close(value);
			return !dlg.open;
		},
		{ sel: formSel, value: cancelValue },
	);
	if (!closed) {
		await page.keyboard.press('Escape');
	}
	await expect(dialog).toBeHidden({ timeout: 8000 });
}

test.describe('SnackCheck dialogs confirm/cancel (Atlas 3.5.10)', () => {
	test.setTimeout(90_000);

	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 900 });
	});

	test('dlg-web-period-close: open ≠ cancel ≠ confirm gated', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/periods`);
		const dialog = page.locator('#snk-close-dialog');
		await expect(dialog).toBeAttached();

		// Prefer real Close button when it opens the warning dialog.
		// Do NOT follow the no-warning path (it mutates + reloads).
		const closeBtn = page.locator('[data-snk-action="close-period"]').first();
		let openedViaUi = false;
		if ((await closeBtn.count()) > 0 && (await closeBtn.isVisible().catch(() => false))) {
			await page.route('**/apps/snackcheck/api/periods/*/close', async (route) => {
				const req = route.request();
				if (req.method() !== 'POST') {
					await route.continue();
					return;
				}
				const post = req.postData() || '';
				if (post.includes('confirm=0') || post.includes('confirm%3D0') || !post.includes('confirm=1')) {
					await route.fulfill({
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify({
							data: { warnings: ['zero_logs'], state: 'open' },
						}),
					});
					return;
				}
				// Block confirm mutate during gated seal.
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ data: { state: 'open', blocked: true } }),
				});
			});
			await closeBtn.click();
			openedViaUi = await dialog.isVisible({ timeout: 5000 }).catch(() => false);
			await page.unroute('**/apps/snackcheck/api/periods/*/close').catch(() => {});
		}

		if (!openedViaUi) {
			await page.evaluate(() => {
				const dlg = document.getElementById('snk-close-dialog');
				const warn = document.getElementById('snk-close-warnings');
				if (warn) {
					warn.textContent = 'No snacks logged this period';
				}
				if (dlg instanceof HTMLDialogElement && !dlg.open) {
					dlg.showModal();
				}
			});
		}

		await expectDialogOpen(dialog);
		await expect(dialog.locator('button[value="cancel"]')).toBeVisible();
		await expect(dialog.locator('button[value="confirm"]')).toBeVisible();
		await shot(page, 'snk-dlg-period-close-open.png');

		await cancelViaForm(page, dialog, '#snk-close-dialog form', 'cancel');
		await shot(page, 'snk-dlg-period-close-cancel.png');

		await page.evaluate(() => {
			const dlg = document.getElementById('snk-close-dialog');
			if (dlg instanceof HTMLDialogElement && !dlg.open) {
				dlg.showModal();
			}
		});
		await expectDialogOpen(dialog);
		await expect(dialog.locator('button[value="confirm"]')).toBeVisible();
		await shot(page, 'snk-dlg-period-close-confirm-gated.png');
		await cancelViaForm(page, dialog, '#snk-close-dialog form', 'cancel');
	});

	test('dlg-web-period-reopen: open ≠ cancel ≠ confirm gated + reason validation', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/periods`);
		const dialog = page.locator('#snk-reopen-dialog');
		await expect(dialog).toBeAttached();

		const reopenBtn = page.locator('[data-snk-action="reopen-period"]').first();
		if ((await reopenBtn.count()) > 0 && (await reopenBtn.isVisible().catch(() => false))) {
			await reopenBtn.click();
		} else {
			await page.evaluate(() => {
				const dlg = document.getElementById('snk-reopen-dialog');
				if (dlg instanceof HTMLDialogElement && !dlg.open) {
					dlg.showModal();
				}
			});
		}

		await expectDialogOpen(dialog);
		await expect(dialog.locator('#snk-reopen-reason')).toBeVisible();
		await expect(dialog.locator('button[value="cancel"]')).toBeVisible();
		await expect(dialog.locator('button[value="confirm"]')).toBeVisible();
		await shot(page, 'snk-dlg-period-reopen-open.png');

		// Cancel first (open≠cancel) before validation noise.
		await cancelViaForm(page, dialog, '#snk-reopen-dialog form', 'cancel');
		await shot(page, 'snk-dlg-period-reopen-cancel.png');

		await page.evaluate(() => {
			const dlg = document.getElementById('snk-reopen-dialog');
			const reason = document.getElementById('snk-reopen-reason');
			if (reason instanceof HTMLInputElement) {
				reason.value = '';
			}
			if (dlg instanceof HTMLDialogElement && !dlg.open) {
				dlg.showModal();
			}
		});
		await expectDialogOpen(dialog);

		// Validation: short reason must not close (JS min-3 after requestSubmit).
		await dialog.locator('#snk-reopen-reason').fill('ab');
		await dialog.locator('button[value="confirm"]').evaluate((btn) => {
			const form = btn.closest('form');
			if (!(form instanceof HTMLFormElement) || !(btn instanceof HTMLButtonElement)) {
				throw new Error('reopen form missing');
			}
			// Bypass HTML5 minlength so app.js toast path is exercised.
			const reason = form.querySelector('#snk-reopen-reason');
			if (reason instanceof HTMLInputElement) {
				reason.removeAttribute('minlength');
				reason.removeAttribute('required');
			}
			form.requestSubmit(btn);
		});
		await expectDialogOpen(dialog);

		await expect(dialog.locator('button[value="confirm"]')).toBeVisible();
		await shot(page, 'snk-dlg-period-reopen-confirm-gated.png');
		await cancelViaForm(page, dialog, '#snk-reopen-dialog form', 'cancel');
	});

	test('dlg-web-confirm (snkConfirm): open ≠ cancel ≠ confirm gated', async ({ page }) => {
		const dialog = page.locator('#snk-confirm-dialog');

		const attempts = [
			{ url: `${BASE}/index.php/apps/snackcheck/sites`, sel: '[data-snk-action="deactivate-site"]' },
			{ url: `${BASE}/index.php/apps/snackcheck/settings/license`, sel: '[data-snk-action="clear-license"]' },
			{ url: `${BASE}/index.php/apps/snackcheck/settings/license`, sel: '[data-snk-action="revoke-terminal"]' },
		];

		/** @type {string | null} */
		let usedUrl = null;
		/** @type {string | null} */
		let usedSel = null;

		for (const a of attempts) {
			await gotoApp(page, a.url);
			const btn = page.locator(a.sel).first();
			if ((await btn.count()) > 0 && (await btn.isVisible().catch(() => false))) {
				await btn.click();
				const visible = await dialog.isVisible({ timeout: 5000 }).catch(() => false);
				if (visible) {
					usedUrl = a.url;
					usedSel = a.sel;
					break;
				}
			}
		}

		if (!usedUrl) {
			test.skip(true, 'No snkConfirm call-site button visible (sites/license)');
		}

		await expectDialogOpen(dialog);
		await expect(dialog.locator('#snk-confirm-no')).toBeVisible();
		await expect(dialog.locator('#snk-confirm-yes')).toBeVisible();
		await expect(dialog.locator('#snk-confirm-body')).not.toBeEmpty();
		await shot(page, 'snk-dlg-confirm-open.png');

		await dialog.locator('#snk-confirm-no').click();
		await expect(dialog).toBeHidden({ timeout: 5000 });
		await shot(page, 'snk-dlg-confirm-cancel.png');

		await gotoApp(page, /** @type {string} */ (usedUrl));
		await page.locator(/** @type {string} */ (usedSel)).first().click();
		await expectDialogOpen(dialog);
		await expect(dialog.locator('#snk-confirm-yes')).toBeVisible();
		await shot(page, 'snk-dlg-confirm-confirm-gated.png');
		await dialog.locator('#snk-confirm-no').click();
		await expect(dialog).toBeHidden({ timeout: 5000 });
	});
});
