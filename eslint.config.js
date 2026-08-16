const wpvip = require( '@automattic/eslint-plugin-wpvip' );
const wordpress = require( '@wordpress/eslint-plugin' );

const wordpressPlugins = new Set(
	wordpress.configs.recommended.flatMap( ( config ) =>
		Object.keys( config.plugins ?? {} )
	)
);
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
			'import/no-unresolved': 'off',
			'import/named': 'off',
			'import/default': 'off',
			'prettier/prettier': 'off',
			'@wordpress/no-unsafe-wp-apis': 'off',
			'no-shadow': 'off',
		},
	},
	{
		files: [ '**/*.{ts,tsx,mts,cts}' ],
		rules: {
			'@typescript-eslint/no-shadow': 'error',
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
			complexity: [ 'error', 21 ],
		},
	},
];
