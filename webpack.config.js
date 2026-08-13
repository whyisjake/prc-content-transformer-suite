/**
 * Shared webpack configuration for the suite.
 *
 * Five plugin configs extend this by requiring '../../webpack.config' and
 * spreading it. In the platform monorepo these plugins were extracted from,
 * that file existed at the repo root; it was not carried across, so every one
 * of those builds failed with "Cannot find module '../../webpack.config'".
 * The committed build output is why that went unnoticed.
 *
 * It is deliberately thin. @wordpress/scripts already supplies the loaders,
 * externals, and dependency-extraction plugin a WordPress build needs, and
 * plugin-local configs override entry and output on top of it. The @prc/*
 * packages resolve through npm workspaces rather than webpack aliases.
 */

const baseConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...baseConfig,

	// Spread so a plugin overriding one key does not drop the rest.
	output: {
		...baseConfig.output,
	},

	// CopyPlugin copies a block.json that plugin-local builds do not have, and
	// it fails the build when the source directory is absent. Plugins that do
	// ship blocks use @wordpress/scripts directly rather than this config.
	plugins: ( baseConfig.plugins || [] )
		.filter( Boolean )
		.filter( ( plugin ) => plugin.constructor.name !== 'CopyPlugin' ),
};
