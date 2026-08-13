// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

function loadDotEnv(filePath) {
	if (!fs.existsSync(filePath)) {
		return;
	}
	for (const line of fs.readFileSync(filePath, 'utf8').split('\n')) {
		const trimmed = line.trim();
		if (trimmed === '' || trimmed.startsWith('#')) {
			continue;
		}
		const eq = trimmed.indexOf('=');
		if (eq === -1) {
			continue;
		}
		const key = trimmed.slice(0, eq).trim();
		let value = trimmed.slice(eq + 1).trim();
		if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
			value = value.slice(1, -1);
		}
		if (process.env[key] === undefined) {
			process.env[key] = value;
		}
	}
}

loadDotEnv(path.join(__dirname, 'e2e', '.env'));

const baseURL = (process.env.E2E_BASE || process.env.BASE_URL || 'http://localhost:8081').replace(/\/$/, '');
const storageState = process.env.E2E_STORAGE_STATE
	? path.resolve(process.env.E2E_STORAGE_STATE)
	: path.join(__dirname, '.auth', 'storage-state.json');

module.exports = defineConfig({
	testDir: './e2e',
	globalSetup: './e2e/global-setup.js',
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [['list']],
	use: {
		baseURL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		...devices['Desktop Chrome'],
		...(process.env.E2E_USER && (process.env.E2E_PASS || process.env.E2E_PASSWORD)
			? { storageState }
			: {}),
	},
});
