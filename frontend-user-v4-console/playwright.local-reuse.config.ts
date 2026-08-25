import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  outputDir: process.env.TEMP ? `${process.env.TEMP}/turaidc-playwright-results` : './test-results',
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:5177',
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
