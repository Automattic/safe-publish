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
			{
				test: /[\\/]dataviews-item-actions[\\/]index\.js$/,
				include: /[\\/]dataviews[\\/]build-module[\\/]/,
				loader: path.resolve(
					__dirname,
					'webpack.kebab-case-loader.js'
				),
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
				// stylesheet, enqueued alongside any built entry.
				style: {
					...defaultConfig.optimization.splitChunks.cacheGroups.style,
					name: 'style-safe-publish',
				},
			},
		},
	},
};
