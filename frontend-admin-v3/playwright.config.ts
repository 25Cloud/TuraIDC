import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: './test-results',
  // The smoke suite shares one Vite server and performs many first-load module
  // compilations. Running every test in parallel can leave pages on the empty
  // app shell before their assertions start, so keep each project serial.
  fullyParallel: false,
  workers: 3,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:5176',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: {
    // 不带 .env 文件也能启动：CI/干净 clone 下 .env.development 被 gitignore。
    // 用 pnpm exec vite 直接传参（pnpm run 会把 --port 原样传递导致端口未生效）。
    command:
      'VITE_API_BASE_URL=http://127.0.0.1:8000/api VITE_BASE_URL=/ pnpm exec vite --host 127.0.0.1 --mode development --port 5176 --strictPort',
    url: 'http://127.0.0.1:5176',
    reuseExistingServer: false,
    timeout: 120_000,
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
    {
      name: 'tablet',
      use: { ...devices['iPad (gen 7)'], viewport: { width: 768, height: 1024 } },
    },
    {
      name: 'mobile',
      use: { ...devices['Pixel 5'], viewport: { width: 390, height: 844 } },
    },
  ],
});
