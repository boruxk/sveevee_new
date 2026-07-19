module.exports = {
	root: true,
	env: {
		browser: true,
		es2021: true,
		node: true
	},
	extends: [
		'eslint:recommended',
		'plugin:vue/vue3-essential',
		'standard'
	],
	overrides: [
		{
			files: ['*.vue'],
			processor: 'vue/.vue',
			rules: {
				indent: 'off'
			}
		}
	],
	parser: 'vue-eslint-parser',
	parserOptions: {
		ecmaVersion: 'latest',
		sourceType: 'module'
	},
	plugins: ['vue'],
	rules: {
		indent: [2, 'tab'],
		'no-tabs': 0,
		'vue/script-indent': [2, 'tab', { baseIndent: 1, switchCase: 1, ignores: [] }],
		'vue/html-indent': [2, 'tab', { baseIndent: 1 }],
		'no-const-assign': 'warn',
		'no-this-before-super': 'warn',
		'vue/html-closing-bracket-newline': ['error', {
			singleline: 'never',
			multiline: 'always'
		}],
		'vue/max-attributes-per-line': ['error', {
			singleline: 5,
			multiline: 1
		}],
		'vue/multi-word-component-names': 'off',
		'multiline-ternary': ['error', 'never'],
		'new-cap': 0,
		'space-before-function-paren': ['error', 'never'],
		'padding-line-between-statements': ['error',
			{ blankLine: 'always', prev: 'function', next: '*' },
			{ blankLine: 'always', prev: '*', next: 'function' }
		]
	}
}
