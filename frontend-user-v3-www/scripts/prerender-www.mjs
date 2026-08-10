import { mkdir, readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { listSeoLandingSitemapRoutes } from '../src/data/seoLandingPages.js'
import { loadBuildEnv } from './build-env.mjs'

const distDir = path.resolve(process.cwd(), 'dist')
const indexPath = path.join(distDir, 'index.html')
const DEFAULT_SITE_URL = 'https://www.example.com'
loadBuildEnv()
const siteUrl = normalizeSiteUrl(process.env.VITE_PUBLIC_SITE_URL || DEFAULT_SITE_URL)

const staticRoutes = [
  {
    path: '/',
    title: '二五云 - 稳定、安全、高性价比的云服务器与 IDC 服务平台',
    description: '二五云提供云服务器、独立服务器、云电脑与 IDC 服务，覆盖香港、美国与国内多地节点。',
  },
  {
    path: '/products',
    title: '产品与服务 - 二五云',
    description: '浏览二五云云服务器、独立服务器、云电脑与 IDC 产品方案。',
  },
  {
    path: '/about',
    title: '关于我们 - 二五云',
    description: '了解二五云的 IDC 服务能力、节点覆盖和平台优势。',
  },
  {
    path: '/terms',
    title: '服务条款 - 二五云',
    description: '查看二五云服务条款。',
  },
  {
    path: '/privacy',
    title: '隐私政策 - 二五云',
    description: '查看二五云隐私政策。',
  },
  {
    path: '/notices',
    title: '官方公告 - 二五云',
    description: '查看二五云平台公告和服务通知。',
  },
  {
    path: '/help',
    title: '帮助中心 - 二五云',
    description: '查看二五云产品购买、账单支付和服务管理帮助。',
  },
]

const routes = [
  ...staticRoutes,
  ...listSeoLandingSitemapRoutes(),
]

function normalizeSiteUrl(value) {
  return String(value || '').replace(/\/+$/, '') || DEFAULT_SITE_URL
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function canonicalFor(routePath) {
  return `${siteUrl}${routePath === '/' ? '/' : routePath}`
}

function replaceTag(html, pattern, replacement) {
  if (pattern.test(html)) {
    return html.replace(pattern, replacement)
  }

  return html.replace('</head>', `  ${replacement}\n</head>`)
}

function renderStructuredData(route) {
  if (typeof route.structuredData !== 'function') return ''
  const payload = route.structuredData(siteUrl)
  if (!payload) return ''

  return `<script type="application/ld+json" id="route-structured-data">${JSON.stringify(payload)}</script>`
}

function renderRouteHtml(template, route) {
  const title = escapeHtml(route.title)
  const description = escapeHtml(route.description)
  const keywords = escapeHtml(route.keywords || '')
  const canonical = canonicalFor(route.path)
  const structuredData = renderStructuredData(route)

  let html = template
    .replace(/<title>.*?<\/title>/i, `<title>${title}</title>`)
    .replace(/<meta name="description" content=".*?" \/>/i, `<meta name="description" content="${description}" />`)
    .replace(/<meta property="og:title" content=".*?" \/>/i, `<meta property="og:title" content="${title}" />`)
    .replace(/<meta property="og:description" content=".*?" \/>/i, `<meta property="og:description" content="${description}" />`)
    .replace(/<meta property="og:url" content=".*?" \/>/i, `<meta property="og:url" content="${canonical}" />`)

  if (keywords) {
    html = html.replace(/<meta name="keywords" content=".*?" \/>/i, `<meta name="keywords" content="${keywords}" />`)
  }

  html = replaceTag(
    html,
    /<link rel="canonical" href=".*?" \/>/i,
    `<link rel="canonical" href="${canonical}" />`,
  )

  if (structuredData) {
    html = replaceTag(
      html,
      /<script type="application\/ld\+json" id="route-structured-data">.*?<\/script>/i,
      structuredData,
    )
  }

  return html
}

async function writeRoute(route, html) {
  if (route.path === '/') {
    await writeFile(indexPath, html, 'utf8')
    return
  }

  const outputDirectory = path.join(distDir, route.path.replace(/^\/+/, ''))
  await mkdir(outputDirectory, { recursive: true })
  await writeFile(path.join(outputDirectory, 'index.html'), html, 'utf8')
}

const template = await readFile(indexPath, 'utf8')

for (const route of routes) {
  await writeRoute(route, renderRouteHtml(template, route))
}

console.log(`Prerendered ${routes.length} public routes for ${siteUrl}`)
