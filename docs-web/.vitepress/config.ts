import { readFileSync, readdirSync } from "node:fs";
import { resolve } from "node:path";
import { defineConfig, type DefaultTheme } from "vitepress";

const docsRoot = resolve(import.meta.dirname, "../../docs");

function pageTitle(path: string): string {
  const source = readFileSync(path, "utf8");
  const title = source.match(/^#\s+(.+)$/m)?.[1];

  return (title ?? path.split(/[\\/]/).pop() ?? "未命名文档")
    .replace(/[`*_]/g, "")
    .trim();
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
            text: entry.name,
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
      pageItem("快速开始.md"),
      pageItem("参考资料/运维/宝塔部署项目指南.md"),
      pageItem("参考资料/运维/Docker与1Panel部署指南.md"),
    ],
  },
  {
    text: "开发指南",
    items: [
      pageItem("参考资料/运维/本地启动指南.md"),
      pageItem("参考资料/运维/测试指南.md"),
      pageItem("BACKEND.md"),
      pageItem("FRONTEND.md"),
      pageItem("DATABASE.md"),
      pageItem("DESIGN.md"),
      {
        text: "产品规格",
        collapsed: true,
        items: directoryItems(resolve(docsRoot, "产品规格"), "产品规格"),
      },
      {
        text: "设计文档",
        collapsed: true,
        items: directoryItems(resolve(docsRoot, "设计文档"), "设计文档"),
      },
      {
        text: "集成与插件",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "参考资料/集成"),
          "参考资料/集成",
        ),
      },
      {
        text: "后端参考",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "参考资料/后端"),
          "参考资料/后端",
        ),
      },
      {
        text: "文档治理与模板",
        collapsed: true,
        items: [
          {
            text: "治理规则",
            collapsed: true,
            items: directoryItems(resolve(docsRoot, "治理"), "治理"),
          },
          {
            text: "模板",
            collapsed: true,
            items: directoryItems(resolve(docsRoot, "模板"), "模板"),
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
          resolve(docsRoot, "设计文档/架构"),
          "设计文档/架构",
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
          resolve(docsRoot, "参考资料/接口"),
          "参考资料/接口",
        ),
      },
      {
        text: "自动生成清单",
        collapsed: false,
        items: directoryItems(resolve(docsRoot, "自动生成"), "自动生成"),
      },
      pageItem("设计文档/后端/API直接重构方案.md"),
    ],
  },
  {
    text: "系统运维",
    items: [
      {
        text: "部署与运行",
        collapsed: false,
        items: directoryItems(
          resolve(docsRoot, "参考资料/运维"),
          "参考资料/运维",
        ),
      },
      {
        text: "数据库维护与迁移",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "参考资料/数据库"),
          "参考资料/数据库",
        ),
      },
      {
        text: "迁移记录",
        collapsed: true,
        items: directoryItems(
          resolve(docsRoot, "参考资料/迁移记录"),
          "参考资料/迁移记录",
        ),
      },
      {
        text: "执行计划",
        collapsed: true,
        items: directoryItems(resolve(docsRoot, "执行计划"), "执行计划"),
      },
    ],
  },
];

export default defineConfig({
  title: "TuraIDC 文档中心",
  description: "TuraIDC 业务/财务系统官方技术文档",
  srcDir: "../docs",
  vite: {
    publicDir: resolve(import.meta.dirname, "../public"),
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
    logo: "/branding/turaidc-logo.png",
    siteTitle: "TuraIDC 文档中心",
    nav: [
      { text: "文档首页", link: "/" },
      { text: "系统架构", link: "/ARCHITECTURE" },
      { text: "API 参考", link: "/自动生成/接口/后端API清单" },
      { text: "部署运维", link: "/参考资料/运维/部署与调度指南" },
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
  },
  rewrites: readmeRewrites(docsRoot),
});
