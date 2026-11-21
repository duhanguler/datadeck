const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'datadeck-app': path.resolve( __dirname, 'ui/index.tsx' ),
		'datadeck-toggle': path.resolve( __dirname, 'ui/toggle.ts' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		chunkFilename: 'chunks/[name].[contenthash:8].js',
		clean: true,
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias || {} ),
			'@datadeck': path.resolve( __dirname, 'ui' ),
		},
	},
};
