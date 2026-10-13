import { defineConfig, devices } from "@playwright/test";

/**
 * 官网端（frontend-user-v3-www）e2e 配置。
 *
 * 与管理端一致：不用真实后端，用例自己 `page.route` 接管站点只读接口，
 * 因此 CI / 干净 clone 下不依赖数据库。
 *
 * 端口与 vite.config.js 里的默认 5175 错开，避免和本地 `pnpm run dev` 抢端口；
 * VITE_API_BASE_URL 指向一个不会被访问的本地地址，真正的数据全部来自用例的路由拦截。
 */
export default defineConfig({
  testDir: "./tests/e2e",
  outputDir: "./test-results",
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [["list"]],
  use: {
    baseURL: "http://127.0.0.1:5176",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  webServer: {
    // 不用 pnpm run，直接 vite 传参，避免 --port 被吞掉。
    command:
      "pnpm exec vite --host 127.0.0.1 --mode development --port 5176 --strictPort",
    env: {
      VITE_API_BASE_URL: "http://127.0.0.1:8000/api",
    },
    url: "http://127.0.0.1:5176",
    reuseExistingServer: false,
    timeout: 120_000,
  },
  projects: [
    {
      // 顶栏下拉只存在于桌面断点（≤960px 走汉堡菜单），因此只跑桌面视口。
      name: "desktop",
      use: {
        ...devices["Desktop Chrome"],
        viewport: { width: 1440, height: 900 },
      },
    },
  ],
});
