/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： eslint.config.js
 * @Description: 配置 GEO MCP Server 的 TypeScript 类型感知静态检查和生产代码约束。
 */

import eslint from '@eslint/js';
import eslintConfigPrettier from 'eslint-config-prettier';
import typescriptEslint from 'typescript-eslint';

export default typescriptEslint.config(
    {
        ignores: ['dist/**', 'node_modules/**'],
    },
    eslint.configs.recommended,
    ...typescriptEslint.configs.recommendedTypeChecked.map((config) => ({
        ...config,
        files: ['src/**/*.ts', 'tests/**/*.ts'],
    })),
    eslintConfigPrettier,
    {
        files: ['src/**/*.ts', 'tests/**/*.ts'],
        languageOptions: {
            parserOptions: {
                projectService: true,
                tsconfigRootDir: import.meta.dirname,
            },
        },
        rules: {
            '@typescript-eslint/no-explicit-any': 'error',
            '@typescript-eslint/no-floating-promises': 'error',
            '@typescript-eslint/no-misused-promises': 'error',
            '@typescript-eslint/only-throw-error': 'error',
        },
    },
);
