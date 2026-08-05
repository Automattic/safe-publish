const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		posts: path.resolve( process.cwd(), 'src', 'posts.tsx' ),
		'audit-log': path.resolve( process.cwd(), 'src', 'audit-log.tsx' ),
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: [ '.tsx', '.ts', '.js', '.jsx' ],
	},
	externals: {
		...defaultConfig.externals,
		react: 'React',
		'react-dom': 'ReactDOM',
		'react-jsx-runtime': 'wp.element',
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			{
				test: /\.tsx?$/,
				use: [
					{
						loader: 'ts-loader',
						options: {
							configFile: 'tsconfig.json',
							transpileOnly: true,
						},
					},
				],
				exclude: /node_modules/,
			},
		],
	},
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			...defaultConfig.optimization.splitChunks,
			cacheGroups: {
				...defaultConfig.optimization.splitChunks.cacheGroups,
				// Merge every entry's style.scss into one fixed-name
				// stylesheet, enqueued on all admin pages.
				style: {
					...defaultConfig.optimization.splitChunks.cacheGroups.style,
					name: 'style-safe-publish',
				},
			},
		},
	},
};
