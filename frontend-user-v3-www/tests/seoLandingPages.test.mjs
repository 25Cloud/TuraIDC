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
  assert.ok(page.title.includes('图拉云'), `${page.path} title should include brand`)
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
  assert.ok(page.title.includes('图拉云'), `${page.path} title should include brand`)
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

  const structuredData = buildSeoLandingStructuredData(page, 'https://www.coyjs.cn')
  assert.ok(Array.isArray(structuredData), `${page.path} structured data should be an array`)
  assert.ok(structuredData.some((item) => item['@type'] === 'Organization'), `${page.path} should expose Organization JSON-LD`)
  assert.ok(structuredData.some((item) => item['@type'] === 'WebSite'), `${page.path} should expose WebSite JSON-LD`)
  assert.ok(structuredData.some((item) => item['@type'] === 'WebPage'), `${page.path} should expose WebPage JSON-LD`)
  assert.ok(structuredData.some((item) => item['@type'] === 'BreadcrumbList'), `${page.path} should expose BreadcrumbList JSON-LD`)
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
