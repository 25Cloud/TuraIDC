import { t } from "@/locales";
export const seoLandingPages = [
  {
    slug: "cloud-server",
    path: "/cloud-server",
    routeName: "WwwSeoCloudServer",
    keyword: "云服务器",
    title: t("seoLanding.cloudServer.title"),
    description:
      "云服务器面向企业网站、业务系统和开发测试场景，提供稳定弹性计算、灵活配置和 IDC 运维支持。",
    keywords: "云服务器,云主机,弹性云服务器,IDC 云服务器",
    changefreq: "weekly",
    priority: "0.9",
    hero: {
      eyebrow: "弹性计算",
      title: t("seoLanding.cloudServer.heroTitle"),
      summary:
        "面向网站托管、业务系统、接口服务和开发测试，按实际业务规模选择 CPU、内存、带宽和系统镜像，减少前期硬件投入。",
      points: ["灵活配置", "快速交付", "工单支持"],
      stats: [
        {
          value: "多规格",
          label: t("seoLanding.cloudServer.hero.stats.specs.label"),
        },
        {
          value: "多系统",
          label: t("seoLanding.cloudServer.hero.stats.os.label"),
        },
        {
          value: "按需选",
          label: t("seoLanding.cloudServer.hero.stats.onDemand.label"),
        },
      ],
    },
    visual: {
      src: "/img/website/solutions/website.svg",
      alt: "云服务器业务部署示意",
    },
    features: [
      {
        title: t("seoLanding.cloudServer.features.scalable.title"),
        description:
          "从轻量网站到业务应用都能选择合适规格，后续扩容时减少重复迁移成本。",
      },
      {
        title: t("seoLanding.cloudServer.features.standardized.title"),
        description:
          "常用系统镜像、网络和安全配置集中管理，方便新业务快速上线。",
      },
      {
        title: t("seoLanding.cloudServer.features.support.title"),
        description:
          "通过账号与工单体系沉淀问题记录，便于排查网络、系统和费用相关问题。",
      },
    ],
    scenarios: [
      {
        title: t("seoLanding.cloudServer.scenarios.marketingSite.title"),
        description:
          "承载官网、专题页、表单提交和轻量后台，适合稳定展示与日常维护。",
      },
      {
        title: t("seoLanding.cloudServer.scenarios.devTest.title"),
        description:
          "为项目测试、接口联调和临时演示提供独立环境，减少本地环境差异。",
      },
      {
        title: t("seoLanding.cloudServer.scenarios.smbSystem.title"),
        description:
          "适用于管理后台、API 服务、数据看板等需要独立运行环境的业务。",
      },
    ],
    faqs: [
      {
        question: "云服务器适合哪些业务？",
        answer:
          "适合企业官网、业务后台、接口服务、开发测试和轻量数据库等常见互联网业务。",
      },
      {
        question: "新业务应该怎么选配置？",
        answer:
          "可以先从较小规格开始，根据访问量、CPU、内存和带宽使用情况再调整。",
      },
      {
        question: "云服务器和独立服务器有什么区别？",
        answer:
          "云服务器更适合弹性和快速交付，独立服务器更适合对物理资源独占要求更高的场景。",
      },
    ],
    relatedLinks: [
      {
        label: t("seoLanding.pages.relatedLinks.viewProductsServices"),
        to: "/products",
      },
      {
        label: t("seoLanding.cloudServer.relatedLinks.helpCenter"),
        to: "/help",
      },
      { label: t("seoLanding.cloudServer.relatedLinks.aboutUs"), to: "/about" },
    ],
    cta: {
      title: t("seoLanding.cloudServer.cta.title"),
      description: t("seoLanding.cloudServer.cta.description"),
      primaryText: "查看商品配置",
      primaryTo: "/products",
      secondaryText: "注册账号",
      secondaryConsolePath: "/client/register",
    },
  },
  {
    slug: "hong-kong-server",
    path: "/hong-kong-server",
    routeName: "WwwSeoHongKongServer",
    keyword: "香港服务器",
    title: t("seoLanding.hongKongServer.title"),
    description:
      "香港服务器适合跨境网站、外贸业务和亚太访问场景，提供云服务器配置选择与工单支持。",
    keywords: "香港服务器,香港云服务器,香港云主机,跨境服务器",
    changefreq: "weekly",
    priority: "0.8",
    hero: {
      eyebrow: "跨境访问",
      title: t("seoLanding.hongKongServer.heroTitle"),
      summary:
        "适用于外贸站点、跨境业务、亚太用户访问和业务中转场景，兼顾部署效率、访问体验和日常运维支持。",
      points: ["亚太访问", "外贸建站", "部署灵活"],
      stats: [
        {
          value: "香港节点",
          label: t("seoLanding.highlight.apacAccessScenario"),
        },
        {
          value: "多带宽",
          label: t("seoLanding.highlight.selectBySiteTraffic"),
        },
        {
          value: "工单协同",
          label: t("seoLanding.highlight.networkSystemTroubleshooting"),
        },
      ],
    },
    visual: {
      src: "/img/website/solutions/go-global.svg",
      alt: "香港服务器跨境业务示意",
    },
    features: [
      {
        title: t("seoLanding.highlight.crossBorderEntry"),
        description:
          "香港节点常用于面向亚太用户的网站、接口服务和轻量业务入口。",
      },
      {
        title: t("seoLanding.highlight.shorterDeploymentChain"),
        description: t("seoLanding.paragraph.migrationInOneFlow"),
      },
      {
        title: t("seoLanding.highlight.easyToScaleLater"),
        description:
          "业务增长后可继续选择更高配置或调整带宽，减少一次性投入压力。",
      },
    ],
    scenarios: [
      {
        title: t("seoLanding.scenario.foreignTradeShowcase"),
        description: t("seoLanding.scenario.foreignTradeShowcaseDesc"),
      },
      {
        title: t("seoLanding.scenario.crossBorderEntry"),
        description: t("seoLanding.scenario.crossBorderEntryDesc"),
      },
      {
        title: t("seoLanding.scenario.overseasStaging"),
        description: t("seoLanding.scenario.overseasStagingDesc"),
      },
    ],
    faqs: [
      {
        question: "香港服务器适合什么业务？",
        answer: "适合外贸网站、亚太用户访问、跨境业务入口和海外测试环境。",
      },
      {
        question: "香港服务器是否适合国内用户访问？",
        answer:
          "具体体验受线路、带宽和访问区域影响，建议按业务主要用户所在地选择配置。",
      },
      {
        question: "如何选择香港服务器带宽？",
        answer:
          "展示型网站通常先按基础带宽试运行，视频、下载或高并发业务需要预留更高带宽。",
      },
    ],
    relatedLinks: [
      {
        label: t("seoLanding.pages.relatedLinks.viewCloudServer"),
        to: "/cloud-server",
      },
      { label: t("seoLanding.cta.viewUsServers"), to: "/us-server" },
      {
        label: t("seoLanding.pages.relatedLinks.viewProductsServices"),
        to: "/products",
      },
    ],
    cta: {
      title: t("seoLanding.cta.chooseHongKongForCrossBorder"),
      description: t("seoLanding.cta.chooseHongKongTip"),
      primaryText: "查看商品配置",
      primaryTo: "/products",
      secondaryText: "提交咨询工单",
      secondaryConsolePath: "/client/tickets",
    },
  },
  {
    slug: "us-server",
    path: "/us-server",
    routeName: "WwwSeoUsServer",
    keyword: "美国服务器",
    title: t("seoLanding.meta.usServer.title"),
    description:
      "美国服务器面向海外网站、跨境业务和开发测试场景，提供云服务器配置选择、系统部署和售后支持。",
    keywords: "美国服务器,美国云服务器,海外服务器,海外云主机",
    changefreq: "weekly",
    priority: "0.8",
    hero: {
      eyebrow: "海外节点",
      title: t("seoLanding.meta.usServer.heroTitle"),
      summary:
        "面向海外展示站、应用服务、跨境业务和测试环境，帮助团队用较低门槛部署海外访问入口。",
      points: ["海外部署", "网站托管", "业务测试"],
      stats: [
        {
          value: "海外访问",
          label: t("seoLanding.related.fitOverseasAudience"),
        },
        {
          value: "灵活配置",
          label: t("seoLanding.related.selectBySystemBandwidth"),
        },
        {
          value: "统一管理",
          label: t("seoLanding.feature.unifiedManagementDescription"),
        },
      ],
    },
    visual: {
      src: "/img/website/solutions/go-global.svg",
      alt: "美国服务器海外部署示意",
    },
    features: [
      {
        title: t("seoLanding.diagram.usServerOverseasDeploymentLabel"),
        description: t(
          "seoLanding.diagram.usServerOverseasDeploymentDescription",
        ),
      },
      {
        title: t("seoLanding.feature.easyTrialRun"),
        description: t("seoLanding.feature.easyTrialRunDescription"),
      },
      {
        title: t("seoLanding.feature.unifiedBilling"),
        description: t("seoLanding.feature.unifiedBillingDescription"),
      },
    ],
    scenarios: [
      {
        title: t("seoLanding.feature.overseasWebsite"),
        description: t("seoLanding.feature.overseasWebsiteDescription"),
      },
      {
        title: t("seoLanding.feature.crossBorderAppService"),
        description: t("seoLanding.feature.crossBorderAppServiceDescription"),
      },
      {
        title: t("seoLanding.feature.overseasLinkValidation"),
        description: t("seoLanding.feature.overseasLinkValidationDescription"),
      },
    ],
    faqs: [
      {
        question: "美国服务器适合国内业务吗？",
        answer:
          "如果主要用户在海外，美国服务器更合适；如果主要用户在国内，应优先评估国内或香港节点。",
      },
      {
        question: "美国服务器可以做测试环境吗？",
        answer: "可以，适合验证海外访问、系统部署和跨境业务流程。",
      },
      {
        question: "海外服务器选择时最先看什么？",
        answer: "优先看用户所在地、带宽需求、系统环境和后续运维方式。",
      },
    ],
    relatedLinks: [
      {
        label: t("seoLanding.cta.viewHongKongServers"),
        to: "/hong-kong-server",
      },
      {
        label: t("seoLanding.pages.relatedLinks.viewCloudServer"),
        to: "/cloud-server",
      },
      { label: t("seoLanding.pages.relatedLinks.viewHelpCenter"), to: "/help" },
    ],
    cta: {
      title: t("seoLanding.feature.overseasBusinessEntry"),
      description: t("seoLanding.feature.overseasBusinessEntryDescription"),
      primaryText: "查看商品配置",
      primaryTo: "/products",
      secondaryText: "注册账号",
      secondaryConsolePath: "/client/register",
    },
  },
  {
    slug: "high-defense-server",
    path: "/high-defense-server",
    routeName: "WwwSeoHighDefenseServer",
    keyword: "高防服务器",
    title: t("seoLanding.meta.highDefenseServer.title"),
    description:
      "高防服务器适合游戏、业务接口和高风险网站等防护需求场景，提供配置选择与运维支持。",
    keywords: "高防服务器,高防云服务器,防护服务器,游戏服务器防护",
    changefreq: "weekly",
    priority: "0.8",
    hero: {
      eyebrow: "安全防护",
      title: t("seoLanding.meta.highDefenseServer.heroTitle"),
      summary:
        "适用于容易受到异常流量影响的网站、游戏和接口服务，帮助业务在风险场景下保持更清晰的资源与工单管理路径。",
      points: ["防护场景", "业务连续性", "问题追踪"],
      stats: [
        { value: "高风险业务", label: t("seoLanding.ddos.scenarioGameAndApi") },
        {
          value: "可追踪",
          label: t("seoLanding.ddos.tagTraceableDescription"),
        },
        {
          value: "可组合",
          label: t("seoLanding.ddos.tagCombinableDescription"),
        },
      ],
    },
    visual: {
      src: "/img/website/solutions/security.svg",
      alt: "高防服务器防护场景示意",
    },
    features: [
      {
        title: t("seoLanding.diagram.ddosProtectionScenariosLabel"),
        description:
          "游戏、活动站、接口服务等更容易遇到异常流量，需要提前规划防护方案。",
      },
      {
        title: t("seoLanding.feature.centralizedTroubleshooting"),
        description: t("seoLanding.feature.centralizedManagement"),
      },
      {
        title: t("seoLanding.serverPlan.matchWorkload"),
        description: t("seoLanding.serverPlan.matchWorkloadDesc"),
      },
    ],
    scenarios: [
      {
        title: t("seoLanding.game.workloadEntry"),
        description: t("seoLanding.game.workloadEntryDesc"),
      },
      {
        title: t("seoLanding.campaign.pages"),
        description: t("seoLanding.campaign.pagesDesc"),
      },
      {
        title: t("seoLanding.api.services"),
        description: t("seoLanding.api.servicesDesc"),
      },
    ],
    faqs: [
      {
        question: "高防服务器和普通云服务器有什么区别？",
        answer:
          "高防服务器更偏向异常流量防护场景，普通云服务器更适合常规计算和托管需求。",
      },
      {
        question: "所有网站都需要高防服务器吗？",
        answer:
          "不一定。普通企业站通常先用常规云服务器，游戏、活动和高风险业务再评估高防需求。",
      },
      {
        question: "选择高防服务器前要准备什么？",
        answer:
          "需要明确业务类型、访问峰值、攻击风险、系统环境和可接受的恢复时间。",
      },
    ],
    relatedLinks: [
      {
        label: t("seoLanding.pages.relatedLinks.viewCloudServer"),
        to: "/cloud-server",
      },
      { label: t("seoLanding.pages.relatedLinks.viewHelpCenter"), to: "/help" },
      {
        label: t("seoLanding.pages.relatedLinks.viewProductsServices"),
        to: "/products",
      },
    ],
    cta: {
      title: t("seoLanding.ddos.highRiskPlan"),
      description: t("seoLanding.ddos.highRiskPlanDesc"),
      primaryText: "查看商品配置",
      primaryTo: "/products",
      secondaryText: "提交咨询工单",
      secondaryConsolePath: "/client/tickets",
    },
  },
  {
    slug: "cloud-pc",
    path: "/cloud-pc",
    routeName: "WwwSeoCloudPc",
    keyword: "云电脑",
    title: t("seoLanding.meta.cloudPc.title"),
    description:
      "云电脑适合远程办公、轻量桌面、软件测试和临时工作环境，提供云端资源选择与账号管理能力。",
    keywords: "云电脑,云桌面,远程办公云电脑,桌面云",
    changefreq: "weekly",
    priority: "0.7",
    hero: {
      eyebrow: "云端桌面",
      title: t("seoLanding.meta.cloudPc.heroTitle"),
      summary:
        "为远程办公、软件测试、临时桌面和轻量操作环境提供云端资源，减少本地设备差异带来的维护成本。",
      points: ["远程访问", "轻量桌面", "账号管理"],
      stats: [
        {
          value: "云端运行",
          label: t("seoLanding.cloudDesktop.lessLocalDependency"),
        },
        {
          value: "灵活使用",
          label: t("seoLanding.cloudDesktop.shortAndLongTerm"),
        },
        {
          value: "统一入口",
          label: t("seoLanding.cloudDesktop.consoleAndRenewal"),
        },
      ],
    },
    visual: {
      src: "/img/website/solutions/government.svg",
      alt: "云电脑远程办公示意",
    },
    features: [
      {
        title: t("seoLanding.cloudDesktop.lowerDeviceBarrier"),
        description: t("seoLanding.cloudDesktop.cloudResourceDesc"),
      },
      {
        title: t("seoLanding.cloudDesktop.temporaryProject"),
        description: t("seoLanding.cloudDesktop.temporaryProjectDesc"),
      },
      {
        title: t("seoLanding.cloudDesktop.easyAccountManagement"),
        description: t("seoLanding.cloudPc.unifiedAccount.desc"),
      },
    ],
    scenarios: [
      {
        title: t("seoLanding.cloudPc.remoteWork.title"),
        description: t("seoLanding.cloudPc.remoteWork.desc"),
      },
      {
        title: t("seoLanding.cloudPc.softwareTesting.title"),
        description: t("seoLanding.cloudPc.softwareTesting.desc"),
      },
      {
        title: t("seoLanding.cloudPc.demoTraining.title"),
        description: t("seoLanding.cloudPc.demoTraining.desc"),
      },
    ],
    faqs: [
      {
        question: "云电脑适合哪些人使用？",
        answer:
          "适合远程办公、软件测试、临时项目、培训演示和轻量桌面使用场景。",
      },
      {
        question: "云电脑能替代所有本地电脑吗？",
        answer: "不一定。高性能图形、专业硬件和低延迟外设场景需要单独评估。",
      },
      {
        question: "云电脑和云服务器有什么区别？",
        answer:
          "云电脑更偏桌面操作体验，云服务器更偏业务系统和服务端应用运行。",
      },
    ],
    relatedLinks: [
      {
        label: t("seoLanding.pages.relatedLinks.viewCloudServer"),
        to: "/cloud-server",
      },
      {
        label: t("seoLanding.pages.relatedLinks.viewProductsServices"),
        to: "/products",
      },
      { label: t("seoLanding.pages.relatedLinks.viewHelpCenter"), to: "/help" },
    ],
    cta: {
      title: t("seoLanding.cloudPc.section.remoteWorkDesktop.title"),
      description: t("seoLanding.cloudPc.section.remoteWorkDesktop.desc"),
      primaryText: "查看商品配置",
      primaryTo: "/products",
      secondaryText: "注册账号",
      secondaryConsolePath: "/client/register",
    },
  },
];

export const seoLandingFooterLinks = seoLandingPages.map((page) => ({
  to: page.path,
  label: page.keyword,
}));

const DEFAULT_SITE_URL = "https://www.example.com";
const SITE_LANGUAGE = "zh-CN";

/**
 * 结构化数据里的站点名与 logo 来自运行时站点配置（后台可编辑），
 * 未传时回落到 SEO 动态渲染注入的 window.__CW_SITE_CONFIG__，避免写死品牌。
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

  return { siteName, siteLogo };
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

export function getSeoLandingPageByPath(path) {
  const normalizedPath = normalizePath(path);
  return seoLandingPages.find((page) => page.path === normalizedPath) || null;
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
    headline: page.hero.title,
    description: page.description,
    keywords: page.keywords,
    inLanguage: SITE_LANGUAGE,
    isPartOf: {
      "@id": websiteId,
    },
    about: {
      "@type": "Service",
      name: page.keyword,
      description: page.hero.summary,
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
        name: t("seoLanding.breadcrumb.home"),
        item: homeUrl,
      },
      {
        "@type": "ListItem",
        position: 2,
        name: t("seoLanding.breadcrumb.productsServices"),
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
  return seoLandingPages.map((page) => ({
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
