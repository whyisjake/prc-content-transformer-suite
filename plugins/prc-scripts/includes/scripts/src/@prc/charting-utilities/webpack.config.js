const defaultConfig = require('../../../../../../../packages');

module.exports = {
	...defaultConfig,
	context: __dirname,
	entry: { index: './index.js' },
	output: {
		...defaultConfig.output,
		path: __dirname + '/../../../build/@prc/charting-utilities',
		library: { name: 'prcChartingUtilities', type: 'window' },
	},
};
