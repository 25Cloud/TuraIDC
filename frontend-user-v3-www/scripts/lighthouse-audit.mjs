// Lighthouse 审计脚本：本地起 vite preview 服务 dist 后审计指定路径
//
// 用法：
//   node scripts/lighthouse-audit.mjs [路径] [标签] [--dist=<目录>]
//   例：node scripts/lighthouse-audit.mjs / optimized
//       node scripts/lighthouse-audit.mjs / baseline --dist=C:/path/to/baseline
//
// 报告输出到 docs/perf-audit/lighthouse/<标签>.json / .html
// 依赖：根 node_modules 中已安装 lighthouse（npm install --no-save lighthouse）

import { createRequire } from "node:module";
import { spawn } from "node:child_process";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import fs from "node:fs";
import { preview } from "vite";

const require = createRequire(import.meta.url);
const { default: lighthouse } = require("lighthouse");

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const wwwRoot = path.resolve(__dirname, "..");
const port = 4173;
const baseUrl = `http://127.0.0.1:${port}`;

// git bash (MSYS) 会把以 / 开头的参数自动转换为 Windows 路径（如 / → D:/Program Files/Git/），
// 这里做防御性还原：截取 Program Files/Git 之后的子串作为目标路径。
function resolveTargetPath(raw) {
  const input = raw || "/";
  const marker = /Program Files(?: \(x86\))?[\\/]Git/;
  const match = input.match(marker);
  let value = match ? input.slice(match.index + match[0].length) : input;
  if (!value || !value.startsWith("/")) {
    value = `/${value.replace(/^[\\/]+/, "")}`;
  }
  return value;
}

const targetPath = resolveTargetPath(process.argv[2]);
const label = (process.argv[3] || "audit").replace(/[^\w-]/g, "-");
const distArg = process.argv.find((arg) => arg.startsWith("--dist="));
const distRoot = distArg ? distArg.split("=")[1] : wwwRoot;
const outDir = path.resolve(wwwRoot, "docs/perf-audit/lighthouse");

const chromePath = "C:/Program Files/Google/Chrome/Application/chrome.exe";
const chromePort = 9222;
const chromeUserDataDir = path.join(os.tmpdir(), "lh-chrome-profile");

// chrome-launcher 与当前 Chrome 版本存在连接问题，改为脚本手动管理 headless Chrome
function startChrome() {
  return spawn(
    chromePath,
    [
      "--headless",
      "--no-sandbox",
      "--disable-dev-shm-usage",
      "--disable-gpu",
      "--no-first-run",
      "--no-default-browser-check",
      `--remote-debugging-port=${chromePort}`,
      `--user-data-dir=${chromeUserDataDir}`,
      "about:blank",
    ],
    { stdio: "ignore", windowsHide: true },
  );
}

async function waitForChrome(timeoutMs = 30000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await fetch(
        `http://127.0.0.1:${chromePort}/json/version`,
        { method: "GET" },
      );
      if (res.ok) return true;
    } catch {
      // Chrome 尚未就绪
    }
    await new Promise((resolve) => setTimeout(resolve, 300));
  }
  return false;
}

async function waitForServer(url, timeoutMs = 30000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    try {
      const res = await fetch(url, { method: "HEAD" });
      if (res.status < 500) return true;
    } catch {
      // 服务未就绪，继续等待
    }
    await new Promise((resolve) => setTimeout(resolve, 300));
  }
  return false;
}

function formatMs(value) {
  return Number.isFinite(value) ? `${Math.round(value)}ms` : "n/a";
}

function formatKb(bytes) {
  return Number.isFinite(bytes) ? `${(bytes / 1024).toFixed(1)}KB` : "n/a";
}

async function main() {
  fs.mkdirSync(outDir, { recursive: true });

  const server = await preview({
    root: distRoot,
    preview: { host: "127.0.0.1", port, strictPort: true },
    logLevel: "silent",
  });

  const chrome = startChrome();
  const chromeReady = await waitForChrome();
  if (!chromeReady) {
    await server.close();
    chrome.kill();
    console.error(`headless Chrome 未就绪（端口 ${chromePort}）`);
    process.exit(1);
  }

  const url = `${baseUrl}${targetPath}`;
  const ready = await waitForServer(url);
  if (!ready) {
    await server.close();
    chrome.kill();
    console.error(`preview server 未就绪：${url}`);
    process.exit(1);
  }

  try {
    const flags = {
      output: ["json", "html"],
      onlyCategories: ["performance"],
      formFactor: "mobile",
      screenEmulation: {
        mobile: true,
        width: 390,
        height: 844,
        deviceScaleFactor: 3,
        disabled: false,
      },
      throttling: {
        rttMs: 150,
        throughputKbps: 1638.4,
        cpuSlowdownMultiplier: 4,
      },
      // 用 devtools 真实节流而非 simulate：Lighthouse 12 的 lantern 模型
      // 在本地预览场景可能抛 NO_LCP
      throttlingMethod: "devtools",
      port: chromePort,
      logLevel: "error",
    };

    const result = await lighthouse(url, flags);
    const lhr = result?.lhr;
    if (!lhr) {
      console.error("Lighthouse 审计失败（无结果）");
      process.exit(1);
    }

    const jsonPath = path.join(outDir, `${label}.json`);
    const htmlPath = path.join(outDir, `${label}.html`);
    fs.writeFileSync(jsonPath, JSON.stringify(lhr, null, 2));
    const htmlReport = Array.isArray(result.report)
      ? result.report[result.report.length - 1] || ""
      : result.report;
    fs.writeFileSync(htmlPath, typeof htmlReport === "string" ? htmlReport : "");

    const audits = lhr.audits || {};
    const metrics = {
      FCP: formatMs(audits["first-contentful-paint"]?.numericValue),
      LCP: formatMs(audits["largest-contentful-paint"]?.numericValue),
      CLS: audits["cumulative-layout-shift"]?.numericValue?.toFixed(3),
      TBT: formatMs(audits["total-blocking-time"]?.numericValue),
      SI: formatMs(audits["speed-index"]?.numericValue),
      TTI: formatMs(audits["interactive"]?.numericValue),
    };

    const resource = audits["resource-summary"]?.details?.items || [];
    const totalBytes = resource.reduce((sum, item) => sum + (item.transferSize || 0), 0);
    const byType = {};
    resource.forEach((item) => {
      byType[item.resourceType] = (byType[item.resourceType] || 0) + (item.transferSize || 0);
    });

    const perfScore = Math.round((lhr.categories?.performance?.score || 0) * 100);

    console.log("========== Lighthouse 审计结果 ==========");
    console.log(`URL        : ${url} (${label})`);
    console.log(`Performance: ${perfScore}/100`);
    console.log(`FCP        : ${metrics.FCP}`);
    console.log(`LCP        : ${metrics.LCP}`);
    console.log(`CLS        : ${metrics.CLS}`);
    console.log(`TBT        : ${metrics.TBT}`);
    console.log(`SI         : ${metrics.SI}`);
    console.log(`TTI        : ${metrics.TTI}`);
    console.log(`传输字节   : ${formatKb(totalBytes)}`);
    Object.entries(byType)
      .sort((a, b) => b[1] - a[1])
      .forEach(([type, bytes]) => {
        console.log(`  - ${type.padEnd(8)}: ${formatKb(bytes)}`);
      });
    console.log(`报告       : ${htmlPath}`);
    console.log("==========================================");
  } finally {
    await server.close();
    chrome.kill();
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
