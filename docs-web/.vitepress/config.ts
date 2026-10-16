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

/**
 * 页面标题取正文中文一级标题，而不是英文文件名。
 *
 * 缺少中文 H1 时直接抛错中断构建：站点面向中文读者，侧栏与导航里出现英文
 * 文件名等同于把内部路径暴露给读者。这个约束是构建期硬门禁，不靠人工复核。
 */
function pageTitle(path: string): string {
  const source = readFileSync(path, "utf8");
  const title = source.match(/^#\s+(.+)$/m)?.[1];

  if (!title || !/\p{Script=Han}/u.test(title)) {
    throw new Error(`文档缺少中文一级标题：${path}`);
  }

  return title.replace(/[`*_]/g, "").trim();
}

function pageLink(relativePath: string): string {
  const path = relativePath.replace(/\\/g, "/").replace(/\.md$/, "");
  const normalized = path
    .replace(/(?:^|\/)README$/, "")
    .replace(/(?:^|\/)index$/, "");

  return encodeURI(`/${normalized}`.replace(/\/$/, "") || "/");
}

function pageItem(relativePath: string): DefaultTheme.SidebarItem {
  return {
    text: pageTitle(resolve(docsRoot, relativePath)),
    link: pageLink(relativePath),
  };
}

function section(
  text: string,
  pages: string[],
  collapsed = false,
): DefaultTheme.SidebarItem {
  return { text, collapsed, items: pages.map(pageItem) };
}

// 文档目录的 README.md 需要重写为 index.md，站点根页（docs/README.md）才落在
// `/` 上。internal/ 虽被 srcExclude 排除，仍一并登记，避免目录重命名后此处
// 静默失配。
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
 */
function assertSidebarCoverage(sidebar: DefaultTheme.SidebarItem[]): void {
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
        // internal/ 是维护者内部记录，由 srcExclude 挡在站点之外
        if (relativePath !== "internal") {
          walk(resolve(directory, entry.name), relativePath);
        }
        continue;
      }

      if (!entry.name.endsWith(".md")) continue;
      // 站点根页由 HomePortal 承接，不参与侧栏
      if (relativeDirectory === "" && /^(readme|index)\.md$/i.test(entry.name)) {
        continue;
      }

      if (!registered.has(pageLink(relativePath))) missing.push(relativePath);
    }
  };
  walk(docsRoot);

  if (missing.length > 0) {
    throw new Error(
      `以下文档未登记到侧栏：\n${missing.map((path) => `  - ${path}`).join("\n")}`,
    );
  }
}

// 使用者文档：面向部署并运营这套系统的站长与运营人员。
// 顺序即阅读顺序：先把站跑起来，再配置，再日常运营，最后才是维护、迁移与排障。
const guideSidebar: DefaultTheme.SidebarItem[] = [
  {
    text: "使用者文档",
    items: [pageItem("guide/index.md")],
  },
  section("部署安装", [
    "guide/deploy/index.md",
    "guide/deploy/generic.md",
    "guide/deploy/bt-panel.md",
    "guide/deploy/bare-metal.md",
    "guide/deploy/docker-1panel.md",
    "guide/deploy/scheduling.md",
  ]),
  section("初始配置", [
    "guide/configure/index.md",
    "guide/configure/site-settings.md",
    "guide/configure/mail-and-sms.md",
    "guide/configure/payment.md",
    "guide/configure/upstream.md",
  ]),
  section("日常运营", [
    "guide/operate/index.md",
    "guide/operate/products.md",
    "guide/operate/orders.md",
    "guide/operate/finance.md",
    "guide/operate/users.md",
    "guide/operate/tickets.md",
    "guide/operate/notifications.md",
  ]),
  section("维护与升级", [
    "guide/maintain/index.md",
    "guide/maintain/upgrade.md",
    "guide/maintain/backup.md",
    "guide/maintain/log-archive.md",
    "guide/maintain/nginx-rules.md",
  ]),
  section("数据迁移", [
    "guide/migrate/index.md",
    "guide/migrate/zjmf-finance.md",
    "guide/migrate/zjmf-finance-experimental.md",
    "guide/migrate/local-idc.md",
  ]),
  {
    text: "故障排查",
    items: [pageItem("guide/troubleshooting.md")],
  },
];

// 开发者文档：面向二次开发、上游对接与仓库贡献者。
const developSidebar: DefaultTheme.SidebarItem[] = [
  {
    text: "开发者文档",
    items: [pageItem("develop/index.md")],
  },
  section("系统概览", ["develop/architecture.md"]),
  section(
    "工程规范",
    [
      "develop/backend.md",
      "develop/frontend.md",
      "develop/design.md",
      "develop/security.md",
      "develop/backend-structure.md",
      "develop/financial-documents.md",
    ],
    false,
  ),
  section(
    "数据库",
    ["develop/database.md", "develop/mysql-compatibility.md"],
    false,
  ),
  section(
    "API",
    [
      "develop/api/index.md",
      "develop/api/format.md",
      "develop/api/catalog-navigation.md",
      "develop/api/backend-api-catalog.md",
    ],
    false,
  ),
  section(
    "插件与集成",
    [
      "develop/plugins/index.md",
      "develop/plugins/package-spec.md",
      "develop/plugins/demo-ali-pay.md",
      "develop/integrations/local-integration.md",
      "develop/integrations/zjmf-upstream-api.md",
    ],
    false,
  ),
  section(
    "开发与测试",
    [
      "develop/local-development.md",
      "develop/testing.md",
      "develop/i18n.md",
      "develop/pitfalls.md",
    ],
    false,
  ),
  section("参与贡献", ["develop/contributing.md"], false),
];

// 两套侧栏合起来必须覆盖全部进站页面
assertSidebarCoverage([...guideSidebar, ...developSidebar]);

export default defineConfig({
  lang: "zh-CN",
  title: "TuraIDC 文档中心",
  description: "TuraIDC 业务/财务系统官方文档：站长部署运营手册与开发者工程文档",
  srcDir: "../docs",
  // 维护者内部工作记录（执行计划、设计、产品规格、修复记录、变更记录与治理规则）
  // 保留在仓库中并继续受 docs:check 校验，但不进入公开站点产物。
  srcExclude: ["internal/**"],
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
  head: [
    ["meta", { name: "theme-color", content: "#165DFF" }],
    ["meta", { name: "mobile-web-app-capable", content: "yes" }],
    ["link", { rel: "icon", href: "/branding/favicon.png" }],
  ],
  themeConfig: {
    // 侧栏与导航顶部仅显示站点标题文字，不渲染 logo 图片
    siteTitle: "TuraIDC文档中心",
    nav: [
      { text: "文档首页", link: "/" },
      { text: "我是使用者", link: "/guide/" },
      { text: "我是开发者", link: "/develop/" },
      { text: "API 参考", link: "/develop/api/backend-api-catalog" },
    ],
    sidebar: {
      "/guide/": guideSidebar,
      "/develop/": developSidebar,
    },
    outline: { level: [2, 3], label: "本页内容" },
    docFooter: { prev: "上一篇", next: "下一篇" },
    lastUpdated: { text: "最后更新于" },
    search: {
      provider: "local",
      options: {
        translations: {
          button: { buttonText: "搜索文档", buttonAriaLabel: "搜索文档" },
        },
      },
    },
    socialLinks: [
      { icon: "github", link: "https://github.com/25Cloud/TuraIDC" },
    ],
    footer: {
      message: "基于 AGPL-3.0-or-later 发布",
      copyright: "Copyright © TuraIDC Contributors",
    },
    // vitepress-carbon 主题文案汉化
    darkModeSwitchLabel: "外观",
    lightModeSwitchTitle: "切换到浅色模式",
    darkModeSwitchTitle: "切换到深色模式",
    sidebarMenuLabel: "菜单",
    returnToTopLabel: "回到顶部",
    langMenuLabel: "切换语言",
  },
  rewrites: readmeRewrites(docsRoot),
});
