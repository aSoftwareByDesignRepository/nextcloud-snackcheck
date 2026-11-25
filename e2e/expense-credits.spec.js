// @ts-check
/**
 * Expense credits — the /log entry point, dialog lifecycle, submit + undo toast,
 * and the self-entry cap rejection. Requires occ: expense_entries_enabled=1,
 * expense_self_entry=1, expense_self_entry_max_cents=5000 (see e2e/.env note).
 */
const { test, expect } = require('@playwright/test');
const { gotoApp } = require('./helpers/auth-guard');

const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

/**
 * The expense entry lives under the progressive-disclosure "More options"
 * details — open it before touching the opener.
 */
async function openExpenseDialog(page) {
	const advanced = page.locator('#snk-log-advanced');
	await expect(advanced).toBeVisible();
	// Clicking summary on an already-open details would close it again
	// (the boolean `open` attribute reads '' when present — check for null).
	if ((await advanced.getAttribute('open')) === null) {
		await advanced.locator('summary').click();
	}
	const opener = page.locator('[data-snk-action="open-expense-dialog"]');
	await expect(opener).toBeVisible();
	await opener.click();
	return page.locator('#snk-expense-dialog');
}

test.describe('SnackCheck expense credits', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!process.env.E2E_USER, 'Set E2E_USER + E2E_PASS in e2e/.env');
		await page.setViewportSize({ width: 1280, height: 800 });
	});

	test('Log: expense entry point opens the dialog with a minted idempotency key', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);

		const opener = page.locator('[data-snk-action="open-expense-dialog"]');
		const dialog = page.locator('#snk-expense-dialog');
		await expect(dialog).not.toBeVisible();
		await openExpenseDialog(page);
		await expect(opener).toBeVisible();
		await expect(opener).toBeEnabled();
		await expect(dialog).toBeVisible();

		await expect(dialog.locator('#snk-expense-title')).toBeVisible();
		await expect(dialog.locator('#snk-expense-amount')).toBeVisible();
		await expect(dialog.locator('#snk-expense-reason')).toBeVisible();
		// A fresh replay-safe key is minted on every open (NN-15 web).
		await expect(dialog.locator('[data-snk-expense-key]')).not.toHaveValue('');

		// Escape must close the dialog cleanly — no dead ends.
		await page.keyboard.press('Escape');
		await expect(dialog).not.toBeVisible();
	});

	test('Log: a fresh idempotency key is minted per dialog open', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
		const dialog = page.locator('#snk-expense-dialog');
		const key = dialog.locator('[data-snk-expense-key]');

		await openExpenseDialog(page);
		const first = await key.inputValue();
		await page.keyboard.press('Escape');
		await openExpenseDialog(page);
		const second = await key.inputValue();

		expect(first).toBeTruthy();
		expect(second).toBeTruthy();
		expect(second).not.toBe(first);
	});

	test('Log: submitting an expense records it and offers undo', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
		const dialog = await openExpenseDialog(page);
		const amount = `1,${String(Math.floor(Math.random() * 90) + 10)}`;
		const reason = `E2E milk ${Date.now() % 100000}`;
		await dialog.locator('#snk-expense-amount').fill(amount);
		await dialog.locator('#snk-expense-reason').fill(reason);
		await dialog.locator('button[type="submit"][value="confirm"]').click();

		await expect(dialog).not.toBeVisible({ timeout: 15000 });
		// Undo-capable success toast (60 s self-undo window).
		await expect(page.locator('.snk-toast, [role="status"]').first()).toBeVisible({ timeout: 15000 });
	});

	test('Log: over-limit self expense is rejected with an error toast', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
		const advanced = page.locator('#snk-log-advanced');
		await advanced.locator('summary').click();
		const opener = page.locator('[data-snk-action="open-expense-dialog"]');
		if (!(await opener.count())) {
			test.skip(true, 'Expense entry not available for this user/site');
		}
		await opener.click();

		const dialog = page.locator('#snk-expense-dialog');
		// 60 EUR is above the 50 EUR self-entry cap configured for e2e.
		await dialog.locator('#snk-expense-amount').fill('60');
		await dialog.locator('#snk-expense-reason').fill('Over limit probe');
		await dialog.locator('button[type="submit"][value="confirm"]').click();

		// Rejected entries keep the dialog open for correction + error toast.
		await expect(
			page.locator('.snk-toast--error, [role="alert"]').first(),
		).toBeVisible({ timeout: 15000 });
		await expect(dialog).toBeVisible();
	});

	test('My month: recorded expense shows as a credit on the statement', async ({ page }) => {
		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/log?siteId=1`);
		const dialog = await openExpenseDialog(page);
		const amount = '2,50';
		await dialog.locator('#snk-expense-amount').fill(amount);
		await dialog.locator('#snk-expense-reason').fill(`E2E credit ${Date.now() % 100000}`);
		await dialog.locator('button[type="submit"][value="confirm"]').click();
		await expect(dialog).not.toBeVisible({ timeout: 15000 });

		await gotoApp(page, `${BASE}/index.php/apps/snackcheck/my-month`);
		await expect(
			page.locator('.snk-badge--credit').first(),
		).toBeVisible({ timeout: 15000 });
	});
});
