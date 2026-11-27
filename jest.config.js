/**
 * Jest with the WordPress preset (jsdom, Babel transform, style mocks).
 */
module.exports = {
	preset: '@wordpress/jest-preset-default',
	rootDir: __dirname,
	testMatch: [ '<rootDir>/resources/js/**/test/**/*.test.[jt]s?(x)' ],
	testPathIgnorePatterns: [
		'/node_modules/',
		'<rootDir>/vendor/',
		'<rootDir>/tools/',
	],
	collectCoverageFrom: [
		'resources/js/**/*.{ts,tsx}',
		'!resources/js/**/test/**',
	],
	coverageDirectory: '<rootDir>/coverage/js',
	coverageThreshold: {
		global: { lines: 80, statements: 80, functions: 80, branches: 80 },
	},
};
