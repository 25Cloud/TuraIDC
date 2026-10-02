import assert from 'node:assert/strict'
import {
  getSeoLandingPageByPath,
  seoLandingPages,
} from '../src/data/seoLandingPages.js'
import {
  buildSeoLandingRouteMeta,
  buildSeoLandingStructuredData,
  listSeoLandingSitemapRoutes,
  seoLandingMetaPages,
} from '../src/data/seoLandingMeta.js'

const expectedPaths = [
  '/cloud-server',
  '/hong-kong-server',
  '/us-server',
  '/high-defense-server',
  '/cloud-pc',
]

// 内容数据（hero/features/scenarios 等全量文案）保留在 seoLandingPages.js，
// 由懒加载落地页组件引入，不进 entry chunk
assert.deepEqual(seoLandingPages.map((page) => page.path), expectedPaths)
assert.equal(new Set(seoLandingPages.map((page) => page.path)).size, seoLandingPages.length)

for (const page of seoLandingPages) {
  assert.equal(getSeoLandingPageByPath(page.path)?.slug, page.slug)
  assert.ok(page.description.includes(page.keyword), `${page.path} description should include keyword`)
  assert.ok(page.keywords.includes(page.keyword), `${page.path} keywords should include keyword`)
  assert.ok(page.hero?.title.includes(page.keyword), `${page.path} hero title should include keyword`)
  assert.ok(page.features.length >= 3, `${page.path} should expose feature content`)
  assert.ok(page.scenarios.length >= 3, `${page.path} should expose scenario content`)
  assert.ok(page.faqs.length >= 3, `${page.path} should expose FAQ content`)
}

// 路由 meta 数据（轻量，进入 entry）拆分在 seoLandingMeta.js，路径与内容数据保持一致
assert.deepEqual(seoLandingMetaPages.map((page) => page.path), expectedPaths)
assert.equal(seoLandingMetaPages.length, seoLandingPages.length)

for (const page of seoLandingMetaPages) {
  assert.equal(getSeoLandingPageByPath(page.path)?.slug, page.slug)
  assert.ok(page.description.includes(page.keyword), `${page.path} description should include keyword`)
  assert.ok(page.keywords.includes(page.keyword), `${page.path} keywords should include keyword`)
  assert.ok(page.heroTitle.includes(page.keyword), `${page.path} meta heroTitle should include keyword`)
  assert.ok(page.heroSummary, `${page.path} meta heroSummary should be present`)

  const meta = buildSeoLandingRouteMeta(page)
  assert.equal(meta.title, page.title)
  assert.equal(meta.description, page.description)
  assert.equal(meta.keywords, page.keywords)
  assert.equal(meta.canonical, page.path)
  assert.equal(meta.seoLandingPath, page.path)
  assert.equal(typeof meta.structuredData, 'function')

  // 站点名由运行时配置注入，未传时不得出现任何写死品牌
  const structuredData = buildSeoLandingStructuredData(page, 'https://www.example.com', {
    siteName: '星辰云',
    siteLogo: 'https://www.example.com/uploads/logo.png',
  })
  assert.ok(Array.isArray(structuredData), `${page.path} structured data should be an array`)

  const organization = structuredData.find((item) => item['@type'] === 'Organization')
  const website = structuredData.find((item) => item['@type'] === 'WebSite')
  const webpage = structuredData.find((item) => item['@type'] === 'WebPage')

  assert.ok(organization, `${page.path} should expose Organization JSON-LD`)
  assert.ok(website, `${page.path} should expose WebSite JSON-LD`)
  assert.ok(webpage, `${page.path} should expose WebPage JSON-LD`)
  assert.ok(structuredData.some((item) => item['@type'] === 'BreadcrumbList'), `${page.path} should expose BreadcrumbList JSON-LD`)

  assert.equal(organization.name, '星辰云', `${page.path} Organization name should use site config`)
  assert.equal(organization.logo, 'https://www.example.com/uploads/logo.png')
  assert.equal(website.name, '星辰云')
  assert.equal(webpage.name, `星辰云 - ${page.title}`)
}

// 未注入站点名时不应凭空造出 Organization / WebSite（避免写出空 name 的结构化数据）
{
  const structuredData = buildSeoLandingStructuredData(seoLandingMetaPages[0], 'https://www.example.com')
  assert.ok(structuredData.every((item) => item['@type'] !== 'Organization'))
  assert.ok(structuredData.every((item) => item['@type'] !== 'WebSite'))
  assert.equal(structuredData[0]['@type'], 'WebPage')
  assert.equal(structuredData[0].name, seoLandingMetaPages[0].title)
}

// SEO 动态渲染会把站点配置注入 window，结构化数据应直接复用它
{
  global.window = {
    __CW_SITE_CONFIG__: {
      site_name: '星辰云',
      site_logo: 'https://www.example.com/uploads/logo.png',
    },
  }
  try {
    const [organization] = buildSeoLandingStructuredData(seoLandingMetaPages[0], 'https://www.example.com')
    assert.equal(organization['@type'], 'Organization')
    assert.equal(organization.name, '星辰云')
    assert.equal(organization.logo, 'https://www.example.com/uploads/logo.png')
  } finally {
    delete global.window
  }
}

const sitemapRoutes = listSeoLandingSitemapRoutes()
assert.deepEqual(sitemapRoutes.map((route) => route.path), expectedPaths)

for (const route of sitemapRoutes) {
  assert.ok(route.title)
  assert.ok(route.description)
  assert.ok(route.keywords)
  assert.match(route.priority, /^0\.\d$/)
  assert.match(route.changefreq, /^(daily|weekly|monthly)$/)
  assert.equal(typeof route.structuredData, 'function')
}

console.log('seoLandingPages tests passed')
