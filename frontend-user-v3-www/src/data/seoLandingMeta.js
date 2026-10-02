// SEO 落地页的轻量 meta 数据与路由工具。
// 仅包含路由注册与结构化数据所需的字段（约 1KB），
// 全量正文文案（hero/features/scenarios 等）保留在 seoLandingPages.js，
// 由懒加载的落地页组件引入，避免约 8.9KB 全量数据进入每页首屏 entry chunk。

export const seoLandingMetaPages = [
  {
    slug: "cloud-server",
    path: "/cloud-server",
    routeName: "WwwSeoCloudServer",
    keyword: "云服务器",
    title: "云服务器 - 稳定弹性计算与 IDC 云主机",
    description:
      "云服务器面向企业网站、业务系统和开发测试场景，提供稳定弹性计算、灵活配置和 IDC 运维支持。",
    keywords: "云服务器,云主机,弹性云服务器,IDC 云服务器",
    changefreq: "weekly",
    priority: "0.9",
    heroTitle: "稳定易用的云服务器",
    heroSummary:
      "面向网站托管、业务系统、接口服务和开发测试，按实际业务规模选择 CPU、内存、带宽和系统镜像，减少前期硬件投入。",
  },
  {
    slug: "hong-kong-server",
    path: "/hong-kong-server",
    routeName: "WwwSeoHongKongServer",
    keyword: "香港服务器",
    title: "香港服务器 - 面向出海与跨境访问的云服务器",
    description:
      "香港服务器适合跨境网站、外贸业务和亚太访问场景，提供云服务器配置选择与工单支持。",
    keywords: "香港服务器,香港云服务器,香港云主机,跨境服务器",
    changefreq: "weekly",
    priority: "0.8",
    heroTitle: "面向亚太业务的香港服务器",
    heroSummary:
      "适用于外贸站点、跨境业务、亚太用户访问和业务中转场景，兼顾部署效率、访问体验和日常运维支持。",
  },
  {
    slug: "us-server",
    path: "/us-server",
    routeName: "WwwSeoUsServer",
    keyword: "美国服务器",
    title: "美国服务器 - 海外业务部署与网站托管",
    description:
      "美国服务器面向海外网站、跨境业务和开发测试场景，提供云服务器配置选择、系统部署和售后支持。",
    keywords: "美国服务器,美国云服务器,海外服务器,海外云主机",
    changefreq: "weekly",
    priority: "0.8",
    heroTitle: "适合海外部署的美国服务器",
    heroSummary:
      "面向海外展示站、应用服务、跨境业务和测试环境，帮助团队用较低门槛部署海外访问入口。",
  },
  {
    slug: "high-defense-server",
    path: "/high-defense-server",
    routeName: "WwwSeoHighDefenseServer",
    keyword: "高防服务器",
    title: "高防服务器 - 面向攻击防护场景的云服务器",
    description:
      "高防服务器适合游戏、业务接口和高风险网站等防护需求场景，提供配置选择与运维支持。",
    keywords: "高防服务器,高防云服务器,防护服务器,游戏服务器防护",
    changefreq: "weekly",
    priority: "0.8",
    heroTitle: "面向防护需求的高防服务器",
    heroSummary:
      "适用于容易受到异常流量影响的网站、游戏和接口服务，帮助业务在风险场景下保持更清晰的资源与工单管理路径。",
  },
  {
    slug: "cloud-pc",
    path: "/cloud-pc",
    routeName: "WwwSeoCloudPc",
    keyword: "云电脑",
    title: "云电脑 - 远程办公与轻量桌面云方案",
    description:
      "云电脑适合远程办公、轻量桌面、软件测试和临时工作环境，提供云端资源选择与账号管理能力。",
    keywords: "云电脑,云桌面,远程办公云电脑,桌面云",
    changefreq: "weekly",
    priority: "0.7",
    heroTitle: "灵活可用的云电脑",
    heroSummary:
      "为远程办公、软件测试、临时桌面和轻量操作环境提供云端资源，减少本地设备差异带来的维护成本。",
  },
];

export const seoLandingFooterLinks = seoLandingMetaPages.map((page) => ({
  to: page.path,
  label: page.keyword,
}));

const DEFAULT_SITE_URL = "https://www.example.com";
const SITE_LANGUAGE = "zh-CN";

/**
 * 结构化数据里的站点名与 logo 来自运行时站点配置（后台可编辑），
 * 由调用方（路由 meta 的 structuredData 工厂）传入，未传时回落到
 * SEO 动态渲染注入的 window.__CW_SITE_CONFIG__，避免写死品牌。
 */
function resolveSiteBrand(siteBrand = {}) {
  const bootstrapped =
    (typeof window !== "undefined" && window.__CW_SITE_CONFIG__) || {};
  const siteName = String(
    siteBrand.siteName || bootstrapped.site_name || "",
  ).trim();
  const siteLogo = String(
    siteBrand.siteLogo || bootstrapped.site_logo || "",
  ).trim();

  return {
    siteName,
    siteLogo,
  };
}

function normalizePath(path) {
  const value = String(path || "").trim();
  if (!value) return "/";
  const withLeadingSlash = value.startsWith("/") ? value : `/${value}`;
  return withLeadingSlash === "/" ? "/" : withLeadingSlash.replace(/\/+$/, "");
}

function normalizeSiteUrl(siteUrl) {
  return (
    String(siteUrl || DEFAULT_SITE_URL).replace(/\/+$/, "") || DEFAULT_SITE_URL
  );
}

function absoluteUrl(siteUrl, path) {
  const normalizedSiteUrl = normalizeSiteUrl(siteUrl);
  const normalizedPath = normalizePath(path);
  return `${normalizedSiteUrl}${normalizedPath === "/" ? "/" : normalizedPath}`;
}

export function getSeoLandingMetaByPath(path) {
  const normalizedPath = normalizePath(path);
  return (
    seoLandingMetaPages.find((page) => page.path === normalizedPath) || null
  );
}

export function buildSeoLandingStructuredData(
  page,
  siteUrl = DEFAULT_SITE_URL,
  siteBrand = {},
) {
  const normalizedSiteUrl = normalizeSiteUrl(siteUrl);
  const { siteName, siteLogo } = resolveSiteBrand(siteBrand);
  const homeUrl = `${normalizedSiteUrl}/`;
  const productsUrl = absoluteUrl(normalizedSiteUrl, "/products");
  const pageUrl = absoluteUrl(normalizedSiteUrl, page.path);
  const organizationId = `${normalizedSiteUrl}/#organization`;
  const websiteId = `${normalizedSiteUrl}/#website`;
  const webpageId = `${pageUrl}#webpage`;

  const organization = {
    "@context": "https://schema.org",
    "@type": "Organization",
    "@id": organizationId,
    name: siteName,
    url: homeUrl,
  };
  if (siteLogo) {
    organization.logo = siteLogo;
  }

  const website = {
    "@context": "https://schema.org",
    "@type": "WebSite",
    "@id": websiteId,
    name: siteName,
    url: homeUrl,
    inLanguage: SITE_LANGUAGE,
    publisher: {
      "@id": organizationId,
    },
  };

  const structured = siteName ? [organization, website] : [];

  structured.push({
    "@context": "https://schema.org",
    "@type": "WebPage",
    "@id": webpageId,
    url: pageUrl,
    name: siteName ? `${siteName} - ${page.title}` : page.title,
    headline: page.heroTitle,
    description: page.description,
    keywords: page.keywords,
    inLanguage: SITE_LANGUAGE,
    isPartOf: {
      "@id": websiteId,
    },
    about: {
      "@type": "Service",
      name: page.keyword,
      description: page.heroSummary,
      provider: {
        "@id": organizationId,
      },
    },
  });

  structured.push({
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: [
      {
        "@type": "ListItem",
        position: 1,
        name: "首页",
        item: homeUrl,
      },
      {
        "@type": "ListItem",
        position: 2,
        name: "产品与服务",
        item: productsUrl,
      },
      {
        "@type": "ListItem",
        position: 3,
        name: page.keyword,
        item: pageUrl,
      },
    ],
  });

  return structured;
}

export function buildSeoLandingRouteMeta(page) {
  return {
    title: page.title,
    description: page.description,
    keywords: page.keywords,
    canonical: page.path,
    seoLandingPath: page.path,
    seoLanding: true,
    structuredData: ({ siteUrl, siteBrand } = {}) =>
      buildSeoLandingStructuredData(page, siteUrl, siteBrand),
  };
}

export function listSeoLandingSitemapRoutes() {
  return seoLandingMetaPages.map((page) => ({
    path: page.path,
    title: page.title,
    description: page.description,
    keywords: page.keywords,
    changefreq: page.changefreq,
    priority: page.priority,
    structuredData: (siteUrl, siteBrand) =>
      buildSeoLandingStructuredData(page, siteUrl, siteBrand),
  }));
}
