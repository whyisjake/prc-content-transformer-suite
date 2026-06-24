const path = require('path');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

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
		path: __dirname + '/../../../build/third-party/d3-v7',
		library: { name: 'd3v7', type: 'window' },
	},
};
