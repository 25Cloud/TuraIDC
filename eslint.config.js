// 根级 ESLint 配置：仅供 lint-staged 在提交钩子中运行的 `eslint --fix` 使用。
// 各包的真实 lint（规则集、Vue/TS 插件）以各自目录下的 eslint.config.* 为准，
// 根配置不重复定义规则，只保证 CLI 能找到合法的 flat config。
import js from '@eslint/js';

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
    },
    rules: {
      // 与各子包约定一致：下划线前缀的未使用变量视为有意忽略
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    },
  },
];