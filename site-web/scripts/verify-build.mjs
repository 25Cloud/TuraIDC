#!/usr/bin/env node
/**
 * 站点产物自检：EdgeOne 构建命令里跟在 astro build 之后执行。
 *
 * 它只校验「构建坏了会静默失效」的东西：正文是否真的进了 HTML、SEO 头部
 * 是否齐全、结构化数据能否解析、sitemap/robots 是否生成、站内链接是否还有
 * 目标、首屏有没有偷偷带上 JS 运行时。样式和动效不在这里判断，靠人工过页面。
 */
import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, extname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const dist = resolve(root, "dist");

const failures = [];
const checks = [];

function check(name, condition, detail = "") {
  checks.push({ name, ok: Boolean(condition), detail });
  if (!condition) {
    failures.push(detail ? `${name}：${detail}` : name);
  }
}

function read(relativePath) {
  const target = resolve(dist, relativePath);
  return existsSync(target) ? readFileSync(target, "utf8") : "";
}

function walk(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const full = join(directory, entry.name);
    return entry.isDirectory() ? walk(full) : [full];
  });
}

const indexHtml = read("index.html");
check("dist/index.html 存在", indexHtml.length > 0);

// 去掉标签和注释后的可见文本，用于确认正文不是空壳
const visibleText = indexHtml
  .replace(/<script[\s\S]*?<\/script>/gi, " ")
  .replace(/<style[\s\S]*?<\/style>/gi, " ")
  .replace(/<[^>]+>/g, " ")
  .replace(/&nbsp;/g, " ")
  .replace(/\s+/g, " ")
  .trim();

const expectText = [
  "开源 IDC 业务与财务系统",
  "商品配置",
  "我们没有",
  "从智简魔方财务搬过来",
  "商业授权",
  "这套系统免费吗",
  "AGPL-3.0-or-later",
];
for (const snippet of expectText) {
  check(`正文包含「${snippet}」`, visibleText.includes(snippet));
}

const title = indexHtml.match(/<title>([^<]*)<\/title>/)?.[1] ?? "";
check("title 非空且含 TuraIDC", title.includes("TuraIDC"), title);

const description =
  indexHtml.match(/<meta name="description" content="([^"]*)"/)?.[1] ?? "";
check(
  "description 长度 60-160",
  description.length >= 60 && description.length <= 160,
  `${description.length} 字`,
);

const canonical =
  indexHtml.match(/<link rel="canonical" href="([^"]*)"/)?.[1] ?? "";
check("canonical 指向主域", canonical === "https://www.turaidc.com/", canonical);

check("存在 og:title", indexHtml.includes('property="og:title"'));
check("存在 og:description", indexHtml.includes('property="og:description"'));
check("未被 noindex", !indexHtml.includes("noindex"));

const ldMatch = indexHtml.match(
  /<script type="application\/ld\+json">([\s\S]*?)<\/script>/,
);
if (ldMatch) {
  try {
    const parsed = JSON.parse(ldMatch[1]);
    const graph = parsed["@graph"] ?? [parsed];
    const types = graph.map((node) => node["@type"]);
    check("结构化数据含 Organization", types.includes("Organization"));
    check(
      "结构化数据含 SoftwareApplication",
      types.includes("SoftwareApplication"),
    );
    check("结构化数据含 FAQPage", types.includes("FAQPage"));

    const faq = graph.find((node) => node["@type"] === "FAQPage");
    const questions = faq?.mainEntity?.length ?? 0;
    check("FAQ 结构化条目 >= 5", questions >= 5, `${questions} 条`);

    const app = graph.find((node) => node["@type"] === "SoftwareApplication");
    const offers = app?.offers?.length ?? 0;
    check("SoftwareApplication 含两款报价", offers === 2, `${offers} 条`);
  } catch (error) {
    check("结构化数据可解析", false, error.message);
  }
} else {
  check("结构化数据可解析", false, "未找到 ld+json");
}

const h1Count = (indexHtml.match(/<h1[\s>]/g) ?? []).length;
check("页面只有一个 h1", h1Count === 1, `${h1Count} 个`);

// 标题跨多个内联元素渲染时，模板换行被压缩会粘字（“IDC业务”），这里按真实文本校验
const h1Text =
  indexHtml
    .match(/<h1[^>]*>([\s\S]*?)<\/h1>/)?.[1]
    ?.replace(/<[^>]+>/g, "")
    .replace(/\s+/g, " ")
    .trim() ?? "";
check(
  "h1 文案为「开源 IDC 业务与财务系统」",
  h1Text === "开源 IDC 业务与财务系统",
  h1Text,
);

// Astro 的 sitemap 集成输出 sitemap-index.xml + sitemap-0.xml
const sitemapIndex = read("sitemap-index.xml");
check("生成 sitemap-index.xml", sitemapIndex.includes("<sitemapindex"));
check(
  "sitemap 索引指向分片",
  sitemapIndex.includes("sitemap-0.xml"),
);
const sitemap = read("sitemap-0.xml");
check(
  "sitemap 使用主域绝对地址",
  sitemap.includes("https://www.turaidc.com/"),
);

const robots = read("robots.txt");
check(
  "robots.txt 指向 sitemap 索引",
  robots.includes("Sitemap: https://www.turaidc.com/sitemap-index.xml"),
);

// 首屏不下载任何 JS：组件没有 client 指令，页面里不应出现外部脚本引用
const scriptTags = (indexHtml.match(/<script\b/g) ?? []).length;
check("脚本只有结构化数据与进场逻辑", scriptTags === 2, `${scriptTags} 个 <script>`);
check("HTML 不引用外部脚本", !/<script[^>]+src=/.test(indexHtml));

// @astrojs/vue 会产出一个水合运行时文件；只要页面没有引用它，首屏就不会加载
const jsFiles = walk(dist).filter((file) => extname(file) === ".js");
for (const file of jsFiles) {
  const rel = `/${file.slice(dist.length + 1).replace(/\\/g, "/")}`;
  check(`${rel} 未被页面引用`, !indexHtml.includes(rel));
}

// 站内链接必须落在产物里；锚点与外部链接不在此校验
const internalHrefs = Array.from(
  indexHtml.matchAll(/href="(\/[^"]*)"/g),
  (match) => match[1],
);

const brokenHrefs = internalHrefs.filter((href) => {
  const clean = href.split("#")[0].split("?")[0];
  if (!clean || clean === "/") {
    return false;
  }
  const target = resolve(dist, `.${clean}`);
  return !(existsSync(target) || existsSync(resolve(dist, `.${clean}.html`)));
});
check("站内链接都有落点", brokenHrefs.length === 0, brokenHrefs.join(", "));

const requiredAssets = [
  "favicon.png",
  "apple-touch-icon.png",
  "turaidc-logo.webp",
];
for (const asset of requiredAssets) {
  const target = resolve(dist, asset);
  check(
    `静态资源 ${asset}`,
    existsSync(target) && statSync(target).size > 0,
  );
}

const failed = checks.filter((item) => !item.ok);
for (const item of checks) {
  if (!item.ok) {
    console.error(`✗ ${item.name}${item.detail ? `（${item.detail}）` : ""}`);
  }
}

if (failed.length > 0) {
  console.error(`\n站点自检失败：${failed.length} 项未通过。`);
  process.exit(1);
}

console.log(`站点自检通过：${checks.length} 项。`);
