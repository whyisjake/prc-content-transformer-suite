/**
 * ESLint configuration.
 *
 * Extends the same base @wordpress/scripts applies by default, with one
 * deliberate relaxation.
 */
module.exports = {
	extends: [ 'plugin:@wordpress/recommended' ],
	settings: {
		// @wordpress/* packages are supplied by WordPress at runtime and mapped
		// to globals by the build, not bundled or installed here. Treating them
		// as core modules stops the import rules reporting them as unresolved
		// and extraneous.
		'import/core-modules': [
			'@wordpress/api-fetch',
			'@wordpress/block-editor',
			'@wordpress/blocks',
			'@wordpress/components',
			'@wordpress/core-data',
			'@wordpress/data',
			'@wordpress/editor',
			'@wordpress/element',
			'@wordpress/i18n',
			'@wordpress/plugins',
		],
	},
	rules: {
		// Object keys here are REST payload fields, and the WordPress REST API
		// is snake_case by convention. Forcing camelCase would rename fields on
		// the wire and break the PHP endpoints that read them. Identifiers are
		// still required to be camelCase.
		camelcase: [ 'error', { properties: 'never' } ],
	},
};
