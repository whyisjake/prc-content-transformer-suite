const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');

const basePluginsWithoutDep = (defaultConfig.plugins || []).filter(
	(plugin) => !(plugin instanceof DependencyExtractionWebpackPlugin)
);

module.exports = {
	...defaultConfig,
	context: __dirname,
	entry: { index: './index.js' },
	output: {
		...defaultConfig.output,
		path: __dirname + '/../../../build/third-party/emotion-styled',
		library: { name: 'emotionStyled', type: 'window' },
	},
	plugins: [
		...basePluginsWithoutDep,
		new DependencyExtractionWebpackPlugin({
			requestToExternal(request) {
				if (request === 'react') {
					return 'React';
				}
				if (request === 'react-dom' || request === 'react-dom/client') {
					return 'ReactDOM';
				}
			},
			requestToHandle(request) {
				if (request === 'react') {
					return 'react';
				}
				if (request === 'react-dom' || request === 'react-dom/client') {
					return 'react-dom';
				}
			},
		}),
	],
};
