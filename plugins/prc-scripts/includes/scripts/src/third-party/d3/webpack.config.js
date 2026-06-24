const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

// Bundle the UMD build so webpack does not traverse `d3/src` (npm may omit
// transitive `d3-*` packages from the lockfile under workspaces).
const d3Bundle = path.join(
	path.dirname(require.resolve('d3')),
	'..',
	'dist',
	'd3.js'
);

module.exports = {
	...defaultConfig,
	resolve: {
		...(defaultConfig.resolve || {}),
		alias: {
			...(defaultConfig.resolve && defaultConfig.resolve.alias),
			d3$: d3Bundle,
		},
	},
	context: __dirname,
	entry: { index: './index.js' },
	output: {
		...defaultConfig.output,
		path: __dirname + '/../../../build/third-party/d3',
		library: { name: 'd3', type: 'window' },
	},
};
