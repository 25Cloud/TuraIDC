import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { defineConfig } from '@playwright/test';

const here = path.dirname(fileURLToPath(import.meta.url));

/**
 * 演示站冒烟专用配置。
 *
 * 与默认配置的差别：
 * - 只跑 `tests/e2e/demo/`，默认套件用 `testIgnore` 把这一组排除；
 * - 不自起 Vite，而是直接托管构建产物 `demo/dist`（纯静态，就是演示站线上跑的东西）；
 * - 用本机 Chrome（这台机器没有 Playwright 自带的 Chromium）。
 *
 * 跑之前先构建产物：
 *   node backend/scripts/build_demo.mjs
 *   pnpm --filter turaidc-user-v4-console run test:demo
 */
export default defineConfig({
  testDir: './tests/e2e/demo',
  workers: 1,
  retries: 0,
  reporter: [['list']],
  timeout: 120_000,
  use: {
    channel: 'chrome',
    locale: 'zh-CN',
    viewport: { width: 1440, height: 900 },
    baseURL: 'http://127.0.0.1:4190',
    screenshot: 'off',
    trace: 'off',
  },
  webServer: {
    command: `node ${path.resolve(here, '../backend/scripts/serve_demo.mjs')} --port=4190`,
    url: 'http://127.0.0.1:4190/start/',
    reuseExistingServer: true,
    timeout: 60_000,
  },
});
