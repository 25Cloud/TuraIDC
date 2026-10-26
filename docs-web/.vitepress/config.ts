import { readFileSync, readdirSync } from "node:fs";
import { createRequire } from "node:module";
import { resolve } from "node:path";
import { defineConfig, type DefaultTheme, type Plugin } from "vitepress";

// vitepress 1.6.4 内部把运行时拆为 framework/theme 两个 chunk 并在其 output
// 展开顺序中覆盖用户 manualChunks；theme 分组包含被入口静态引用的全局注册
// 组件（如增强阅读面板），与 framework 形成跨 chunk 循环，水合时序下
// framework 读取到 theme 尚未赋值的导出（undefined.shallowRef 崩溃）。
// vite 的 mergeConfig 会跳过 undefined 值，因此用"恒返回 undefined 的
// 函数"覆盖内部分块，让运行时全部进入单一 entry chunk，消除循环。
function flattenManualChunks(): Plugin {
  return {
    name: "docs-web:flatten-manual-chunks",
    apply: "build",
    config() {
      return {
        build: {
          rollupOptions: {
            output: {
              manualChunks: () => undefined,
            },
          },
        },
      };
    },
  };
}

const docsRoot = resolve(import.meta.dirname, "../../docs");

// 文档源位于 vitepress 包外（../docs），SSR 编译产物会从 docs/ 目录发起
// import "vue/server-renderer"，而该目录向上无法解析 vue。将 vue 系列
// 显式解析到 docs-web 自身依赖，保证与 SSR external 的 @vue/server-renderer
// 为同一副本，避免双实例。
const require = createRequire(import.meta.url);
const vueAlias = [
  {
    find: "vue/server-renderer",
    replacement: require.resolve("vue/server-renderer"),
  },
  { find: "vue", replacement: require.resolve("vue") },
];

type LocaleId = "zh-CN" | "en" | "zh-TW" | "zh-HK";
type LocalizedText = Record<LocaleId, string>;

interface LocaleDefinition {
  id: LocaleId;
  /** 站点内的目录前缀；中文占根路径，所以为空串 */
  dir: string;
  label: string;
  htmlLang: string;
  title: string;
  description: string;
}

const LOCALES: LocaleDefinition[] = [
  {
    id: "zh-CN",
    dir: "",
    label: "简体中文",
    htmlLang: "zh-CN",
    title: "TuraIDC 文档中心",
    description:
      "TuraIDC 业务/财务系统官方文档：站长部署运营手册与开发者工程文档",
  },
  {
    id: "en",
    dir: "en",
    label: "English",
    htmlLang: "en",
    title: "TuraIDC Documentation",
    description:
      "Official TuraIDC docs: deployment and operations for site owners, engineering guides for developers.",
  },
  {
    id: "zh-TW",
    dir: "zh-tw",
    label: "繁體中文（台灣）",
    htmlLang: "zh-TW",
    title: "TuraIDC 文件中心",
    description:
      "TuraIDC 業務／財務系統官方文件：站長部署營運手冊與開發者工程文件",
  },
  {
    id: "zh-HK",
    dir: "zh-hk",
    label: "繁體中文（香港）",
    htmlLang: "zh-HK",
    title: "TuraIDC 文件中心",
    description:
      "TuraIDC 業務／財務系統官方文件：站長部署營運手冊與開發者工程文件",
  },
];

const LOCALE_DIRS = new Set(
  LOCALES.map((locale) => locale.dir).filter((dir) => dir !== ""),
);

/** 由脚本生成、不进译文镜像的中文文档：非中文站点在侧栏里直接指向中文页。 */
const GENERATED_PAGES: Record<string, LocalizedText> = {
  "develop/database.md": {
    "zh-CN": "数据库结构快照",
    en: "Database snapshot (Chinese)",
    "zh-TW": "資料庫結構快照（中文）",
    "zh-HK": "數據庫結構快照（中文）",
  },
  "develop/api/backend-api-catalog.md": {
    "zh-CN": "后端 API 清单",
    en: "Backend API catalog (Chinese)",
    "zh-TW": "後端 API 清單（中文）",
    "zh-HK": "後端 API 清單（中文）",
  },
};

function localeRoot(locale: LocaleId): string {
  const definition = LOCALES.find((entry) => entry.id === locale);
  if (!definition) {
    throw new Error(`未知语言：${locale}`);
  }

  return definition.dir ? resolve(docsRoot, definition.dir) : docsRoot;
}

/**
 * 页面标题取正文一级标题，而不是英文文件名。
 *
 * 中文/繁体站点缺汉字标题、英文站点标题里仍带汉字，都在构建期直接抛错。前者防止
 * 侧栏出现英文文件名，后者防止漏译页面悄悄上线——这个约束不靠人工复核。
 */
function pageTitle(locale: LocaleId, relativePath: string): string {
  const source = readFileSync(
    resolve(localeRoot(locale), relativePath),
    "utf8",
  );
  const title = source.match(/^#\s+(.+)$/m)?.[1];
  const hasHan = title ? /\p{Script=Han}/u.test(title) : false;

  if (!title) {
    throw new Error(`文档缺少一级标题：${relativePath}（${locale}）`);
  }

  if (locale === "en") {
    if (hasHan) {
      throw new Error(`英文页面标题未翻译：${relativePath}`);
    }
  } else if (!hasHan) {
    throw new Error(`文档缺少中文一级标题：${relativePath}（${locale}）`);
  }

  return title.replace(/[`*_]/g, "").trim();
}

function pageLink(locale: LocaleId, relativePath: string): string {
  const definition = LOCALES.find((entry) => entry.id === locale)!;
  const prefix = definition.dir ? `/${definition.dir}` : "";
  const path = relativePath.replace(/\\/g, "/").replace(/\.md$/, "");
  const normalized = path
    .replace(/(?:^|\/)README$/, "")
    .replace(/(?:^|\/)index$/, "");

  return encodeURI(`${prefix}/${normalized}`.replace(/\/$/, "") || "/");
}

function pageItem(
  locale: LocaleId,
  relativePath: string,
): DefaultTheme.SidebarItem {
  return {
    text: pageTitle(locale, relativePath),
    link: pageLink(locale, relativePath),
  };
}

function generatedItem(
  locale: LocaleId,
  relativePath: string,
): DefaultTheme.SidebarItem {
  return {
    text: GENERATED_PAGES[relativePath][locale],
    // 生成物只有中文版，非中文站点跨回中文原件
    link: `/${relativePath.replace(/\.md$/, "")}`,
  };
}

interface SidebarSection {
  text: LocalizedText;
  pages: string[];
  collapsed?: boolean;
}

interface SidebarBlueprint {
  lead: string;
  sections: SidebarSection[];
}

function buildSidebar(
  locale: LocaleId,
  blueprint: SidebarBlueprint,
): DefaultTheme.SidebarItem[] {
  const sectionItems = blueprint.sections.map((section) => ({
    text: section.text[locale],
    collapsed: section.collapsed ?? false,
    items: section.pages.map((page) =>
      GENERATED_PAGES[page]
        ? generatedItem(locale, page)
        : pageItem(locale, page),
    ),
  }));

  return [
    {
      text: pageTitle(locale, blueprint.lead),
      items: [pageItem(locale, blueprint.lead)],
    },
    ...sectionItems,
  ];
}

// 文档目录的 README.md 需要重写为 index.md，站点根页（docs/README.md）才落在
// `/` 上。internal/ 虽被 srcExclude 排除，仍一并登记，避免目录重命名后此处
// 静默失配；语言子树同样递归覆盖（docs/en/README.md → en/index.md）。
function readmeRewrites(
  directory: string,
  relativeDirectory = "",
): Record<string, string> {
  return readdirSync(directory, { withFileTypes: true }).reduce<
    Record<string, string>
  >((rewrites, entry) => {
    const fullPath = resolve(directory, entry.name);
    const relativePath = relativeDirectory
      ? `${relativeDirectory}/${entry.name}`
      : entry.name;

    if (entry.isDirectory()) {
      return { ...rewrites, ...readmeRewrites(fullPath, relativePath) };
    }

    if (entry.isFile() && entry.name === "README.md") {
      rewrites[relativePath] = relativePath.replace(/README\.md$/, "index.md");
    }

    return rewrites;
  }, {});
}

/**
 * 站上每个页面都必须在侧栏里出现。
 *
 * 侧栏是显式维护的（顺序即阅读顺序），代价是新增页面容易忘记登记——那种页面只
 * 能靠直链访问，等于从导航里消失。这里在配置加载阶段做覆盖校验，让遗漏当场
 * 失败，而不是等读者发现找不到入口。
 *
 * 根语言扫描 docs/ 时要跳过两处：internal/（不上站）与各语言子树（由各自侧栏
 * 负责），否则每加一种语言都会把根语言侧栏判成漏登记。
 */
function assertSidebarCoverage(
  locale: LocaleId,
  sidebar: DefaultTheme.SidebarItem[],
): void {
  const registered = new Set<string>();
  const collect = (items: DefaultTheme.SidebarItem[]) => {
    for (const item of items) {
      if (item.link) registered.add(item.link);
      if (item.items) collect(item.items);
    }
  };
  collect(sidebar);

  const missing: string[] = [];
  const walk = (directory: string, relativeDirectory = "") => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      if (entry.name.startsWith(".")) continue;
      const relativePath = relativeDirectory
        ? `${relativeDirectory}/${entry.name}`
        : entry.name;

      if (entry.isDirectory()) {
        const skip =
          relativeDirectory === "" &&
          (relativePath === "internal" || LOCALE_DIRS.has(relativePath));
        if (!skip) {
          walk(resolve(directory, entry.name), relativePath);
        }
        continue;
      }

      if (!entry.name.endsWith(".md")) continue;
      // 各语言首页由 HomePortal 承接，不参与侧栏
      if (
        relativeDirectory === "" &&
        /^(readme|index)\.md$/i.test(entry.name)
      ) {
        continue;
      }

      if (!registered.has(pageLink(locale, relativePath)))
        missing.push(relativePath);
    }
  };
  walk(localeRoot(locale));

  if (missing.length > 0) {
    throw new Error(
      `以下文档未登记到侧栏（${locale}）：\n${missing.map((path) => `  - ${path}`).join("\n")}`,
    );
  }
}

// 侧栏顺序即阅读顺序：先把站跑起来，再配置，再日常运营，最后才是维护、迁移与排障。
const guideBlueprint: SidebarBlueprint = {
  lead: "guide/index.md",
  sections: [
    {
      text: {
        "zh-CN": "部署安装",
        en: "Installation",
        "zh-TW": "部署安裝",
        "zh-HK": "部署安裝",
      },
      pages: [
        "guide/deploy/index.md",
        "guide/deploy/generic.md",
        "guide/deploy/bt-panel.md",
        "guide/deploy/bare-metal.md",
        "guide/deploy/docker-1panel.md",
        "guide/deploy/scheduling.md",
      ],
    },
    {
      text: {
        "zh-CN": "初始配置",
        en: "Initial setup",
        "zh-TW": "初始設定",
        "zh-HK": "初始設定",
      },
      pages: [
        "guide/configure/index.md",
        "guide/configure/site-settings.md",
        "guide/configure/mail-and-sms.md",
        "guide/configure/payment.md",
        "guide/configure/upstream.md",
        "guide/configure/rainyun-rcs.md",
      ],
    },
    {
      text: {
        "zh-CN": "日常运营",
        en: "Daily operations",
        "zh-TW": "日常營運",
        "zh-HK": "日常營運",
      },
      pages: [
        "guide/operate/index.md",
        "guide/operate/products.md",
        "guide/operate/orders.md",
        "guide/operate/finance.md",
        "guide/operate/users.md",
        "guide/operate/tickets.md",
        "guide/operate/notifications.md",
      ],
    },
    {
      text: {
        "zh-CN": "维护与升级",
        en: "Maintenance and upgrades",
        "zh-TW": "維護與升級",
        "zh-HK": "維護與升級",
      },
      pages: [
        "guide/maintain/index.md",
        "guide/maintain/upgrade.md",
        "guide/maintain/backup.md",
        "guide/maintain/log-archive.md",
        "guide/maintain/nginx-rules.md",
      ],
    },
    {
      text: {
        "zh-CN": "数据迁移",
        en: "Data migration",
        "zh-TW": "資料移轉",
        "zh-HK": "數據遷移",
      },
      pages: [
        "guide/migrate/index.md",
        "guide/migrate/zjmf-finance.md",
        "guide/migrate/zjmf-finance-experimental.md",
        "guide/migrate/local-idc.md",
      ],
    },
    {
      text: {
        "zh-CN": "开源与商业许可",
        en: "Licensing",
        "zh-TW": "開源與商業授權",
        "zh-HK": "開源與商業授權",
      },
      pages: ["guide/licensing.md"],
    },
    {
      text: {
        "zh-CN": "故障排查",
        en: "Troubleshooting",
        "zh-TW": "疑難排解",
        "zh-HK": "疑難排解",
      },
      pages: ["guide/troubleshooting.md"],
    },
  ],
};

const developBlueprint: SidebarBlueprint = {
  lead: "develop/index.md",
  sections: [
    {
      text: {
        "zh-CN": "系统概览",
        en: "System overview",
        "zh-TW": "系統概覽",
        "zh-HK": "系統概覽",
      },
      pages: ["develop/architecture.md"],
    },
    {
      text: {
        "zh-CN": "工程规范",
        en: "Engineering standards",
        "zh-TW": "工程規範",
        "zh-HK": "工程規範",
      },
      pages: [
        "develop/backend.md",
        "develop/frontend.md",
        "develop/design.md",
        "develop/security.md",
        "develop/backend-structure.md",
        "develop/financial-documents.md",
      ],
    },
    {
      text: {
        "zh-CN": "数据库",
        en: "Database",
        "zh-TW": "資料庫",
        "zh-HK": "數據庫",
      },
      pages: ["develop/database.md", "develop/mysql-compatibility.md"],
    },
    {
      text: { "zh-CN": "API", en: "API", "zh-TW": "API", "zh-HK": "API" },
      pages: [
        "develop/api/index.md",
        "develop/api/format.md",
        "develop/api/catalog-navigation.md",
        "develop/api/backend-api-catalog.md",
      ],
    },
    {
      text: {
        "zh-CN": "插件与集成",
        en: "Plugins and integrations",
        "zh-TW": "外掛與整合",
        "zh-HK": "插件與集成",
      },
      pages: [
        "develop/plugins/index.md",
        "develop/plugins/package-spec.md",
        "develop/plugins/demo-ali-pay.md",
        "develop/integrations/local-integration.md",
        "develop/integrations/zjmf-upstream-api.md",
      ],
    },
    {
      text: {
        "zh-CN": "开发与测试",
        en: "Development and testing",
        "zh-TW": "開發與測試",
        "zh-HK": "開發與測試",
      },
      pages: [
        "develop/local-development.md",
        "develop/testing.md",
        "develop/i18n.md",
        "develop/pitfalls.md",
      ],
    },
    {
      text: {
        "zh-CN": "参与贡献",
        en: "Contributing",
        "zh-TW": "參與貢獻",
        "zh-HK": "參與貢獻",
      },
      pages: ["develop/contributing.md"],
    },
  ],
};

interface ChromeCopy {
  navHome: string;
  navGuide: string;
  navDevelop: string;
  navApi: string;
  siteTitle: string;
  outlineLabel: string;
  prev: string;
  next: string;
  lastUpdated: string;
  searchButton: string;
  footerMessage: string;
  darkModeLabel: string;
  lightModeTitle: string;
  darkModeTitle: string;
  menuLabel: string;
  backToTop: string;
  langMenuLabel: string;
}

const CHROME: Record<LocaleId, ChromeCopy> = {
  "zh-CN": {
    navHome: "文档首页",
    navGuide: "我是使用者",
    navDevelop: "我是开发者",
    navApi: "API 参考",
    siteTitle: "TuraIDC文档中心",
    outlineLabel: "本页内容",
    prev: "上一篇",
    next: "下一篇",
    lastUpdated: "最后更新于",
    searchButton: "搜索文档",
    footerMessage: "基于 AGPL-3.0-or-later 发布",
    darkModeLabel: "外观",
    lightModeTitle: "切换到浅色模式",
    darkModeTitle: "切换到深色模式",
    menuLabel: "菜单",
    backToTop: "回到顶部",
    langMenuLabel: "切换语言",
  },
  en: {
    navHome: "Home",
    navGuide: "For operators",
    navDevelop: "For developers",
    navApi: "API reference",
    siteTitle: "TuraIDC Docs",
    outlineLabel: "On this page",
    prev: "Previous",
    next: "Next",
    lastUpdated: "Last updated",
    searchButton: "Search documentation",
    footerMessage: "Released under AGPL-3.0-or-later",
    darkModeLabel: "Appearance",
    lightModeTitle: "Switch to light mode",
    darkModeTitle: "Switch to dark mode",
    menuLabel: "Menu",
    backToTop: "Back to top",
    langMenuLabel: "Change language",
  },
  "zh-TW": {
    navHome: "文件首頁",
    navGuide: "我是使用者",
    navDevelop: "我是開發者",
    navApi: "API 參考",
    siteTitle: "TuraIDC文件中心",
    outlineLabel: "本頁內容",
    prev: "上一篇",
    next: "下一篇",
    lastUpdated: "最後更新於",
    searchButton: "搜尋文件",
    footerMessage: "依 AGPL-3.0-or-later 發布",
    darkModeLabel: "外觀",
    lightModeTitle: "切換到淺色模式",
    darkModeTitle: "切換到深色模式",
    menuLabel: "選單",
    backToTop: "回到頂端",
    langMenuLabel: "切換語言",
  },
  "zh-HK": {
    navHome: "文件首頁",
    navGuide: "我是用戶",
    navDevelop: "我是開發者",
    navApi: "API 參考",
    siteTitle: "TuraIDC文件中心",
    outlineLabel: "本頁內容",
    prev: "上一篇",
    next: "下一篇",
    lastUpdated: "最後更新於",
    searchButton: "搜尋文件",
    footerMessage: "依 AGPL-3.0-or-later 發布",
    darkModeLabel: "外觀",
    lightModeTitle: "切換到淺色模式",
    darkModeTitle: "切換到深色模式",
    menuLabel: "選單",
    backToTop: "返回頂部",
    langMenuLabel: "切換語言",
  },
};

function themeConfigFor(locale: LocaleId) {
  const chrome = CHROME[locale];
  const definition = LOCALES.find((entry) => entry.id === locale)!;
  const prefix = definition.dir ? `/${definition.dir}` : "";

  return {
    siteTitle: chrome.siteTitle,
    nav: [
      { text: chrome.navHome, link: `${prefix}/` },
      { text: chrome.navGuide, link: `${prefix}/guide/` },
      { text: chrome.navDevelop, link: `${prefix}/develop/` },
      {
        text: chrome.navApi,
        link: `${prefix}/develop/api/backend-api-catalog`,
      },
    ],
    sidebar: {
      [`${prefix}/guide/`]: buildSidebar(locale, guideBlueprint),
      [`${prefix}/develop/`]: buildSidebar(locale, developBlueprint),
    },
    socialLinks: [
      { icon: "github", link: "https://github.com/25Cloud/TuraIDC" },
    ],
    outline: { level: [2, 3] as [number, number], label: chrome.outlineLabel },
    docFooter: { prev: chrome.prev, next: chrome.next },
    lastUpdated: { text: chrome.lastUpdated },
    search: {
      provider: "local" as const,
      options: {
        translations: {
          button: {
            buttonText: chrome.searchButton,
            buttonAriaLabel: chrome.searchButton,
          },
        },
      },
    },
    footer: {
      message: chrome.footerMessage,
      copyright: "Copyright © TuraIDC Contributors",
    },
    darkModeSwitchLabel: chrome.darkModeLabel,
    lightModeSwitchTitle: chrome.lightModeTitle,
    darkModeSwitchTitle: chrome.darkModeTitle,
    sidebarMenuLabel: chrome.menuLabel,
    returnToTopLabel: chrome.backToTop,
    langMenuLabel: chrome.langMenuLabel,
  };
}

// 每种语言的自侧栏必须覆盖该语言子树下全部页面
for (const locale of LOCALES) {
  const prefix = locale.dir ? `/${locale.dir}` : "";
  assertSidebarCoverage(locale.id, [
    ...buildSidebar(locale.id, guideBlueprint),
    ...buildSidebar(locale.id, developBlueprint),
  ]);
}

/**
 * 页面在其余语言里的地址。
 *
 * 镜像目录结构让这件事变成纯字符串操作：去掉当前语言前缀，再分别拼上各语言前缀。
 * `x-default` 指向简体中文，供搜索引擎在无匹配时回落。
 */
function alternateLinks(relativePath: string) {
  const withoutLocale = relativePath.replace(/^(?:en|zh-tw|zh-hk)\//, "");
  const canonical = withoutLocale
    .replace(/(^|\/)index\.md$/, "$1")
    .replace(/\.md$/, "");

  return LOCALES.flatMap((locale) => {
    const base = locale.dir ? `/${locale.dir}` : "";
    const href = canonical ? `${base}/${canonical}` : `${base || ""}/`;

    return [
      ["link", { rel: "alternate", hreflang: locale.htmlLang, href }],
    ] as const;
  }).concat([
    [
      "link",
      {
        rel: "alternate",
        hreflang: "x-default",
        href: canonical ? `/${canonical}` : "/",
      },
    ],
  ]);
}

export default defineConfig({
  lang: "zh-CN",
  title: "TuraIDC 文档中心",
  description: LOCALES[0].description,
  srcDir: "../docs",
  // 维护者内部工作记录（执行计划、设计、产品规格、修复记录、变更记录与治理规则）
  // 保留在仓库中并继续受 docs:check 校验，但不进入公开站点产物。
  srcExclude: ["internal/**"],
  locales: Object.fromEntries(
    LOCALES.map((locale) => [
      locale.dir === "" ? "root" : locale.dir,
      {
        label: locale.label,
        lang: locale.htmlLang,
        title: locale.title,
        description: locale.description,
        link: locale.dir ? `/${locale.dir}/` : "/",
        themeConfig: themeConfigFor(locale.id),
      },
    ]),
  ),
  vite: {
    publicDir: resolve(import.meta.dirname, "../public"),
    plugins: [flattenManualChunks()],
    resolve: {
      alias: vueAlias,
    },
    ssr: {
      // 避免 @vue/server-renderer 作为 external 在运行时 require 到另一份 vue
      noExternal: ["@vue/server-renderer"],
    },
  },
  cleanUrls: true,
  lastUpdated: true,
  ignoreDeadLinks: true,
  // 本地搜索索引由 VitePress 在构建期读**顶层** themeConfig.search 决定是否生成；
  // 各语言的搜索按钮文案在 locales[*].themeConfig.search 里覆盖。把 search 只写在
  // 语言级配置里，索引会静默不生成（构建成功但站内搜不到东西）。
  themeConfig: {
    search: {
      provider: "local",
      options: {
        translations: {
          button: { buttonText: "搜索文档", buttonAriaLabel: "搜索文档" },
        },
      },
    },
  },
  sitemap: {
    hostname: "https://docs.turaidc.com",
  },
  transformHead({ pageData }) {
    return alternateLinks(pageData.relativePath);
  },
  head: [
    ["meta", { name: "theme-color", content: "#165DFF" }],
    ["meta", { name: "mobile-web-app-capable", content: "yes" }],
    ["link", { rel: "icon", href: "/branding/favicon.png" }],
  ],
  rewrites: readmeRewrites(docsRoot),
});
