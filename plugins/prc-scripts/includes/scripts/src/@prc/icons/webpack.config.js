const defaultConfig = require('../../../../../../../packages');

module.exports = {
	...defaultConfig,
	context: __dirname,
	entry: { index: './src/index.js' },
	output: {
		...defaultConfig.output,
		path: __dirname + '/../../../build/@prc/icons',
		library: { name: 'prcIcons', type: 'window' },
	},
};
