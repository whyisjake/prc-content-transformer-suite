const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...defaultConfig,
	context: __dirname,
	entry: { index: './index.js' },
	output: {
		...defaultConfig.output,
		path: __dirname + '/../../../build/third-party/d3-force',
		library: { name: 'd3Force', type: 'window' },
	},
};
