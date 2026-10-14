import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './test-results',
  // 该套件共享一个 Vite 服务，测试之间保持串行，避免首屏模块编译竞争。
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:5177',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    // 不带 .env 文件也能启动：CI/干净 clone 下 .env.development 被 gitignore，
    // 而 request.ts 顶层要求 VITE_API_BASE_URL 存在，缺少会导致应用挂载失败。
    // 默认指向本机后端（本地未起服务时请求失败不影响控制台壳渲染）。
    //
    // 接口地址必须走 Playwright 的 env 字段，不能用 `VAR=value 命令` 前缀 ——
    // 那是 Unix shell 写法，Windows 的 cmd 不认，webServer 会直接启动失败。
    command: 'pnpm exec vite --host 127.0.0.1 --mode development --port 5177 --strictPort',
    env: {
      VITE_API_BASE_URL: 'http://127.0.0.1:8000/api',
      VITE_BASE_URL: '/',
    },
    url: 'http://127.0.0.1:5177',
    reuseExistingServer: false,
    timeout: 120_000,
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
  ],
});
