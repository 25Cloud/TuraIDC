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

  if (import.meta.env.DEV) {
    console.warn('[consoleUrl] 无法推断控制台地址，请显式配置 VITE_CONSOLE_SITE_URL。')
  }

  // 最后兜底仍返回空，由调用侧保留 /client/* 路径，避免误跳回官网域名。
  if (publicSiteOrigin) {
    if (import.meta.env.DEV) {
      console.warn('[consoleUrl] 控制台地址推断失败，保留 /client/* 路径，请显式配置 VITE_CONSOLE_SITE_URL。')
    }
  }

  return ''
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

export function buildConsoleUrl(path = '/client/dashboard', query = {}) {
  const consolePath = normalizeConsolePath(path)
  const consoleOrigin = normalizeConsoleOrigin()

  if (!consoleOrigin) {
    return appendQuery(consolePath, query)
  }

  try {
    return appendQuery(new URL(consolePath, `${consoleOrigin}/`).toString(), query)
  } catch {
    return appendQuery(consolePath, query)
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
