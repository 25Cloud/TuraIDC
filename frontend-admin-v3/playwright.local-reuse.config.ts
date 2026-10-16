import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: process.env.TEMP ? `${process.env.TEMP}/turaidc-playwright-results` : './test-results',
  reporter: 'list',
  use: {
    // 钉住语言：应用首屏按浏览器偏好自适应，而本套 spec 的断言写的是中文。
    // 不钉的话，宿主语言是英文时页面就渲染英文，整批用例会以「看起来像回归」的形式变红。
    locale: 'zh-CN',
    baseURL: 'http://127.0.0.1:5176',
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
  ],
});
