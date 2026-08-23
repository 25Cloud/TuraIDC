// 根级 ESLint 配置：仅供 lint-staged 在提交钩子中运行的 `eslint --fix` 使用。
// 各包的真实 lint（规则集、Vue/TS 插件）以各自目录下的 eslint.config.* 为准，
// 根配置不重复定义规则，只保证 CLI 能找到合法的 flat config。
import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';
import globals from 'globals';

export default [
  {
    ignores: [
      '**/node_modules/**',
      '**/dist/**',
      '**/coverage/**',
      '**/vendor/**',
      '**/.vite/**',
      '**/test-results/**',
      '**/playwright-report/**',
    ],
  },
  js.configs.recommended,
  {
    files: ['**/*.{js,mjs,cjs,ts,mts,cts,vue}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.node,
      },
    },
    rules: {
      // 与各子包约定一致：下划线前缀的未使用变量视为有意忽略
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      // 类型感知与细粒度规则由各子包 lint / vue-tsc 把关，根配置不重复判定
      'no-undef': 'off',
      'no-constant-condition': 'off',
      'no-constant-binary-expression': 'off',
      'no-dupe-else-if': 'off',
    },
  },
  {
    // TypeScript 文件需要专用解析器，否则提交钩子解析失败
    files: ['**/*.{ts,mts,cts}'],
    languageOptions: {
      parser: tseslint.parser,
    },
  },
  {
    // vue 单文件（含 <script setup lang="ts">）需要专用解析器，否则提交钩子解析失败
    files: ['**/*.vue'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
        parser: tseslint.parser,
      },
    },
  },
];
