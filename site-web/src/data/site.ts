/**
 * 不随语言变化的常量：地址、锚点、图标 key、素材路径。
 * 文案一律在 src/i18n/*.json，两者不要互相包含。
 */

export const DOCS_URL = "https://docs.turaidc.com";
export const REPO_URL = "https://github.com/25Cloud/TuraIDC";
export const CONTACT_MAIL = "contact@turaidc.com";
/** 二五云：目前唯一跑着 TuraIDC 的生产站点，作为在线演示入口 */
export const DEMO_URL = "https://idc.25y.cn/";

export const NAV_LINKS = [
  { key: "features", href: "#features" },
  { key: "flow", href: "#flow" },
  { key: "security", href: "#security" },
  { key: "deploy", href: "#deploy" },
  { key: "pricing", href: "#pricing" },
  { key: "faq", href: "#faq" },
] as const;

export const LINKS = {
  docs: DOCS_URL,
  repo: REPO_URL,
  releases: `${REPO_URL}/releases`,
  issues: `${REPO_URL}/issues`,
  demo: DEMO_URL,
  quickStart: `${DOCS_URL}/quick-start`,
  deployDocs: `${DOCS_URL}/guide/deploy/scheduling`,
  api: `${DOCS_URL}/develop/api`,
  plugins: `${DOCS_URL}/develop/plugins`,
  architecture: `${DOCS_URL}/develop/architecture`,
  licensing: `${DOCS_URL}/guide/licensing`,
  migrate: `${DOCS_URL}/guide/migrate/zjmf-finance`,
  github: REPO_URL,
} as const;

/** deploy 区块三张卡片各自对应的文档地址，顺序与 i18n 的 deploy.items 一致 */
export const DEPLOY_HREFS = [
  `${DOCS_URL}/guide/deploy/docker-1panel`,
  `${DOCS_URL}/guide/deploy/bt-panel`,
  `${DOCS_URL}/guide/deploy/bare-metal`,
];

/** 与 i18n 的 features.items 顺序一致 */
export const FEATURE_ICONS = [
  "shop",
  "task",
  "money",
  "user",
  "ticket",
  "notification",
  "time",
  "layers",
] as const;

/** 与 i18n 的 flow.steps 顺序一致 */
export const FLOW_ICONS = [
  "shop",
  "wallet",
  "rocket",
  "refresh",
  "chart",
  "ticket",
] as const;

/** 与 i18n 的 deploy.items 顺序一致 */
export const DEPLOY_ICONS = ["terminal", "control", "server"] as const;

/**
 * 系统实拍图，由 `scripts/capture-shots.mjs` 用无头 Chrome 打开二五云线上站点截取。
 * 换版式后重跑脚本，不要手工改图。
 */
export const SHOTS = {
  catalog: "/shots/catalog.webp",
} as const;

/**
 * 合作伙伴素材，顺序与 i18n 的 partners.items 一致。
 * 取自仓库 README 的 Trusted Partners，按 2 倍显示高度导出。
 */
export const PARTNER_LOGOS = [
  { src: "/partners/7inet.svg", width: 96, height: 70 },
  { src: "/partners/starvm.webp", width: 149, height: 40 },
  { src: "/partners/25yun.webp", width: 110, height: 40 },
  { src: "/partners/xianlin.webp", width: 135, height: 40 },
  { src: "/partners/shanshui.webp", width: 188, height: 40 },
] as const;

export const FOOTER_HREFS: Record<string, string> = {
  quickStart: LINKS.quickStart,
  deploy: LINKS.deployDocs,
  api: LINKS.api,
  plugins: LINKS.plugins,
  repo: REPO_URL,
  releases: LINKS.releases,
  issues: LINKS.issues,
  licensing: LINKS.licensing,
  mail: `mailto:${CONTACT_MAIL}`,
  architecture: LINKS.architecture,
  migrate: LINKS.migrate,
};
