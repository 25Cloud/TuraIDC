export const seoLandingPages = [
  {
    slug: 'cloud-server',
    path: '/cloud-server',
    routeName: 'WwwSeoCloudServer',
    keyword: '云服务器',
    title: '云服务器 - 稳定弹性计算与 IDC 云主机 - 图拉云',
    description: '图拉云云服务器面向企业网站、业务系统和开发测试场景，提供稳定弹性计算、灵活配置和 IDC 运维支持。',
    keywords: '云服务器,云主机,弹性云服务器,IDC 云服务器,图拉云',
    changefreq: 'weekly',
    priority: '0.9',
    hero: {
      eyebrow: '弹性计算',
      title: '稳定易用的云服务器',
      summary: '面向网站托管、业务系统、接口服务和开发测试，按实际业务规模选择 CPU、内存、带宽和系统镜像，减少前期硬件投入。',
      points: ['灵活配置', '快速交付', '工单支持'],
      stats: [
        { value: '多规格', label: 'CPU / 内存 / 带宽组合' },
        { value: '多系统', label: 'Linux 与 Windows 镜像' },
        { value: '按需选', label: '适配测试到生产环境' },
      ],
    },
    visual: { src: '/img/website/solutions/website.svg', alt: '云服务器业务部署示意' },
    features: [
      { title: '配置可按业务阶段调整', description: '从轻量网站到业务应用都能选择合适规格，后续扩容时减少重复迁移成本。' },
      { title: '适合标准化部署', description: '常用系统镜像、网络和安全配置集中管理，方便新业务快速上线。' },
      { title: '售后路径清晰', description: '通过账号与工单体系沉淀问题记录，便于排查网络、系统和费用相关问题。' },
    ],
    scenarios: [
      { title: '企业官网与营销站', description: '承载官网、专题页、表单提交和轻量后台，适合稳定展示与日常维护。' },
      { title: '开发测试环境', description: '为项目测试、接口联调和临时演示提供独立环境，减少本地环境差异。' },
      { title: '中小型业务系统', description: '适用于管理后台、API 服务、数据看板等需要独立运行环境的业务。' },
    ],
    faqs: [
      { question: '云服务器适合哪些业务？', answer: '适合企业官网、业务后台、接口服务、开发测试和轻量数据库等常见互联网业务。' },
      { question: '新业务应该怎么选配置？', answer: '可以先从较小规格开始，根据访问量、CPU、内存和带宽使用情况再调整。' },
      { question: '云服务器和独立服务器有什么区别？', answer: '云服务器更适合弹性和快速交付，独立服务器更适合对物理资源独占要求更高的场景。' },
    ],
    relatedLinks: [
      { label: '查看产品与服务', to: '/products' },
      { label: '阅读帮助中心', to: '/help' },
      { label: '了解图拉云', to: '/about' },
    ],
    cta: {
      title: '按业务规模选择云服务器',
      description: '先查看可售产品和配置，再进入控制台完成账号注册与下单。',
      primaryText: '查看产品配置',
      primaryTo: '/products',
      secondaryText: '注册账号',
      secondaryConsolePath: '/client/register',
    },
  },
  {
    slug: 'hong-kong-server',
    path: '/hong-kong-server',
    routeName: 'WwwSeoHongKongServer',
    keyword: '香港服务器',
    title: '香港服务器 - 面向出海与跨境访问的云服务器 - 图拉云',
    description: '图拉云香港服务器适合跨境网站、外贸业务和亚太访问场景，提供云服务器配置选择与工单支持。',
    keywords: '香港服务器,香港云服务器,香港云主机,跨境服务器,图拉云',
    changefreq: 'weekly',
    priority: '0.8',
    hero: {
      eyebrow: '跨境访问',
      title: '面向亚太业务的香港服务器',
      summary: '适用于外贸站点、跨境业务、亚太用户访问和业务中转场景，兼顾部署效率、访问体验和日常运维支持。',
      points: ['亚太访问', '外贸建站', '部署灵活'],
      stats: [
        { value: '香港节点', label: '适合亚太访问场景' },
        { value: '多带宽', label: '按站点访问量选择' },
        { value: '工单协同', label: '支持网络与系统问题排查' },
      ],
    },
    visual: { src: '/img/website/solutions/go-global.svg', alt: '香港服务器跨境业务示意' },
    features: [
      { title: '适合跨境访问入口', description: '香港节点常用于面向亚太用户的网站、接口服务和轻量业务入口。' },
      { title: '部署链路更短', description: '网站、证书、应用和数据迁移可以在同一业务流程中逐步完成。' },
      { title: '便于后续扩展', description: '业务增长后可继续选择更高配置或调整带宽，减少一次性投入压力。' },
    ],
    scenarios: [
      { title: '外贸展示站', description: '承载企业介绍、产品目录、询盘表单和多语言页面。' },
      { title: '跨境业务入口', description: '作为面向亚太用户的应用入口、接口服务或轻量后台。' },
      { title: '海外测试环境', description: '用于验证海外线路、业务访问和不同区域用户体验。' },
    ],
    faqs: [
      { question: '香港服务器适合什么业务？', answer: '适合外贸网站、亚太用户访问、跨境业务入口和海外测试环境。' },
      { question: '香港服务器是否适合国内用户访问？', answer: '具体体验受线路、带宽和访问区域影响，建议按业务主要用户所在地选择配置。' },
      { question: '如何选择香港服务器带宽？', answer: '展示型网站通常先按基础带宽试运行，视频、下载或高并发业务需要预留更高带宽。' },
    ],
    relatedLinks: [
      { label: '查看云服务器', to: '/cloud-server' },
      { label: '查看美国服务器', to: '/us-server' },
      { label: '查看产品与服务', to: '/products' },
    ],
    cta: {
      title: '为跨境业务选择香港服务器',
      description: '先确认访问区域、带宽需求和系统环境，再选择合适配置。',
      primaryText: '查看产品配置',
      primaryTo: '/products',
      secondaryText: '提交咨询工单',
      secondaryConsolePath: '/client/tickets',
    },
  },
  {
    slug: 'us-server',
    path: '/us-server',
    routeName: 'WwwSeoUsServer',
    keyword: '美国服务器',
    title: '美国服务器 - 海外业务部署与网站托管 - 图拉云',
    description: '图拉云美国服务器面向海外网站、跨境业务和开发测试场景，提供云服务器配置选择、系统部署和售后支持。',
    keywords: '美国服务器,美国云服务器,海外服务器,海外云主机,图拉云',
    changefreq: 'weekly',
    priority: '0.8',
    hero: {
      eyebrow: '海外节点',
      title: '适合海外部署的美国服务器',
      summary: '面向海外展示站、应用服务、跨境业务和测试环境，帮助团队用较低门槛部署海外访问入口。',
      points: ['海外部署', '网站托管', '业务测试'],
      stats: [
        { value: '海外访问', label: '适合目标用户在海外的业务' },
        { value: '灵活配置', label: '按系统与带宽需求选择' },
        { value: '统一管理', label: '订单、账单和工单集中处理' },
      ],
    },
    visual: { src: '/img/website/solutions/go-global.svg', alt: '美国服务器海外部署示意' },
    features: [
      { title: '面向海外用户访问', description: '适合服务海外用户的网站、接口服务和轻量应用。' },
      { title: '方便业务试运行', description: '先部署测试环境，再根据访问数据调整配置和带宽。' },
      { title: '统一账单管理', description: '海外节点资源可与其他产品一起在账户内查看和续费。' },
    ],
    scenarios: [
      { title: '海外官网', description: '承载英文站、多语言站和面向海外市场的企业展示内容。' },
      { title: '跨境应用服务', description: '部署 API、中转服务、轻量业务后台和运营工具。' },
      { title: '海外链路验证', description: '用于测试不同区域访问、系统兼容和业务延迟。' },
    ],
    faqs: [
      { question: '美国服务器适合国内业务吗？', answer: '如果主要用户在海外，美国服务器更合适；如果主要用户在国内，应优先评估国内或香港节点。' },
      { question: '美国服务器可以做测试环境吗？', answer: '可以，适合验证海外访问、系统部署和跨境业务流程。' },
      { question: '海外服务器选择时最先看什么？', answer: '优先看用户所在地、带宽需求、系统环境和后续运维方式。' },
    ],
    relatedLinks: [
      { label: '查看香港服务器', to: '/hong-kong-server' },
      { label: '查看云服务器', to: '/cloud-server' },
      { label: '查看帮助中心', to: '/help' },
    ],
    cta: {
      title: '部署海外业务入口',
      description: '根据目标用户区域选择节点和带宽，先小规模上线再持续优化。',
      primaryText: '查看产品配置',
      primaryTo: '/products',
      secondaryText: '注册账号',
      secondaryConsolePath: '/client/register',
    },
  },
  {
    slug: 'high-defense-server',
    path: '/high-defense-server',
    routeName: 'WwwSeoHighDefenseServer',
    keyword: '高防服务器',
    title: '高防服务器 - 面向攻击防护场景的云服务器 - 图拉云',
    description: '图拉云高防服务器适合游戏、业务接口和高风险网站等防护需求场景，提供配置选择与运维支持。',
    keywords: '高防服务器,高防云服务器,防护服务器,游戏服务器防护,图拉云',
    changefreq: 'weekly',
    priority: '0.8',
    hero: {
      eyebrow: '安全防护',
      title: '面向防护需求的高防服务器',
      summary: '适用于容易受到异常流量影响的网站、游戏和接口服务，帮助业务在风险场景下保持更清晰的资源与工单管理路径。',
      points: ['防护场景', '业务连续性', '问题追踪'],
      stats: [
        { value: '高风险业务', label: '适合游戏与接口服务' },
        { value: '可追踪', label: '通过工单沉淀问题记录' },
        { value: '可组合', label: '按服务器规格与带宽选择' },
      ],
    },
    visual: { src: '/img/website/solutions/security.svg', alt: '高防服务器防护场景示意' },
    features: [
      { title: '适配攻击风险更高的业务', description: '游戏、活动站、接口服务等更容易遇到异常流量，需要提前规划防护方案。' },
      { title: '减少排查分散', description: '资源、账单和工单在账户内集中管理，便于复盘问题。' },
      { title: '按业务承压选择配置', description: '根据业务类型、流量规模和系统负载选择服务器规格。' },
    ],
    scenarios: [
      { title: '游戏业务入口', description: '面向游戏登录、接口和官网等对可用性敏感的业务。' },
      { title: '活动与营销页面', description: '适合短期访问波动明显、需要提前准备防护预案的页面。' },
      { title: '业务接口服务', description: '承载 API、回调服务和对外接口，减少异常流量对主业务影响。' },
    ],
    faqs: [
      { question: '高防服务器和普通云服务器有什么区别？', answer: '高防服务器更偏向异常流量防护场景，普通云服务器更适合常规计算和托管需求。' },
      { question: '所有网站都需要高防服务器吗？', answer: '不一定。普通企业站通常先用常规云服务器，游戏、活动和高风险业务再评估高防需求。' },
      { question: '选择高防服务器前要准备什么？', answer: '需要明确业务类型、访问峰值、攻击风险、系统环境和可接受的恢复时间。' },
    ],
    relatedLinks: [
      { label: '查看云服务器', to: '/cloud-server' },
      { label: '查看帮助中心', to: '/help' },
      { label: '查看产品与服务', to: '/products' },
    ],
    cta: {
      title: '为高风险业务准备服务器方案',
      description: '先梳理业务峰值、攻击风险和系统配置，再选择合适资源。',
      primaryText: '查看产品配置',
      primaryTo: '/products',
      secondaryText: '提交咨询工单',
      secondaryConsolePath: '/client/tickets',
    },
  },
  {
    slug: 'cloud-pc',
    path: '/cloud-pc',
    routeName: 'WwwSeoCloudPc',
    keyword: '云电脑',
    title: '云电脑 - 远程办公与轻量桌面云方案 - 图拉云',
    description: '图拉云云电脑适合远程办公、轻量桌面、软件测试和临时工作环境，提供云端资源选择与账号管理能力。',
    keywords: '云电脑,云桌面,远程办公云电脑,桌面云,图拉云',
    changefreq: 'weekly',
    priority: '0.7',
    hero: {
      eyebrow: '云端桌面',
      title: '灵活可用的云电脑',
      summary: '为远程办公、软件测试、临时桌面和轻量操作环境提供云端资源，减少本地设备差异带来的维护成本。',
      points: ['远程访问', '轻量桌面', '账号管理'],
      stats: [
        { value: '云端运行', label: '减少本地设备依赖' },
        { value: '灵活使用', label: '适合临时与长期场景' },
        { value: '统一入口', label: '控制台管理与续费' },
      ],
    },
    visual: { src: '/img/website/solutions/government.svg', alt: '云电脑远程办公示意' },
    features: [
      { title: '降低本地设备门槛', description: '常用软件、测试环境和轻量桌面可运行在云端资源上。' },
      { title: '适合临时项目', description: '短期测试、外包协作和演示环境可以快速准备。' },
      { title: '方便账号管理', description: '通过统一账户查看服务、续费和售后记录。' },
    ],
    scenarios: [
      { title: '远程办公', description: '为员工或临时协作者提供相对统一的云端操作环境。' },
      { title: '软件测试', description: '隔离测试环境，减少对个人电脑配置和系统版本的依赖。' },
      { title: '演示与培训', description: '在短期活动、培训和客户演示中快速准备可用桌面。' },
    ],
    faqs: [
      { question: '云电脑适合哪些人使用？', answer: '适合远程办公、软件测试、临时项目、培训演示和轻量桌面使用场景。' },
      { question: '云电脑能替代所有本地电脑吗？', answer: '不一定。高性能图形、专业硬件和低延迟外设场景需要单独评估。' },
      { question: '云电脑和云服务器有什么区别？', answer: '云电脑更偏桌面操作体验，云服务器更偏业务系统和服务端应用运行。' },
    ],
    relatedLinks: [
      { label: '查看云服务器', to: '/cloud-server' },
      { label: '查看产品与服务', to: '/products' },
      { label: '查看帮助中心', to: '/help' },
    ],
    cta: {
      title: '为远程办公准备云端桌面',
      description: '先确认软件、系统和使用周期，再选择合适的云电脑资源。',
      primaryText: '查看产品配置',
      primaryTo: '/products',
      secondaryText: '注册账号',
      secondaryConsolePath: '/client/register',
    },
  },
]

export const seoLandingFooterLinks = seoLandingPages.map((page) => ({
  to: page.path,
  label: page.keyword,
}))

const DEFAULT_SITE_URL = 'https://www.example.com'
const SITE_NAME = '图拉云'
const SITE_LANGUAGE = 'zh-CN'

function normalizePath(path) {
  const value = String(path || '').trim()
  if (!value) return '/'
  const withLeadingSlash = value.startsWith('/') ? value : `/${value}`
  return withLeadingSlash === '/' ? '/' : withLeadingSlash.replace(/\/+$/, '')
}

function normalizeSiteUrl(siteUrl) {
  return String(siteUrl || DEFAULT_SITE_URL).replace(/\/+$/, '') || DEFAULT_SITE_URL
}

function absoluteUrl(siteUrl, path) {
  const normalizedSiteUrl = normalizeSiteUrl(siteUrl)
  const normalizedPath = normalizePath(path)
  return `${normalizedSiteUrl}${normalizedPath === '/' ? '/' : normalizedPath}`
}

export function getSeoLandingPageByPath(path) {
  const normalizedPath = normalizePath(path)
  return seoLandingPages.find((page) => page.path === normalizedPath) || null
}

export function buildSeoLandingStructuredData(page, siteUrl = DEFAULT_SITE_URL) {
  const normalizedSiteUrl = normalizeSiteUrl(siteUrl)
  const homeUrl = `${normalizedSiteUrl}/`
  const productsUrl = absoluteUrl(normalizedSiteUrl, '/products')
  const pageUrl = absoluteUrl(normalizedSiteUrl, page.path)
  const organizationId = `${normalizedSiteUrl}/#organization`
  const websiteId = `${normalizedSiteUrl}/#website`
  const webpageId = `${pageUrl}#webpage`

  return [
    {
      '@context': 'https://schema.org',
      '@type': 'Organization',
      '@id': organizationId,
      name: SITE_NAME,
      url: homeUrl,
      logo: absoluteUrl(normalizedSiteUrl, '/branding/logo.png'),
    },
    {
      '@context': 'https://schema.org',
      '@type': 'WebSite',
      '@id': websiteId,
      name: SITE_NAME,
      url: homeUrl,
      inLanguage: SITE_LANGUAGE,
      publisher: {
        '@id': organizationId,
      },
    },
    {
      '@context': 'https://schema.org',
      '@type': 'WebPage',
      '@id': webpageId,
      url: pageUrl,
      name: page.title,
      headline: page.hero.title,
      description: page.description,
      keywords: page.keywords,
      inLanguage: SITE_LANGUAGE,
      isPartOf: {
        '@id': websiteId,
      },
      about: {
        '@type': 'Service',
        name: page.keyword,
        description: page.hero.summary,
        provider: {
          '@id': organizationId,
        },
      },
    },
    {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: [
        {
          '@type': 'ListItem',
          position: 1,
          name: '首页',
          item: homeUrl,
        },
        {
          '@type': 'ListItem',
          position: 2,
          name: '产品与服务',
          item: productsUrl,
        },
        {
          '@type': 'ListItem',
          position: 3,
          name: page.keyword,
          item: pageUrl,
        },
      ],
    },
  ]
}

export function buildSeoLandingRouteMeta(page) {
  return {
    title: page.title,
    description: page.description,
    keywords: page.keywords,
    canonical: page.path,
    seoLandingPath: page.path,
    seoLanding: true,
    structuredData: ({ siteUrl } = {}) => buildSeoLandingStructuredData(page, siteUrl),
  }
}

export function listSeoLandingSitemapRoutes() {
  return seoLandingPages.map((page) => ({
    path: page.path,
    title: page.title,
    description: page.description,
    keywords: page.keywords,
    changefreq: page.changefreq,
    priority: page.priority,
    structuredData: (siteUrl) => buildSeoLandingStructuredData(page, siteUrl),
  }))
}
