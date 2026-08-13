/**
 * Authenticate as admin, ensure the plugin is active, and persist storage state.
 */

const { execFileSync } = require( 'child_process' );
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Ensure private storage is writable for E2E private-folder flows.
 *
 * @return {void}
 */
function ensurePrivateStorage() {
	try {
		execFileSync(
			'npx',
			[
				'wp-env',
				'run',
				'tests-cli',
				'wp',
				'eval',
				'wp_mkdir_p("/tmp/media-manager-private"); $s=get_option("media_manager_settings",array()); if(!is_array($s)){$s=array();} $s["private_storage_path"]="/tmp/media-manager-private"; update_option("media_manager_settings",$s);',
			],
			{
				cwd: process.cwd(),
				stdio: 'pipe',
			}
		);
	} catch ( error ) {
		// Non-fatal: private-folder specs will skip when storage is unready.
		// eslint-disable-next-line no-console
		console.warn(
			'Unable to configure private storage for E2E:',
			error?.message || error
		);
	}
}

/**
 * @param {import('@playwright/test').FullConfig} config
 * @return {Promise<void>}
 */
async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( {
		baseURL,
	} );

	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );

	await requestUtils.setupRest();

	// wp-env does not always leave plugins active on the tests site after restart.
	await requestUtils.activatePlugin( 'media-manager' );
	ensurePrivateStorage();

	await requestContext.dispose();
}

module.exports = globalSetup;
