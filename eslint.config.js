const wpvip = require( '@automattic/eslint-plugin-wpvip' );
const wordpress = require( '@wordpress/eslint-plugin' );

const wordpressPlugins = new Set(
	wordpress.configs.recommended.flatMap( ( config ) =>
		Object.keys( config.plugins ?? {} )
	)
);

// Flat configs cannot redefine a plugin. Keep WordPress' plugin instances when
// the WPVIP and WordPress recommended configs register the same plugin.
const wpvipConfig = wpvip.configs.recommended.map( ( config ) => ( {
	...config,
	plugins: Object.fromEntries(
		Object.entries( config.plugins ?? {} ).filter(
			( [ name ] ) => ! wordpressPlugins.has( name )
		)
	),
} ) );
const wordpressJsdocConfig = wordpress.configs.recommended.find(
	( config ) =>
		Array.isArray( config.rules?.[ 'jsdoc/no-undefined-types' ] )
);
const [ , wordpressJsdocOptions ] =
	wordpressJsdocConfig.rules[ 'jsdoc/no-undefined-types' ];

module.exports = [
	{
		ignores: [
			'build/**',
			'coverage/**',
			'node_modules/**',
			'vendor/**',
			'**/*.php',
			'**/*.config.{js,mjs,ts,mts}',
			'webpack.utils.js',
			'tests/**',
		],
	},
	...wpvipConfig,
	...wordpress.configs.recommended,
	{
		rules: {
			// TypeScript validates imports; these resolver-based rules can hang on
			// large TypeScript projects.
			'import/no-unresolved': 'off',
			'import/named': 'off',
			'import/default': 'off',

			// JavaScript and TypeScript use WordPress' ESLint formatting rules.
			// Prettier is reserved for documentation and configuration files.
			'prettier/prettier': 'off',

			// The plugin uses experimental Gutenberg APIs where required.
			'@wordpress/no-unsafe-wp-apis': 'off',

			// TypeScript's rule handles type and value namespaces correctly.
			'no-shadow': 'off',
		},
	},
	{
		files: [ '**/*.{ts,tsx,mts,cts}' ],
		rules: {
			'@typescript-eslint/no-shadow': 'error',

			// JSX is a valid namespace in TypeScript docblocks.
			'jsdoc/no-undefined-types': [
				'error',
				{
					...wordpressJsdocOptions,
					definedTypes: [
						...wordpressJsdocOptions.definedTypes,
						'JSX',
					],
				},
			],
		},
	},
	{
		files: [
			'src/components/AuditLogDataView.tsx',
			'src/components/BlockDiffViewer.tsx',
		],
		rules: {
			// These existing components exceed WPVIP's default of 20 by one.
			complexity: [ 'error', 21 ],
		},
	},
];
