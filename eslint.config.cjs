/**
 * ESLint flat config (ESLint 9+/10 via the WordPress scripts package): WordPress recommended rules
 * (React hooks, jsx-a11y, i18n) plus no-unsanitized for DOM injection sinks.
 */
const wordpress = require( '@wordpress/scripts/config/eslint.config.cjs' );
const noUnsanitized = require( 'eslint-plugin-no-unsanitized' );

module.exports = [
	{
		ignores: [
			'assets/build/**',
			'vendor/**',
			'tools/**',
			'node_modules/**',
			'coverage/**',
			'playwright-report/**',
			'test-results/**',
			'tests/seeded/**',
		],
	},
	...wordpress,
	noUnsanitized.configs.recommended,
	{
		rules: {
			'no-unsanitized/method': 'error',
			'no-unsanitized/property': 'error',
			'@wordpress/i18n-text-domain': [
				'error',
				{ allowedTextDomain: 'agentporter' },
			],
		},
	},
	{
		// Node-side tooling configs.
		files: [ '*.config.{js,cjs,ts}', 'tests/e2e/**', 'bin/**/*.js' ],
		rules: {
			'import/no-extraneous-dependencies': 'off',
		},
	},
];
