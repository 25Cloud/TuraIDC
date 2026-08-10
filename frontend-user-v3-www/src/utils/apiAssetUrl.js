function normalizeApiBaseUrl(value) {
  return String(value || '').trim().replace(/\/+$/, '')
}

export function resolveApiOrigin(apiBaseUrl) {
  const normalized = normalizeApiBaseUrl(apiBaseUrl)
  if (!normalized) return ''

  try {
    const url = new URL(normalized)
    return /^https?:$/.test(url.protocol) ? url.origin : ''
  } catch {
    return ''
  }
}

/**
 * API-managed uploads must retain the API origin when the WWW app is deployed
 * separately. Other relative values are static WWW assets and stay untouched.
 */
export function resolveApiAssetUrl(value, apiBaseUrl) {
  const normalized = String(value || '').trim().replace(/\\/g, '/')
  if (!normalized || /^(?:https?:)?\/\//i.test(normalized) || normalized.startsWith('data:')) {
    return normalized
  }

  const managedPath = normalized.match(/^(?:\.\/|\/+)?((?:uploads|media)\/.+)$/i)?.[1]
  if (!managedPath) {
    return normalized
  }

  const apiOrigin = resolveApiOrigin(apiBaseUrl)
  const absolutePath = `/${managedPath}`
  return apiOrigin ? `${apiOrigin}${absolutePath}` : absolutePath
}

export function rewriteApiAssetUrlsInHtml(html, apiBaseUrl) {
  return String(html || '').replace(
    /(\b(?:src|poster|href)\s*=\s*)(["'])((?:\.\/|\/+)?(?:uploads|media)\/[^"']+)\2/gi,
    (_match, prefix, quote, path) => `${prefix}${quote}${resolveApiAssetUrl(path, apiBaseUrl)}${quote}`,
  )
}
