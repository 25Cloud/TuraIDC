/**
 * 不随语言变化的常量：地址、锚点、图标 key、素材路径。
 * 文案一律在 src/i18n/*.json，两者不要互相包含。
 */

export const DOCS_URL = "https://docs.turaidc.com";
export const REPO_URL = "https://github.com/25Cloud/TuraIDC";
/** GitHub 仓库标识，实时徽章与图表地址都由它拼出来 */
export const REPO_SLUG = "25Cloud/TuraIDC";
export const CONTACT_MAIL = "contact@turaidc.com";
/** 二五云：目前唯一跑着 TuraIDC 的生产站点，作为在线演示入口 */
export const DEMO_URL = "https://idc.25y.cn/";
/** 演示站：纯静态、无后端，首页 / 用户端 / 控制台 / 管理端都能直接点 */
export const DEMO_SITE_URL = "https://demo.turaidc.com/";

/** 演示站三端入口：官网与演示站互相指路，访客不用猜「管理端在哪」 */
export const DEMO_ENTRIES = [
  { key: "user" as const, path: "/", account: null },
  { key: "console" as const, path: "/console/#/client/login", account: "demo@example.com / demo123456" },
  { key: "admin" as const, path: "/admin/#/admin/dashboard", account: "admin / demo123456" },
];

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
  demoSite: DEMO_SITE_URL,
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

/**
 * 实时徽章墙。
 *
 * 图片地址指向 shields.io 与 GitHub 自己的徽章服务：访客打开页面时才由对方现算，
 * 对方各自缓存几分钟，所以两次部署之间数字也会变——这是首页唯一「实时」的部分，
 * 依然零客户端 JS（就是几张 <img>），也不会把访客 IP 交给 GitHub API。
 *
 * `width` / `height` 是按当前徽章实测宽度写死的占位尺寸，防加载时布局跳动；
 * 数字变长会让真实 SVG 变宽，渲染时用 CSS 固定 20px 高、宽度自适应，不会拉伸变形。
 *
 * `group` 决定换行位置：仓库状态一组、技术栈一组，免得 11 枚挤成一行后
 * 末尾挂一枚孤零零的徽章。
 */
export const LIVE_BADGES = [
  {
    key: "release",
    group: "repo",
    label: "Release",
    src: `https://img.shields.io/github/v/release/${REPO_SLUG}?style=flat-square&label=Release`,
    href: `${REPO_URL}/releases`,
    width: 98,
    height: 20,
  },
  {
    key: "stars",
    group: "repo",
    label: "Stars",
    src: `https://img.shields.io/github/stars/${REPO_SLUG}?style=flat-square&label=Stars`,
    href: `${REPO_URL}/stargazers`,
    width: 78,
    height: 20,
  },
  {
    key: "forks",
    group: "repo",
    label: "Forks",
    src: `https://img.shields.io/github/forks/${REPO_SLUG}?style=flat-square&label=Forks`,
    href: `${REPO_URL}/forks`,
    width: 70,
    height: 20,
  },
  {
    key: "contributors",
    group: "repo",
    label: "Contributors",
    src: `https://img.shields.io/github/contributors/${REPO_SLUG}?style=flat-square&label=Contributors`,
    href: `${REPO_URL}/graphs/contributors`,
    width: 108,
    height: 20,
  },
  {
    key: "issues",
    group: "repo",
    label: "Issues",
    src: `https://img.shields.io/github/issues/${REPO_SLUG}?style=flat-square&label=Issues`,
    href: `${REPO_URL}/issues`,
    width: 96,
    height: 20,
  },
  {
    key: "lastCommit",
    group: "repo",
    label: "Last commit",
    src: `https://img.shields.io/github/last-commit/${REPO_SLUG}?style=flat-square&label=Last%20Commit`,
    href: `${REPO_URL}/commits`,
    width: 124,
    height: 20,
  },
  {
    key: "license",
    group: "repo",
    label: "License",
    src: "https://img.shields.io/badge/license-AGPL--3.0--or--later-blue?style=flat-square",
    href: `${REPO_URL}/blob/main/LICENSE`,
    width: 156,
    height: 20,
  },
  {
    key: "ci",
    group: "repo",
    label: "CI",
    src: `${REPO_URL}/actions/workflows/ci.yml/badge.svg`,
    href: `${REPO_URL}/actions/workflows/ci.yml`,
    width: 86,
    height: 20,
  },
  {
    key: "php",
    group: "stack",
    label: "PHP",
    src: "https://img.shields.io/badge/PHP-%3E%3D8.3-777BB4?style=flat-square",
    href: `${REPO_URL}/tree/main/backend`,
    width: 76,
    height: 20,
  },
  {
    key: "laravel",
    group: "stack",
    label: "Laravel",
    src: "https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square",
    href: `${REPO_URL}/tree/main/backend`,
    width: 74,
    height: 20,
  },
  {
    key: "vue",
    group: "stack",
    label: "Vue",
    src: "https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square",
    href: `${REPO_URL}/tree/main/frontend-admin-v3`,
    width: 48,
    height: 20,
  },
] as const;

/**
 * README 里那张 star-history 图，原样搬过来（含 README 自带的 sealed_token，不要另编一个）。
 * 深浅两版靠 <picture> 的 prefers-color-scheme 切换——它跟随系统配色，不跟随站内主题开关。
 */
const STAR_HISTORY_TOKEN =
  "DLZeeKcw05mIrh2HqJHHcah_CJlwXqq0_AW-LpSPtWsFoVE6Px46XG3BoaMrvOmP-N-Pcp22-B-fQna7PXyJum3iYhdzpsJD0xsfA7tCvxXDdFS3-MSOeg";

export const STAR_HISTORY = {
  chart: `https://api.star-history.com/chart?repos=${REPO_SLUG}&type=date&legend=top-left&sealed_token=${STAR_HISTORY_TOKEN}`,
  chartDark: `https://api.star-history.com/chart?repos=${REPO_SLUG}&type=date&theme=dark&legend=top-left&sealed_token=${STAR_HISTORY_TOKEN}`,
  page: `https://www.star-history.com/?repos=25Cloud%2FTuraIDC&type=date&legend=top-left`,
  width: 800,
  height: 533,
} as const;

/** 贡献者头像墙：contrib.rocks 按仓库现算，点进 GitHub 看完整列表 */
export const CONTRIBUTORS = {
  image: `https://contrib.rocks/image?repo=${REPO_SLUG}`,
  page: `${REPO_URL}/graphs/contributors`,
  width: 744,
  height: 64,
} as const;

/** 语言占比条配色，与 GitHub 自身语言色一致；表里没有的语言用中性灰 */
export const LANGUAGE_COLORS: Record<string, string> = {
  TypeScript: "#3178c6",
  PHP: "#4F5D95",
  Vue: "#41b883",
  JavaScript: "#f1e05a",
  Python: "#3572a5",
  Blade: "#f7523f",
  Shell: "#89e051",
  CSS: "#563d7c",
};

export const LANGUAGE_FALLBACK_COLOR = "#8b949e";

/** 开发交流群：群号写成可选中的文本，不为「复制按钮」加一行 JS */
export const COMMUNITY = {
  qqGroup: "1105174267",
  qqJoin: "https://qm.qq.com/q/Doa31p5M30",
  qqQrcode: "/images/qq-group.jpg",
  qqQrcodeWidth: 1284,
  qqQrcodeHeight: 2280,
} as const;

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
