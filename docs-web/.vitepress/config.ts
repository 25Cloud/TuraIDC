import { readFileSync, readdirSync } from "node:fs";
import { createRequire } from "node:module";
import { resolve } from "node:path";
import { defineConfig, type DefaultTheme } from "vitepress";

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

const directoryTitles: Record<string, string> = {
  "product-specs/active": "进行中",
  "designs/architecture": "架构",
  "designs/backend": "后端",
  "designs/product": "产品",
  "references/integrations/plugins": "插件",
  "generated/api": "API",
  "execution-plans/active": "进行中",
  "execution-plans/completed": "已完成",
  "execution-plans/tech-debt": "技术债",
};

function pageTitle(path: string): string {
  const source = readFileSync(path, "utf8");
  const title = source.match(/^#\s+(.+)$/m)?.[1];

  if (!title || !/\p{Script=Han}/u.test(title)) {
    throw new Error(`文档缺少中文一级标题：${path}`);
  }

  return title.replace(/[`*_]/g, "").trim();
}

function directoryTitle(relativePath: string): string {
  const title = directoryTitles[relativePath];

  if (!title) {
    throw new Error(`文档目录缺少中文侧栏标题：${relativePath}`);
  }

  return title;
}

function pageLink(relativePath: string): string {
  const path = relativePath.replace(/\\/g, "/").replace(/\.md$/, "");
  const normalized = path
    .replace(/(?:^|\/)README$/, "")
    .replace(/(?:^|\/)index$/, "");

  return encodeURI(`/${normalized}`.replace(/\/$/, "") || "/");
}

function directoryItems(
  directory: string,
  relativeDirectory = "",
): DefaultTheme.SidebarItem[] {
  return readdirSync(directory, { withFileTypes: true })
    .filter((entry) => !entry.name.startsWith("."))
    .sort((left, right) => {
      const leftIndex = left.name === "README.md" || left.name === "index.md";
      const rightIndex =
        right.name === "README.md" || right.name === "index.md";

      if (leftIndex !== rightIndex) return leftIndex ? -1 : 1;
      if (left.isDirectory() !== right.isDirectory())
        return left.isDirectory() ? -1 : 1;

      return left.name.localeCompare(right.name, "zh-CN");
    })
    .flatMap((entry): DefaultTheme.SidebarItem[] => {
      const fullPath = resolve(directory, entry.name);
      const relativePath = relativeDirectory
        ? `${relativeDirectory}/${entry.name}`
        : entry.name;

      if (entry.isDirectory()) {
        return [
          {
            text: directoryTitle(relativePath),
            collapsed: relativeDirectory !== "",
            items: directoryItems(fullPath, relativePath),
          },
        ];
      }

      if (!entry.isFile() || !entry.name.endsWith(".md")) return [];

      return [{ text: pageTitle(fullPath), link: pageLink(relativePath) }];
    });
}

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

function pageItem(relativePath: string): DefaultTheme.SidebarItem {
  return {
    text: pageTitle(resolve(docsRoot, relativePath)),
    link: pageLink(relativePath),
  };
}

const sidebar: DefaultTheme.SidebarItem[] = [
  {
    text: "快速开始",
    items: [
      pageItem("quick-start.md"),
      pageItem("references/operations/deployment.md"),
      pageItem("references/operations/bt-panel-deployment.md"),
      pageItem("references/operations/docker-and-1panel-deployment.md"),
    ],
  },
  {
    text: "开发指南",
    items: [
      pageItem("references/operations/local-development.md"),
      pageItem("references/operations/testing.md"),
      pageItem("BACKEND.md"),
      pageItem("FRONTEND.md"),
      pageItem("DATABASE.md"),
      pageItem("DESIGN.md"),
      {
        text: "产品规格",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "product-specs"),
          "product-specs",
        ),
      },
      {
        text: "设计文档",
        collapsed: true,
        items: directoryItems(resolve(docsRoot, "designs"), "designs"),
      },
      {
        text: "集成与插件",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "references/integrations"),
          "references/integrations",
        ),
      },
      {
        text: "后端参考",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "references/backend"),
          "references/backend",
        ),
      },
      {
        text: "文档治理与模板",
        collapsed: true,
        items: [
          {
            text: "治理规则",
            collapsed: true,
            items: directoryItems(
              resolve(docsRoot, "governance"),
              "governance",
            ),
          },
          {
            text: "模板",
            collapsed: true,
            items: directoryItems(resolve(docsRoot, "templates"), "templates"),
          },
        ],
      },
    ],
  },
  {
    text: "系统架构",
    items: [
      pageItem("ARCHITECTURE.md"),
      {
        text: "架构设计",
        collapsed: false,
        items: directoryItems(
          resolve(docsRoot, "designs/architecture"),
          "designs/architecture",
        ),
      },
    ],
  },
  {
    text: "API 文档",
    items: [
      {
        text: "API 规范",
        collapsed: false,
        items: directoryItems(
          resolve(docsRoot, "references/api"),
          "references/api",
        ),
      },
      {
        text: "自动生成清单",
        collapsed: false,
        items: directoryItems(resolve(docsRoot, "generated"), "generated"),
      },
      pageItem("designs/backend/direct-api-refactor.md"),
    ],
  },
  {
    text: "系统运维",
    items: [
      {
        text: "部署与运行",
        collapsed: false,
        items: directoryItems(
          resolve(docsRoot, "references/operations"),
          "references/operations",
        ),
      },
      {
        text: "数据库维护与迁移",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "references/database"),
          "references/database",
        ),
      },
      {
        text: "迁移记录",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "references/migration-records"),
          "references/migration-records",
        ),
      },
      {
        text: "执行计划",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "execution-plans"),
          "execution-plans",
        ),
      },
    ],
  },
];

export default defineConfig({
  lang: "zh-CN",
  title: "TuraIDC 文档中心",
  description: "TuraIDC 业务/财务系统官方技术文档",
  srcDir: "../docs",
  vite: {
    publicDir: resolve(import.meta.dirname, "../public"),
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
    ["meta", { name: "apple-mobile-web-app-capable", content: "yes" }],
    ["link", { rel: "icon", href: "/branding/favicon.png" }],
  ],
  themeConfig: {
    // 侧栏与导航顶部仅显示站点标题文字，不渲染 logo 图片
    siteTitle: "TuraIDC文档中心",
    nav: [
      { text: "文档首页", link: "/" },
      { text: "系统架构", link: "/ARCHITECTURE" },
      { text: "API 参考", link: "/generated/api/backend-api-catalog" },
      {
        text: "部署运维",
        link: "/references/operations/deployment-and-scheduling",
      },
    ],
    sidebar,
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
