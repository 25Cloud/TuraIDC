#!/usr/bin/env node
/**
 * 抓真实界面截图（不是手绘图）。
 *
 * 页面来自二五云线上站点 `idc.25y.cn`——目前唯一跑着 TuraIDC 的生产站，
 * 所以截出来的就是这套系统在浏览器里真实渲染的样子：真实商品、真实价格、真实组件。
 *
 * 这个脚本不进构建链：它要真实网络、要本机 Chrome，只在换版式时手动跑一次，
 * 产物（public/shots/*.webp）入库，构建与部署只读产物。
 *
 * 用法：
 *   node scripts/capture-shots.mjs
 *   SHOT_BASE=https://别的站点 node scripts/capture-shots.mjs
 *
 * Playwright 不在 site-web 的依赖里（避免为一个手工脚本多装一套浏览器运行时），
 * 脚本会依次到仓库根与 frontend-user-v3-www 的 node_modules 里找，找不到就报错退出。
 */
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, rmSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const outDir = resolve(root, "public/shots");
const base = (process.env.SHOT_BASE ?? "https://idc.25y.cn").replace(
  /\/+$/,
  "",
);

const VIEWPORT = { width: 1280, height: 800 };
/** 截 2 倍图再压到 1600 宽：2 倍屏上不糊，单张仍在 100 KB 以内 */
const SCALE = 2;
const OUTPUT_WIDTH = 1600;

const PLAYWRIGHT_CANDIDATES = [
  join(root, "node_modules/playwright/index.mjs"),
  join(root, "../node_modules/playwright/index.mjs"),
  join(root, "../frontend-user-v3-www/node_modules/playwright/index.mjs"),
  join(root, "../frontend-user-v4-console/node_modules/playwright/index.mjs"),
];

const playwrightEntry = PLAYWRIGHT_CANDIDATES.find((file) => existsSync(file));
if (!playwrightEntry) {
  console.error(
    "找不到 playwright。先在有它的子项目里装一次依赖（例如 cd frontend-user-v3-www && pnpm install）。",
  );
  process.exit(1);
}

const { chromium } = await import(pathToFileURL(playwrightEntry).href);

/** 截图里不该出现的浮层：右下角 AI 助手入口会压住报价栏 */
const HIDE_SELECTORS = [".ai-guide-launcher"];

/**
 * @param {string} name 产物名
 * @param {(page: import("playwright").Page) => Promise<void>} prepare 打开页面并摆好位置
 */
async function capture(browser, name, prepare) {
  const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: SCALE,
    locale: "zh-CN",
    timezoneId: "Asia/Shanghai",
  });
  const page = await context.newPage();
  await page.addInitScript((selectors) => {
    const inject = () => {
      const style = document.createElement("style");
      style.textContent = selectors
        .map((selector) => `${selector}{display:none!important}`)
        .join("\n");
      document.head.append(style);
    };
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", inject);
    } else {
      inject();
    }
  }, HIDE_SELECTORS);

  await prepare(page);
  await page.waitForTimeout(600);

  const png = join(outDir, `${name}.png`);
  await page.screenshot({ path: png });

  const webp = join(outDir, `${name}.webp`);
  execFileSync(
    process.execPath,
    [
      "-e",
      `const sharp=require(${JSON.stringify(
        join(root, "node_modules/sharp"),
      )});sharp(${JSON.stringify(png)}).resize({width:${OUTPUT_WIDTH}}).webp({quality:82}).toFile(${JSON.stringify(
        webp,
      )}).then(()=>console.log("ok"))`,
    ],
    { stdio: "inherit" },
  );

  const { size } = await import("node:fs").then((fs) => fs.statSync(webp));
  rmSync(png);
  console.log(`✓ ${name}.webp（${Math.round(size / 1024)} KB）`);
  await context.close();
}

mkdirSync(outDir, { recursive: true });

const browser = await chromium.launch({ channel: "chrome", headless: true });

try {
  // 商品选购页：分组侧栏 + 商品卡片 + 库存与价格，最像「这套系统在卖东西」
  await capture(browser, "catalog", async (page) => {
    await page.goto(`${base}/products`, {
      waitUntil: "networkidle",
      timeout: 90_000,
    });
    await page.waitForSelector("text=/立即选购/", { timeout: 30_000 });
    await page.waitForTimeout(1200);
  });
} finally {
  await browser.close();
}

console.log(`\n截图完成，输出目录：${outDir}`);
