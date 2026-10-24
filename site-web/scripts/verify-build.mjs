#!/usr/bin/env node
/**
 * 站点产物自检：EdgeOne 构建命令里跟在 astro build 之后执行。
 *
 * 只校验「构建坏了会静默失效」的东西：每种语言的正文是否真的进了 HTML、
 * SEO 头部与 hreflang 是否齐全、结构化数据能否解析、sitemap/robots 是否生成、
 * 站内链接是否还有落点、首屏有没有偷偷带上 JS 运行时。
 * 样式、动效与主题不在这里判断，靠人工过页面。
 */
import { existsSync, readFileSync, readdirSync, statSync } from "node:fs";
import { dirname, extname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const dist = resolve(root, "dist");

const PAGES = [
  {
    file: "index.html",
    lang: "zh-CN",
    canonical: "https://www.turaidc.com/",
  },
  {
    file: "zh-tw/index.html",
    lang: "zh-TW",
    canonical: "https://www.turaidc.com/zh-tw/",
  },
  {
    file: "zh-hk/index.html",
    lang: "zh-HK",
    canonical: "https://www.turaidc.com/zh-hk/",
  },
  {
    file: "en/index.html",
    lang: "en",
    canonical: "https://www.turaidc.com/en/",
  },
];

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
  "合作伙伴",
  "在线演示",
  "从智简魔方财务迁入",
  "这套系统免费吗",
  "AGPL-3.0-or-later",
];
for (const snippet of expectText) {
  check(`正文包含「${snippet}」`, visibleText.includes(snippet));
}

check("演示站链接指向二五云", indexHtml.includes("https://idc.25y.cn/"));

for (const page of PAGES) {
  const html = read(page.file);
  check(`${page.file} 存在`, html.length > 0);

  const htmlTag = html.match(/<html[^>]*lang="([^"]*)"/)?.[1] ?? "";
  check(`${page.file} 的 html lang`, htmlTag === page.lang, htmlTag);

  const title = html.match(/<title>([^<]*)<\/title>/)?.[1] ?? "";
  check(`${page.file} title 含 TuraIDC`, title.includes("TuraIDC"), title);

  const description =
    html.match(/<meta name="description" content="([^"]*)"/)?.[1] ?? "";
  check(
    `${page.file} description 长度 60-220`,
    description.length >= 60 && description.length <= 220,
    `${description.length} 字`,
  );

  const canonical =
    html.match(/<link rel="canonical" href="([^"]*)"/)?.[1] ?? "";
  check(`${page.file} canonical`, canonical === page.canonical, canonical);

  const hreflangs = Array.from(
    html.matchAll(/<link rel="alternate" hreflang="([^"]*)"/g),
    (match) => match[1],
  );
  check(
    `${page.file} hreflang 覆盖 4 语言 + x-default`,
    hreflangs.length === 5,
    hreflangs.join(", "),
  );

  check(`${page.file} 未被 noindex`, !html.includes("noindex"));

  const h1Text =
    html
      .match(/<h1[^>]*>([\s\S]*?)<\/h1>/)?.[1]
      ?.replace(/<[^>]+>/g, "")
      .replace(/\s+/g, " ")
      .trim() ?? "";
  check(`${page.file} h1 含 IDC`, h1Text.includes("IDC"), h1Text);
  check(
    `${page.file} 只有一个 h1`,
    (html.match(/<h1[\s>]/g) ?? []).length === 1,
  );

  const ldMatch = html.match(
    /<script type="application\/ld\+json">([\s\S]*?)<\/script>/,
  );
  if (!ldMatch) {
    check(`${page.file} 结构化数据`, false, "未找到 ld+json");
  } else {
    try {
      const parsed = JSON.parse(ldMatch[1]);
      const graph = parsed["@graph"] ?? [parsed];
      const types = graph.map((node) => node["@type"]);
      check(
        `${page.file} 结构化数据类型`,
        types.includes("Organization") &&
          types.includes("SoftwareApplication") &&
          types.includes("FAQPage"),
        types.join(", "),
      );

      const faq = graph.find((node) => node["@type"] === "FAQPage");
      check(
        `${page.file} FAQ 条目 >= 5`,
        (faq?.mainEntity?.length ?? 0) >= 5,
        `${faq?.mainEntity?.length ?? 0} 条`,
      );
      check(
        `${page.file} 结构化数据语言`,
        faq?.inLanguage === page.lang,
        String(faq?.inLanguage),
      );

      const app = graph.find((node) => node["@type"] === "SoftwareApplication");
      check(
        `${page.file} 两款报价`,
        (app?.offers?.length ?? 0) === 2,
        `${app?.offers?.length ?? 0} 条`,
      );
    } catch (error) {
      check(`${page.file} 结构化数据可解析`, false, error.message);
    }
  }
}

// Astro 的 sitemap 集成输出 sitemap-index.xml + sitemap-0.xml
const sitemapIndex = read("sitemap-index.xml");
check("生成 sitemap-index.xml", sitemapIndex.includes("<sitemapindex"));
check("sitemap 索引指向分片", sitemapIndex.includes("sitemap-0.xml"));

const sitemap = read("sitemap-0.xml");
const sitemapUrls = Array.from(sitemap.matchAll(/<loc>([^<]*)<\/loc>/g)).map(
  (match) => match[1],
);
check(
  "sitemap 收录 4 个语言页",
  sitemapUrls.length === 4,
  sitemapUrls.join(", "),
);
for (const page of PAGES) {
  check(
    `sitemap 含 ${page.canonical}`,
    sitemapUrls.some((url) => url === page.canonical),
  );
}

const robots = read("robots.txt");
check(
  "robots.txt 指向 sitemap 索引",
  robots.includes("Sitemap: https://www.turaidc.com/sitemap-index.xml"),
);

// 首屏不下载任何 JS：组件没有 client 指令，页面里不应出现外部脚本引用
const scriptTags = (indexHtml.match(/<script\b/g) ?? []).length;
check("脚本只有主题/结构化数据/进场逻辑", scriptTags === 3, `${scriptTags} 个`);
check("HTML 不引用外部脚本", !/<script[^>]+src=/.test(indexHtml));

// @astrojs/vue 会产出一个水合运行时文件；只要页面没有引用它，首屏就不会加载
const jsFiles = walk(dist).filter((file) => extname(file) === ".js");
for (const file of jsFiles) {
  const rel = `/${file.slice(dist.length + 1).replace(/\\/g, "/")}`;
  check(`${rel} 未被页面引用`, !indexHtml.includes(rel));
}

// 站内链接必须落在产物里；锚点与外部链接不在此校验
const brokenHrefs = [];
for (const page of PAGES) {
  const html = read(page.file);
  const hrefs = Array.from(
    html.matchAll(/href="(\/[^"]*)"/g),
    (match) => match[1],
  );
  for (const href of hrefs) {
    const clean = href.split("#")[0].split("?")[0];
    if (!clean || clean === "/") {
      continue;
    }
    const target = resolve(dist, `.${clean}`);
    const exists =
      existsSync(target) ||
      existsSync(resolve(dist, `.${clean}.html`)) ||
      existsSync(join(target, "index.html"));
    if (!exists) {
      brokenHrefs.push(`${page.file} → ${href}`);
    }
  }
}
check("站内链接都有落点", brokenHrefs.length === 0, brokenHrefs.join(", "));

const requiredAssets = [
  "favicon.png",
  "apple-touch-icon.png",
  "turaidc-logo.webp",
  "partners/7inet.svg",
  "partners/starvm.webp",
  "partners/25yun.webp",
  "partners/xianlin.webp",
  "partners/shanshui.webp",
];
for (const asset of requiredAssets) {
  const target = resolve(dist, asset);
  check(`静态资源 ${asset}`, existsSync(target) && statSync(target).size > 0);
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
