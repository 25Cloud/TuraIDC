/**
 * 页面级 meta 同步：在 SPA 路由切换时更新 description / og:* / canonical / robots，
 * 避免动态页面共享首页 meta 影响分享卡片与搜索引擎抓取。
 */

const META_SELECTORS = {
  description: 'meta[name="description"]',
  keywords: 'meta[name="keywords"]',
  ogTitle: 'meta[property="og:title"]',
  ogDescription: 'meta[property="og:description"]',
  ogUrl: 'meta[property="og:url"]',
  robots: 'meta[name="robots"]',
}

const CANONICAL_SELECTOR = 'link[rel="canonical"]'
const STRUCTURED_DATA_SCRIPT_ID = 'route-structured-data'

function writeMetaBySelector(selector, content) {
  if (typeof document === 'undefined') return
  let node = document.head.querySelector(selector)
  if (!node) {
    if (!content) return
    node = document.createElement(selector.startsWith('meta') ? 'meta' : 'link')
    if (selector.startsWith('meta')) {
      const [, key, value] = selector.match(/^meta\[(.+?)="(.+?)"\]$/) || []
      if (key && value) node.setAttribute(key, value)
    } else if (selector === CANONICAL_SELECTOR) {
      node.setAttribute('rel', 'canonical')
    }
    document.head.appendChild(node)
  }
  if (!content) {
    if (node.parentNode) node.parentNode.removeChild(node)
    return
  }
  if (selector === CANONICAL_SELECTOR) {
    node.setAttribute('href', content)
  } else {
    node.setAttribute('content', content)
  }
}

function writeStructuredData(structuredData) {
  if (typeof document === 'undefined') return

  let node = document.head.querySelector(`#${STRUCTURED_DATA_SCRIPT_ID}`)
  if (!structuredData) {
    if (node?.parentNode) node.parentNode.removeChild(node)
    return
  }

  if (!node) {
    node = document.createElement('script')
    node.id = STRUCTURED_DATA_SCRIPT_ID
    node.type = 'application/ld+json'
    document.head.appendChild(node)
  }

  node.textContent = JSON.stringify(structuredData)
}

/**
 * 应用页面 meta。
 * @param {Object} options
 * @param {string} [options.title]      页面标题（不含站点名后缀，站点名由 applyPageTitle 拼接）
 * @param {string} [options.description]
 * @param {string} [options.keywords]
 * @param {string} [options.canonical]  完整 canonical URL
 * @param {string} [options.ogTitle]    默认回退到 title
 * @param {string} [options.ogDescription] 默认回退到 description
 * @param {string} [options.ogUrl]      默认回退到 canonical
 * @param {string} [options.robots]     如 'noindex,nofollow'；为空则移除 robots meta
 * @param {Object|Object[]} [options.structuredData] 页面结构化数据；为空则移除
 */
export function updatePageMeta(options = {}) {
  if (typeof document === 'undefined') return

  const {
    title,
    description,
    keywords,
    canonical,
    ogTitle = title,
    ogDescription = description,
    ogUrl = canonical,
    robots,
    structuredData,
  } = options

  if (title) {
    document.title = title
  }

  writeMetaBySelector(META_SELECTORS.description, description || '')
  writeMetaBySelector(META_SELECTORS.keywords, keywords || '')
  writeMetaBySelector(META_SELECTORS.ogTitle, ogTitle || '')
  writeMetaBySelector(META_SELECTORS.ogDescription, ogDescription || '')
  writeMetaBySelector(META_SELECTORS.ogUrl, ogUrl || '')
  writeMetaBySelector(META_SELECTORS.robots, robots || '')
  writeMetaBySelector(CANONICAL_SELECTOR, canonical || '')
  writeStructuredData(structuredData || null)
}

/**
 * 根据路由 meta 与站点基础信息构建并应用 meta。
 * 仅处理 meta 中存在的字段，避免覆盖未声明的默认值。
 */
export function applyRouteMeta(to, baseConfig = {}) {
  const meta = to?.meta || {}
  const siteUrl = String(baseConfig.siteUrl || '').replace(/\/+$/, '')
  const siteName = baseConfig.browserTitle || baseConfig.siteName || ''

  const pageTitle = typeof meta.title === 'string' ? meta.title : ''
  const description = typeof meta.description === 'string' ? meta.description : ''
  const keywords = typeof meta.keywords === 'string' ? meta.keywords : ''
  const canonical = meta.canonical
    ? (siteUrl ? `${siteUrl}${meta.canonical}` : meta.canonical)
    : ''
  const robots = typeof meta.robots === 'string' ? meta.robots : ''
  const structuredData = typeof meta.structuredData === 'function'
    ? meta.structuredData({ siteUrl, route: to })
    : meta.structuredData

  // 仅当标题未包含站点名时追加后缀，避免静态页（已含完整标题）与详情页（短标题）重复拼接
  const fullTitle = pageTitle && siteName && !pageTitle.includes(siteName)
    ? `${pageTitle} - ${siteName}`
    : pageTitle

  updatePageMeta({
    title: fullTitle,
    description,
    keywords,
    canonical,
    ogTitle: fullTitle,
    ogDescription: description,
    ogUrl: canonical,
    robots,
    structuredData,
  })
}
