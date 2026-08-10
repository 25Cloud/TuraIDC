const hintedOrigins = new Set<string>()
const DEFAULT_HINT_URLS = [
  'https://static.geetest.com',
  'https://gcaptcha4.geetest.com',
  'https://api.geetest.com',
  'https://qr.alipay.com',
  'https://mclient.alipay.com',
]

function getConnection() {
  if (typeof navigator === 'undefined') {
    return null
  }

  const runtimeNavigator = navigator as Navigator & {
    connection?: unknown
    mozConnection?: unknown
    webkitConnection?: unknown
  }

  return (runtimeNavigator.connection || runtimeNavigator.mozConnection || runtimeNavigator.webkitConnection || null) as {
    effectiveType?: string
    saveData?: boolean
    downlink?: number
    rtt?: number
  } | null
}

export function toOrigin(candidate: string) {
  if (!candidate || typeof window === 'undefined') {
    return ''
  }

  try {
    const url = new URL(String(candidate).trim(), window.location.origin)
    if (!['http:', 'https:'].includes(url.protocol)) {
      return ''
    }

    return url.origin
  } catch {
    return ''
  }
}

function toDnsPrefetchHref(origin: string) {
  try {
    return `//${new URL(origin).host}`
  } catch {
    return ''
  }
}

function hasHeadLink(rel: string, href: string) {
  if (typeof document === 'undefined') {
    return false
  }

  return Array.from(document.head.querySelectorAll(`link[rel="${rel}"]`))
    .some((node) => node.getAttribute('href') === href)
}

function appendHeadLink(rel: string, href: string, crossOrigin = false) {
  if (!href || typeof document === 'undefined' || hasHeadLink(rel, href)) {
    return
  }

  const link = document.createElement('link')
  link.rel = rel
  link.href = href

  if (crossOrigin) {
    link.crossOrigin = 'anonymous'
  }

  document.head.appendChild(link)
}

export function warmupOrigins(candidates: Array<string | undefined | null>) {
  if (typeof window === 'undefined') {
    return
  }

  const origins = new Set<string>()

  candidates
    .flatMap((candidate) => String(candidate || '').split(','))
    .map((candidate) => candidate.trim())
    .forEach((candidate) => {
      const origin = toOrigin(candidate)
      if (origin && origin !== window.location.origin) {
        origins.add(origin)
      }
    })

  origins.forEach((origin) => {
    appendHeadLink('dns-prefetch', toDnsPrefetchHref(origin))
    appendHeadLink('preconnect', origin, true)
  })
}

export function getNetworkProfile() {
  const connection = getConnection()
  const effectiveType = String(connection?.effectiveType || '').toLowerCase()
  const saveData = Boolean(connection?.saveData)
  const isWeakConnection = saveData || ['slow-2g', '2g', '3g'].includes(effectiveType)

  return {
    effectiveType,
    saveData,
    downlink: Number(connection?.downlink || 0),
    rtt: Number(connection?.rtt || 0),
    isWeakConnection,
  }
}

export function waitFor(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

export function primeConnectionHints({ urls = [], origins = [] }: { urls?: Array<string | undefined | null>, origins?: Array<string | undefined | null> } = {}) {
  if (typeof document === 'undefined' || typeof window === 'undefined') {
    return
  }

  const candidates = new Set<string>()

  origins.forEach((origin) => {
    const normalized = toOrigin(String(origin || ''))
    if (normalized) {
      candidates.add(normalized)
    }
  })

  urls.forEach((url) => {
    const normalized = toOrigin(String(url || ''))
    if (normalized) {
      candidates.add(normalized)
    }
  })

  candidates.forEach((origin) => {
    if (!origin || origin === window.location.origin || hintedOrigins.has(origin)) {
      return
    }

    hintedOrigins.add(origin)
    appendHeadLink('dns-prefetch', toDnsPrefetchHref(origin))
    appendHeadLink('preconnect', origin, true)
  })
}

export function initRuntimeConnectionHints(options: { apiBaseUrl?: string, urls?: Array<string | undefined | null> } = {}) {
  primeConnectionHints({
    urls: [
      options.apiBaseUrl,
      ...DEFAULT_HINT_URLS,
      ...(Array.isArray(options.urls) ? options.urls : []),
    ],
  })
}
