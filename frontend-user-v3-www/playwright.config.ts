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
  // 并行跑：串行时 64 个用例要 3 分多钟，并行后实测 85 秒，失败集合不变。
  // 并行度可用 PLAYWRIGHT_WORKERS 覆盖，CI 上按 4 核给 4。
  fullyParallel: true,
  workers: Number(process.env.PLAYWRIGHT_WORKERS ?? (process.env.CI ? 4 : 8)),
  retries: process.env.CI ? 1 : 0,
  reporter: [["list"]],
  use: {
    // 钉住语言：应用首屏按浏览器偏好自适应，而本套 spec 的断言写的是中文。
    // 不钉的话，宿主语言是英文时页面就渲染英文，整批用例会以「看起来像回归」的形式变红。
    locale: 'zh-CN',
    // Cookie 告知横幅不是本套用例的验证对象；移动端抽屉选项会被它挡住。
    // 统一以「已确认」状态启动，避免每个用例各自处理，也不污染视觉快照。
    storageState: {
      cookies: [],
      origins: [
        {
          origin: "http://127.0.0.1:5176",
          localStorage: [{ name: "www_cookie_consent", value: "accepted" }],
        },
      ],
    },
    // 本机没下载 Playwright 自带 chromium 时，可用 PW_E2E_CHANNEL=chrome 走系统 Chrome
    channel: process.env.PW_E2E_CHANNEL || undefined,
    baseURL: "http://127.0.0.1:5176",
    // 预览（CI 门禁）模式只在失败重试时留 trace：常开 trace 会持续抓 DOM 快照，
    // 重交互用例会被拖到超时。
    trace: process.env.PW_E2E_PREVIEW === "1" ? "on-first-retry" : "retain-on-failure",
    screenshot: "only-on-failure",
  },
  webServer: {
    // 不用 pnpm run，直接 vite 传参，避免 --port 被吞掉。
    // PW_E2E_PREVIEW=1 时改跑构建产物：dev server 按需编译在并行 worker 下会互相
    // 抢编译，导致首屏断言随机超时（CI 门禁因此统一跑 preview，本地开发仍走 dev）。
    // 直接调 vite 入口脚本，不走 `pnpm exec`：corepack 的 pnpm 版本一旦与
    // packageManager 不一致（corepack 自升级到 v12 就是），webServer 会在启动阶段
    // BAD_PM_VERSION 挂掉，跟用例无关。
    command:
      process.env.PW_E2E_PREVIEW === "1"
        ? "node node_modules/vite/bin/vite.js preview --host 127.0.0.1 --port 5176 --strictPort"
        : "node node_modules/vite/bin/vite.js --host 127.0.0.1 --mode development --port 5176 --strictPort",
    env: {
      VITE_API_BASE_URL: "http://127.0.0.1:8000/api",
    },
    url: "http://127.0.0.1:5176",
    reuseExistingServer: false,
    timeout: process.env.PW_E2E_PREVIEW === "1" ? 60_000 : 120_000,
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
