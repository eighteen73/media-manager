module.exports = [
	{
		ignores: [
			'build/**',
			'node_modules/**',
			'vendor/**',
			'**/*.test.js',
			'src/admin/setup-tests.js',
		],
	},
	{
		files: [ 'src/**/*.{js,jsx}' ],
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'module',
			parserOptions: {
				ecmaFeatures: {
					jsx: true,
				},
			},
			globals: {
				window: 'readonly',
				document: 'readonly',
				wp: 'readonly',
				mediaManagerAdmin: 'readonly',
				mediaManagerTeardown: 'writable',
				jQuery: 'readonly',
				MutationObserver: 'readonly',
				console: 'readonly',
				setTimeout: 'readonly',
				clearTimeout: 'readonly',
				Number: 'readonly',
				Set: 'readonly',
			},
		},
		rules: {
			'no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
					caughtErrorsIgnorePattern: '^_',
				},
			],
			'no-undef': 'error',
		},
	},
];
