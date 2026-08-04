/**
 * Authenticate as admin, ensure the plugin is active, and persist storage state.
 */

const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

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

	await requestContext.dispose();
}

module.exports = globalSetup;
