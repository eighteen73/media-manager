/**
 * Playwright configuration for Media Manager E2E tests.
 *
 * Targets the wp-env tests site (port 8889) and authenticates via
 * @wordpress/e2e-test-utils-playwright global setup.
 */

const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

process.env.WP_ARTIFACTS_PATH ??= path.join(process.cwd(), 'artifacts');
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

const baseUrl = new URL(process.env.WP_BASE_URL || 'http://localhost:8889');

module.exports = defineConfig({
	testDir: './tests/e2e',
	outputDir: path.join(process.env.WP_ARTIFACTS_PATH, 'test-results'),
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI ? [['github'], ['list']] : 'list',
	timeout: 100_000,
	globalSetup: require.resolve('./tests/e2e/global-setup.js'),
	use: {
		baseURL: baseUrl.href.replace(/\/$/, ''),
		headless: true,
		storageState: process.env.STORAGE_STATE_PATH,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'on-first-retry',
		...devices['Desktop Chrome'],
	},
	webServer: {
		command: 'npm run env:start',
		port: Number(baseUrl.port || 8889),
		timeout: 120_000,
		reuseExistingServer: true,
	},
});
