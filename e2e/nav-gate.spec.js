// @ts-check
/**
 * nav_entry_access_leak — in 'listed' access mode the apps menu / OCS
 * navigation endpoint must not advertise SnackCheck to non-listed users;
 * in 'open' mode every authenticated user sees exactly one entry.
 * Assertions hit the OCS endpoint — the same feed the web menu and the
 * NC mobile clients render.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const COMPOSE_DIR = path.resolve(__dirname, '../../..');
const BASE = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');

const occ = (/** @type {string[]} */ args, /** @type {string[]} */ envs = []) => execFileSync('docker', [
	'compose', 'exec', '-T', ...envs.flatMap((e) => ['-e', e]),
	'-u', 'www-data', 'nextcloud', 'php', 'occ', ...args,
], { cwd: COMPOSE_DIR, encoding: 'utf8' }).trim();

const LISTED = { username: 'snk_nav_listed', password: 'SnkNav-Listed-2026!' };
const OUTSIDER = { username: 'snk_nav_out', password: 'SnkNav-Out-2026!' };

async function navIds(/** @type {import('@playwright/test').APIRequestContext} */ request) {
	const res = await request.get(`${BASE}/ocs/v2.php/core/navigation/apps?format=json`, {
		headers: { 'OCS-APIRequest': 'true' },
	});
	expect(res.status()).toBe(200);
	return /** @type {Array<{ id: string, href: string }>} */ ((await res.json()).ocs.data);
}

async function authedRequest(
	/** @type {typeof import('playwright-core')} */ playwright,
	/** @type {{ username: string, password: string }} */ { username, password },
) {
	const request = await playwright.request.newContext({ baseURL: BASE });
	const loginPage = await request.get('/login');
	const token = ((await loginPage.text()).match(/data-requesttoken="([^"]+)"/) || [])[1];
	const res = await request.post('/login', {
		form: { user: username, password, requesttoken: decodeURIComponent(token) },
		headers: { Origin: BASE },
		maxRedirects: 0,
	});
	expect(res.status(), `login ${username}`).toBe(303);
	return request;
}

test.describe('nav gate', () => {
	test('listed mode hides the menu entry from non-listed users', async (/** @type {{ playwright: typeof import('playwright-core') }} */ { playwright }) => {
		const users = occ(['user:list']);
		for (const u of [LISTED, OUTSIDER]) {
			if (!users.includes(u.username)) {
				occ(['user:add', '--password-from-env', u.username], [`OC_PASS=${u.password}`]);
			}
		}
		const modeBefore = occ(['config:app:get', 'snackcheck', 'access_mode']) || 'open';
		let usersBefore = '';
		try { usersBefore = occ(['config:app:get', 'snackcheck', 'access_users_json']); } catch {}
		occ(['config:app:set', 'snackcheck', 'access_mode', '--value=listed']);
		occ(['config:app:set', 'snackcheck', 'access_users_json', `--value=${JSON.stringify([LISTED.username])}`]);
		try {
			const out = await authedRequest(playwright, OUTSIDER);
			// appconfig rides APCu per SAPI — poll the OCS feed until the
			// CLI write reaches FPM (never a fixed sleep).
			let ids = /** @type {string[]} */ ([]);
			for (let i = 0; i < 40; i++) {
				ids = (await navIds(out)).map((e) => e.id);
				if (!ids.includes('snackcheck')) break;
				await new Promise((r) => setTimeout(r, 250));
			}
			expect(ids).not.toContain('snackcheck');
			await out.dispose();

			const inReq = await authedRequest(playwright, LISTED);
			let entries = /** @type {Array<{ id: string, href: string }>} */ ([]);
			for (let i = 0; i < 40; i++) {
				entries = (await navIds(inReq)).filter((e) => e.id === 'snackcheck');
				if (entries.length === 1) break;
				await new Promise((r) => setTimeout(r, 250));
			}
			expect(entries).toHaveLength(1);
			expect(entries[0].href).toContain('/apps/snackcheck');
			await inReq.dispose();
		} finally {
			occ(['config:app:set', 'snackcheck', 'access_mode', `--value=${modeBefore}`]);
			if (usersBefore) {
				occ(['config:app:set', 'snackcheck', 'access_users_json', `--value=${usersBefore}`]);
			} else {
				occ(['config:app:delete', 'snackcheck', 'access_users_json']);
			}
		}
	});
});
