/**
 * Tests for the DataViews kebabCase webpack loader.
 */
// TypeScript 6 no longer includes @types/* automatically.
/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const require = createRequire( __filename );
const loader: ( source: string ) => string = require(
	'../../webpack.kebab-case-loader.js'
);

const UNLOCK_LINE =
	'const { Menu, kebabCase } = unlock(componentsPrivateApis);';

// The module webpack resolves for the bundled DataViews item actions.
const ITEM_ACTIONS_MODULE = path.join(
	path.dirname( require.resolve( '@wordpress/dataviews/package.json' ) ),
	'build-module/components/dataviews-item-actions/index.js'
);

/**
 * Evaluates the loader's output with a stubbed private API and returns the
 * kebabCase binding the patched module would use.
 */
function resolveKebabCase(
	privateApi: Record< string, unknown >
): ( value: string ) => string {
	const body = `${ loader( UNLOCK_LINE ) }\nreturn kebabCase;`;
	return new Function( 'unlock', 'componentsPrivateApis', body )(
		() => privateApi,
		{}
	);
}

describe( 'webpack.kebab-case-loader', () => {
	it( 'should patch the installed DataViews item-actions module', () => {
		// ARRANGE: Read the module the build feeds through the loader.
		const source = readFileSync( ITEM_ACTIONS_MODULE, 'utf8' );

		// ACT: Run the loader.
		const output = loader( source );

		// ASSERT: The unlock line is replaced with the guarded fallback.
		expect( source ).toContain( UNLOCK_LINE );
		expect( output ).not.toContain( UNLOCK_LINE );
		expect( output ).toContain( 'const kebabCase = coreKebabCase ??' );
	} );

	it( 'should route the item-actions module through the loader', () => {
		// ARRANGE: Find the webpack rule that applies the loader.
		const { rules } = require( '../../webpack.config.js' ).module;
		const rule = rules.find(
			( candidate: { loader?: unknown } ) =>
				typeof candidate.loader === 'string' &&
				candidate.loader.endsWith( 'webpack.kebab-case-loader.js' )
		);

		// ACT: Match the rule against the module webpack resolves.
		const matches =
			rule.test.test( ITEM_ACTIONS_MODULE ) &&
			rule.include.test( ITEM_ACTIONS_MODULE );

		// ASSERT: The build patches the module.
		expect( matches ).toBe( true );
	} );

	it( 'should prefer the kebabCase core provides', () => {
		// ARRANGE: A private API that still exposes kebabCase.
		const coreKebabCase = ( value: string ) => `core:${ value }`;

		// ACT: Resolve the binding.
		const kebabCase = resolveKebabCase( { kebabCase: coreKebabCase } );

		// ASSERT: Core's helper is used untouched.
		expect( kebabCase ).toBe( coreKebabCase );
	} );

	it( 'should fall back to a local kebabCase when core drops it', () => {
		// ARRANGE: A private API without kebabCase, as on WordPress 7.2.
		const kebabCase = resolveKebabCase( { Menu: {} } );

		// ACT + ASSERT: Kebab-case action IDs pass through, camelCase and
		// separators convert.
		expect( kebabCase( 'ignore-needs-attention' ) ).toBe(
			'ignore-needs-attention'
		);
		expect( kebabCase( 'fooBar' ) ).toBe( 'foo-bar' );
		expect( kebabCase( 'foo bar_baz' ) ).toBe( 'foo-bar-baz' );
	} );

	it( 'should fail the build when the unlock line is missing', () => {
		// ACT + ASSERT: A changed DataViews build stops the build loudly.
		expect( () =>
			loader( 'const { Menu } = unlock(privateApis);' )
		).toThrow( /kebabCase unlock/ );
	} );
} );
