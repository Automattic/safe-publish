module.exports = {
	...require( '@wordpress/prettier-config' ),
	embeddedLanguageFormatting: 'off',
	overrides: [
		{
			files: [ '*.json', '*.yml', '*.yaml', '*.md' ],
			options: {
				useTabs: false,
				tabWidth: 2,
			},
		},
		{
			files: [ '*.md' ],
			options: {
				proseWrap: 'never',
			},
		},
	],
};
