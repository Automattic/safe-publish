import { defineConfig, PlaywrightTestConfig } from '@playwright/test';

const baseConfig =
	require( '@wordpress/scripts/config/playwright.config.js' ) as PlaywrightTestConfig; // eslint-disable-line @typescript-eslint/no-var-requires

const config = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	// The suite targets an already-running environment. The base config
	// auto-starts one with a command this project does not define, on
	// default ports that collide in worktrees.
	webServer: undefined,
} );

export default config;
