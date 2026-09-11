import js from '@eslint/js';
import globals from 'globals';

export default [
    js.configs.recommended,
    {
        files: ['resources/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            // ブラウザで動くコードなので window / document を既知の変数として扱う
            globals: globals.browser,
        },
        rules: {
            'no-unused-vars': 'error',
            'no-console': 'warn',
            eqeqeq: 'error',
        },
    },
];
