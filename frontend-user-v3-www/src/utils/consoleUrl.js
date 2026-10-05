// 控制台地址解析。系统同时支持两种部署形态，这里按顺序尝试：
//
// 1. 多域名：显式配置 VITE_CONSOLE_SITE_URL=https://console.example.com；
// 2. 多域名兜底：VITE_PUBLIC_SITE_URL 是 https://www.example.com 时，
//    由 www. 前缀推断出 console. 子域；
// 3. 单域名子路径：控制台挂在官网同源的子路径下，如 https://example.com/console。
//    此时靠 VITE_CONSOLE_BASE_PATH（默认 /console）定位，部署在别的子路径时改这个变量。
//
// 两种形态最终都拼成「地址 + 基础路径」两段；任何一步都解析不出来时会退化成
// 同源的 /client/login —— 那条路径落在官网 SPA 上，登录和注册永远到不了控制台。
const CONSOLE_BASE_PATH = normalizeBasePath(import.meta.env.VITE_CONSOLE_BASE_PATH || '/console')

function normalizeBasePath(rawValue = '/console') {
  const trimmed = String(rawValue || '').trim()
  if (!trimmed || trimmed === '/') {
    return ''
  }
  const withLeadingSlash = trimmed.startsWith('/') ? trimmed : `/${trimmed}`
  return withLeadingSlash.replace(/\/+$/, '')
}

function currentOrigin() {
  if (typeof window === 'undefined') {
    return ''
  }
  const { protocol, hostname, port } = window.location
  return `${protocol}//${hostname}${port ? `:${port}` : ''}`
}

function normalizeConsoleOrigin() {
  const configuredOrigin = String(import.meta.env.VITE_CONSOLE_SITE_URL || '').trim().replace(/\/+$/, '')
  if (configuredOrigin) {
    return configuredOrigin
  }

  const publicSiteOrigin = String(import.meta.env.VITE_PUBLIC_SITE_URL || '').trim().replace(/\/+$/, '')
  if (publicSiteOrigin) {
    try {
      const publicUrl = new URL(publicSiteOrigin)
      if (publicUrl.hostname.startsWith('www.')) {
        publicUrl.hostname = `console.${publicUrl.hostname.slice(4)}`
        return publicUrl.toString().replace(/\/+$/, '')
      }
    } catch {
      // ignore invalid public site url and continue with runtime fallback
    }
  }

  if (typeof window === 'undefined') {
    return ''
  }

  const { protocol, hostname, port } = window.location
  if (hostname.startsWith('www.')) {
    const consoleHostname = `console.${hostname.slice(4)}`
    return `${protocol}//${consoleHostname}${port ? `:${port}` : ''}`
  }

  if (hostname === '127.0.0.1' || hostname === 'localhost') {
    const consolePort = String(import.meta.env.VITE_CONSOLE_DEV_PORT || '5173').trim()
    return `${protocol}//${hostname}${consolePort ? `:${consolePort}` : ''}`
  }

  // 单域名子路径部署（官网 = 控制台 = API 同源）：控制台只能靠子路径区分。
  const origin = currentOrigin()
  if (!origin || !CONSOLE_BASE_PATH) {
    return ''
  }

  return `${origin}${CONSOLE_BASE_PATH}`
}

function normalizeConsolePath(path = '/client/dashboard') {
  const normalized = String(path || '/client/dashboard').trim()
  return normalized.startsWith('/') ? normalized : `/${normalized}`
}

function appendQuery(url, query = {}) {
  const entries = Object.entries(query)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')

  if (!entries.length) {
    return url
  }

  const separator = url.includes('?') ? '&' : '?'
  const search = new URLSearchParams()
  entries.forEach(([key, value]) => {
    search.set(key, String(value))
  })
  return `${url}${separator}${search.toString()}`
}

export function consoleBasePath() {
  return CONSOLE_BASE_PATH
}

export function buildConsoleUrl(path = '/client/dashboard', query = {}) {
  const consolePath = normalizeConsolePath(path)
  const consoleOrigin = normalizeConsoleOrigin()

  if (!consoleOrigin) {
    return appendQuery(`${CONSOLE_BASE_PATH}${consolePath}`, query)
  }

  try {
    return appendQuery(new URL(`${consoleOrigin}${consolePath}`).toString(), query)
  } catch {
    return appendQuery(`${CONSOLE_BASE_PATH}${consolePath}`, query)
  }
}

export function isConsolePath(path = '') {
  return normalizeConsolePath(path).startsWith('/client/')
}

export function navigateToConsole(path = '/client/dashboard', query = {}) {
  const targetUrl = buildConsoleUrl(path, query)

  if (typeof window !== 'undefined') {
    window.location.assign(targetUrl)
  }

  return targetUrl
}