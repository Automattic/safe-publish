module.exports = {
	extends: [
		'plugin:@automattic/wpvip/recommended',
		'plugin:@wordpress/eslint-plugin/recommended',
		'plugin:@typescript-eslint/recommended',
	],
	rules: {
		// Disable import resolution rules - they cause hangs with large codebases
		// and TypeScript resolver. TypeScript itself handles import validation.
		'import/no-unresolved': 'off',
		'import/named': 'off',
		'import/default': 'off',

		// Disable prettier - this project uses WordPress coding standards (tabs,
		// single quotes) which conflict with the wpvip prettier config (spaces,
		// double quotes).
		'prettier/prettier': 'off',

		// Allow usage of Gutenberg experimental components.
		'@wordpress/no-unsafe-wp-apis': 'off',

		// Use TypeScript-aware no-shadow rule instead of base rule to avoid
		// false positives with TypeScript type/value namespacing.
		'no-shadow': 'off',
		'@typescript-eslint/no-shadow': 'error',
	},
};
