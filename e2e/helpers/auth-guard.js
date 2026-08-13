/**
 * E2E auth helpers for SnackCheck (mirrors ProjectCheck / TicketCheck).
 */
async function tryProgrammaticLogin(page) {
	const user = process.env.E2E_USER;
	const pass = process.env.E2E_PASS || process.env.E2E_PASSWORD;
	if (!user || !pass) {
		return false;
	}

	const loginHeading = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
	const onLogin = await loginHeading.isVisible({ timeout: 3000 }).catch(() => false);
	if (!onLogin) {
		return true;
	}

	const accountField = page.getByRole('textbox', { name: /account name|email|kontoname|e-mail/i }).first();
	const passwordField = page.getByRole('textbox', { name: /password|passwort/i });
	await accountField.fill(user);
	await passwordField.fill(pass);
	await page.getByRole('button', { name: /^log in$|^anmelden$/i }).click();
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 }).catch(() => {});

	const stillLogin = await loginHeading.isVisible({ timeout: 2000 }).catch(() => false);
	return !stillLogin;
}

async function ensureAuthenticated(page) {
	const loggedIn = await tryProgrammaticLogin(page);
	if (!loggedIn) {
		const loginHeading = page.getByRole('heading', { name: /log in to nextcloud|bei nextcloud anmelden/i });
		const onLogin = await loginHeading.isVisible({ timeout: 3000 }).catch(() => false);
		if (onLogin) {
			const { test } = require('@playwright/test');
			test.skip(true, 'Not authenticated. Set E2E_USER + E2E_PASS in e2e/.env');
		}
	}
}

/**
 * On very narrow viewports NC app navigation can cover content — dismiss when needed.
 * @param {import('@playwright/test').Page} page
 */
async function dismissOpenAppNavigation(page) {
	const narrow = (page.viewportSize()?.width ?? 1280) <= 480;
	if (!narrow) {
		return;
	}
	const appContent = page.locator('#app-content.snk-app').first();
	const present = await appContent.count().catch(() => 0);
	if (!present) {
		return;
	}
	const appBox = await appContent.boundingBox({ timeout: 3000 }).catch(() => null);
	if (appBox && appBox.width > 200) {
		return;
	}
	const toggle = page.locator('#app-navigation-toggle, .app-navigation-toggle').first();
	if (await toggle.count()) {
		await toggle.click({ force: true }).catch(() => {});
		await page.waitForTimeout(250);
	}
	await appContent
		.evaluate((el) => {
			if (el.getBoundingClientRect().width > 200) {
				return;
			}
			const nav = document.getElementById('app-navigation');
			if (nav) {
				nav.classList.add('hidden');
			}
			document.body.classList.remove('snapjs-left');
		})
		.catch(() => {});
}

async function gotoApp(page, url) {
	await page.goto(url, { waitUntil: 'domcontentloaded' });
	await ensureAuthenticated(page);
	await page.waitForSelector('#app-content.snk-app, #snk-main-content', { timeout: 30_000 });
	await dismissOpenAppNavigation(page);
}

module.exports = { ensureAuthenticated, tryProgrammaticLogin, gotoApp, dismissOpenAppNavigation };
