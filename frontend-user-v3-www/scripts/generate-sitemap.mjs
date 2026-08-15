import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { listSeoLandingSitemapRoutes } from '../src/data/seoLandingMeta.js'
import { loadBuildEnv } from './build-env.mjs'

const distDir = path.resolve(process.cwd(), 'dist')
const DEFAULT_SITE_URL = 'https://www.coyjs.cn'
loadBuildEnv()
const siteUrl = normalizeSiteUrl(process.env.VITE_PUBLIC_SITE_URL || DEFAULT_SITE_URL)

const staticPublicRoutes = [
  { path: '/', priority: '1.0', changefreq: 'daily' },
  { path: '/products', priority: '0.9', changefreq: 'daily' },
  { path: '/about', priority: '0.6', changefreq: 'monthly' },
  { path: '/terms', priority: '0.4', changefreq: 'yearly' },
  { path: '/privacy', priority: '0.4', changefreq: 'yearly' },
  { path: '/notices', priority: '0.7', changefreq: 'daily' },
  { path: '/help', priority: '0.7', changefreq: 'weekly' },
]

const publicRoutes = [
  ...staticPublicRoutes,
  ...listSeoLandingSitemapRoutes(),
]

function normalizeSiteUrl(value) {
  return String(value || '').replace(/\/+$/, '') || DEFAULT_SITE_URL
}

function urlFor(routePath) {
  return `${siteUrl}${routePath === '/' ? '/' : routePath}`
}

function renderSitemap() {
  const urls = publicRoutes.map((route) => `  <url>
    <loc>${urlFor(route.path)}</loc>
    <changefreq>${route.changefreq}</changefreq>
    <priority>${route.priority}</priority>
  </url>`).join('\n')

  return `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls}
</urlset>
`
}

function renderRobots() {
  return `User-agent: *
Allow: /
Disallow: /client/
Disallow: /console/
Sitemap: ${siteUrl}/sitemap.xml
`
}

await mkdir(distDir, { recursive: true })
await writeFile(path.join(distDir, 'sitemap.xml'), renderSitemap(), 'utf8')
await writeFile(path.join(distDir, 'robots.txt'), renderRobots(), 'utf8')

console.log(`Generated sitemap.xml and robots.txt for ${siteUrl}`)
