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
  "项目动态",
];
for (const snippet of expectText) {
  check(`正文包含「${snippet}」`, visibleText.includes(snippet));
}

// 项目动态：版本行仍是构建期快照（来自 src/data/github.json），数字改成 shields.io 实时徽章。
// 换了 release 却没重新抓数据，这里会先炸。
const github = JSON.parse(
  readFileSync(resolve(root, "src/data/github.json"), "utf8"),
);
const latestTag = github.releases[0]?.tag ?? "";
check("项目动态展示最新版本号", visibleText.includes(latestTag), latestTag);
check(
  "正文出现许可证标识",
  visibleText.includes("AGPL-3.0"),
  github.repo.license,
);
// 旧版把 Star / Fork / issue / 最近提交渲染成数字格子，现在这些数字只在徽章图片里
const oldCells = ["未关闭 issue", "最近提交"].filter((label) =>
  visibleText.includes(label),
);
check(
  "项目动态不再渲染快照数字格子",
  oldCells.length === 0,
  oldCells.join("、"),
);

// 文案不许泄漏「这个页面是怎么做的」：访客只关心项目本身。
// 这类句子（构建期快照、shields.io 缓存几分钟、本站不加载第三方脚本）是内部实现说明，
// 出现在官网正文里既无信息量又像凑字数，历史上真被写进去过一次。
const implementationLeaks = [
  "shields.io",
  "构建时",
  "构建期",
  "缓存几分钟",
  "不加载",
  "现算",
  "出图",
  "contrib.rocks",
  "star-history.com",
].filter((phrase) => visibleText.includes(phrase));
check(
  "正文不出现实现细节文案",
  implementationLeaks.length === 0,
  implementationLeaks.join("、"),
);

// 属性里的 & 会被转义成 &amp;，比对前先还原一遍，否则 URL 永远匹配不上
const html = indexHtml.replace(/&amp;/g, "&");
const imgTags = html.match(/<img\b[^>]*>/g) ?? [];

/**
 * 徽章墙的 11 枚徽章：地址写死在这里，改了 src/data/site.ts 就得同步改这里。
 *
 * 含 CI 徽章：`.github/workflows/ci.yml` 已在 push/PR 上触发且当前为绿；
 * 历史上它只有手动触发、长期停红，那时首页刻意不挂。若再次长期红，
 * 按 site-web-maintenance.md §7.3 撤下并同步这里。
 */
const SHIELD_BADGES = [
  [
    "Release",
    "https://img.shields.io/github/v/release/25Cloud/TuraIDC?style=flat-square&label=Release",
  ],
  [
    "Stars",
    "https://img.shields.io/github/stars/25Cloud/TuraIDC?style=flat-square&label=Stars",
  ],
  [
    "Forks",
    "https://img.shields.io/github/forks/25Cloud/TuraIDC?style=flat-square&label=Forks",
  ],
  [
    "Contributors",
    "https://img.shields.io/github/contributors/25Cloud/TuraIDC?style=flat-square&label=Contributors",
  ],
  [
    "Issues",
    "https://img.shields.io/github/issues/25Cloud/TuraIDC?style=flat-square&label=Issues",
  ],
  [
    "Last Commit",
    "https://img.shields.io/github/last-commit/25Cloud/TuraIDC?style=flat-square&label=Last%20Commit",
  ],
  [
    "License",
    "https://img.shields.io/badge/license-AGPL--3.0--or--later-blue?style=flat-square",
  ],
  [
    "CI",
    "https://github.com/25Cloud/TuraIDC/actions/workflows/ci.yml/badge.svg",
  ],
  [
    "PHP",
    "https://img.shields.io/badge/PHP-%3E%3D8.3-777BB4?style=flat-square",
  ],
  [
    "Laravel",
    "https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square",
  ],
  ["Vue", "https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square"],
];

for (const [name, url] of SHIELD_BADGES) {
  const tag = imgTags.find((item) => item.includes(url)) ?? "";
  check(`实时徽章 ${name} 在页面里`, Boolean(tag), url);
  check(
    `实时徽章 ${name} 带尺寸与降级属性`,
    /width="\d+"/.test(tag) &&
      /height="\d+"/.test(tag) &&
      /loading="lazy"/.test(tag) &&
      /decoding="async"/.test(tag) &&
      /referrerpolicy="no-referrer"/.test(tag),
    tag.slice(0, 120),
  );
}

const shieldCount = imgTags.filter(
  (item) => item.includes("img.shields.io") || item.includes("/badge.svg"),
).length;
check("徽章墙共 11 枚实时徽章", shieldCount === 11, `${shieldCount} 枚`);

// 社区板块：三张第三方图片 + 语言占比 + 交流群
const communityHtml =
  indexHtml.match(/<section id="community"[\s\S]*?<\/section>/)?.[0] ?? "";
const community = communityHtml.replace(/&amp;/g, "&");
check("社区板块已渲染", communityHtml.length > 0);

const STAR_HISTORY_LIGHT =
  "https://api.star-history.com/chart?repos=25Cloud/TuraIDC&type=date&legend=top-left&sealed_token=";
const STAR_HISTORY_DARK =
  "https://api.star-history.com/chart?repos=25Cloud/TuraIDC&type=date&theme=dark&legend=top-left&sealed_token=";
check("社区板块含 star-history 浅色图", community.includes(STAR_HISTORY_LIGHT));
check("社区板块含 star-history 深色图", community.includes(STAR_HISTORY_DARK));
check(
  "star-history 用 prefers-color-scheme 切换深浅",
  /<source[^>]*media="\(prefers-color-scheme: dark\)"/.test(community),
);
check(
  "star-history 外链到 star-history.com",
  community.includes(
    "https://www.star-history.com/?repos=25Cloud%2FTuraIDC&type=date&legend=top-left",
  ),
);
check(
  "社区板块含 contrib.rocks 头像墙",
  community.includes("https://contrib.rocks/image?repo=25Cloud/TuraIDC"),
);
check(
  "贡献者头像墙链接到贡献者列表",
  community.includes("https://github.com/25Cloud/TuraIDC/graphs/contributors"),
);

const languageSlices = (community.match(/class="slice"/g) ?? []).length;
check(
  "语言占比条至少渲染 3 种语言",
  languageSlices >= 3,
  `${languageSlices} 段`,
);
const languageRows = (community.match(/class="lang-percent"/g) ?? []).length;
check("语言列表至少渲染 3 行占比", languageRows >= 3, `${languageRows} 行`);
check(
  "语言占比条带颜色",
  /class="slice"[^>]*style="[^"]*background:/.test(community),
);

check("社区板块展示 QQ 群号", visibleText.includes("1105174267"));
check(
  "社区板块给出加群链接",
  community.includes("https://qm.qq.com/q/Doa31p5M30"),
);
check(
  "二维码用站内图片",
  /<img[^>]*src="\/images\/qq-group\.jpg"/.test(community) &&
    !community.includes("raw.githubusercontent.com"),
);

// 社区板块同样零客户端 JS：图片就是图片，没有脚本、没有内联事件
check(
  "社区板块不含脚本与内联事件",
  !/<script/i.test(communityHtml) &&
    !/<iframe/i.test(communityHtml) &&
    !/\son[a-z]+=/i.test(communityHtml),
);

for (const [label, needle] of [
  ["star-history", "api.star-history.com/chart"],
  ["contrib.rocks", "contrib.rocks/image"],
  ["QQ 群二维码", "images/qq-group.jpg"],
]) {
  const tag = imgTags.find((item) => item.includes(needle)) ?? "";
  check(
    `社区图片 ${label} 带尺寸与降级属性`,
    /width="\d+"/.test(tag) &&
      /height="\d+"/.test(tag) &&
      /loading="lazy"/.test(tag) &&
      /decoding="async"/.test(tag) &&
      /referrerpolicy="no-referrer"/.test(tag),
    tag.slice(0, 120),
  );
}

// 实拍图必须带宽高，否则首屏图片加载时整块布局会跳
for (const name of ["catalog"]) {
  const tag =
    indexHtml.match(new RegExp(`<img[^>]*${name}\\.webp[^>]*>`))?.[0] ?? "";
  check(`实拍图 ${name} 有 alt`, /alt="[^"]+"/.test(tag));
  check(
    `实拍图 ${name} 声明宽高`,
    /width="1600"/.test(tag) && /height="1000"/.test(tag),
    tag.slice(0, 80),
  );
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
  "shots/catalog.webp",
  "images/qq-group.jpg",
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
