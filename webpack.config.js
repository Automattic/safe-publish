const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( process.cwd(), 'src', 'index.tsx' ),
		'history': path.resolve( process.cwd(), 'src', 'history.tsx' ),
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
};
