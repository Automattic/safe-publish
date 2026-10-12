/**
 * Webpack loader for the bundled DataViews item actions module.
 *
 * DataViews unlocks kebabCase from core's private components API to build the
 * action modal's class name. Core builds past Gutenberg #81294 no longer expose
 * it, so opening any action modal throws. Fall back to a local helper when core
 * does not provide it.
 */

const SEARCH = 'const { Menu, kebabCase } = unlock(componentsPrivateApis);';

const REPLACE = `
const { Menu, kebabCase: coreKebabCase } = unlock(componentsPrivateApis);
const kebabCase = coreKebabCase ?? ( ( str ) => String( str ?? '' )
	.replace( /([a-z0-9])([A-Z])/g, '$1-$2' )
	.replace( /[^A-Za-z0-9]+/g, '-' )
	.toLowerCase() );`;

module.exports = function ( source ) {
	// Fail the build if a DataViews update changes the line, so the patch is
	// revisited instead of silently skipped.
	if ( ! source.includes( SEARCH ) ) {
		throw new Error(
			'webpack.kebab-case-loader.js: the DataViews kebabCase unlock ' +
				'was not found. Remove this loader if DataViews no longer ' +
				'needs it.'
		);
	}

	// A replacer function keeps "$" sequences in REPLACE literal.
	return source.replace( SEARCH, () => REPLACE );
};
