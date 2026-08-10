export function deriveInitials(name = '') {
  const trimmed = String(name || '').trim()

  if (!trimmed) {
    return 'IF'
  }

  const latinParts = trimmed
    .replace(/[^A-Za-z0-9\s]/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)

  if (latinParts.length >= 2) {
    return `${latinParts[0][0]}${latinParts[1][0]}`.toUpperCase()
  }

  if (latinParts.length === 1) {
    return latinParts[0].slice(0, 2).toUpperCase()
  }

  return trimmed.slice(0, 2).toUpperCase()
}

export function updateFavicon(href: string, fallbackHref: string) {
  if (typeof document === 'undefined') {
    return
  }

  let icon = document.querySelector("link[rel='icon']") as HTMLLinkElement | null

  if (!icon) {
    icon = document.createElement('link')
    icon.rel = 'icon'
    document.head.appendChild(icon)
  }

  const resolvedHref = href || fallbackHref
  icon.href = resolvedHref
  icon.type = resolvedHref.endsWith('.svg') ? 'image/svg+xml' : 'image/png'
}

export function applyDocumentTitle(pageTitle: string, baseTitle: string, faviconHref: string, fallbackFavicon: string) {
  if (typeof document === 'undefined') {
    return
  }

  document.title = pageTitle ? `${pageTitle} - ${baseTitle}` : baseTitle
  updateFavicon(faviconHref, fallbackFavicon)
}

export function syncDocumentTitle(baseTitle: string, previousBaseTitle: string, defaultSiteName: string) {
  if (typeof document === 'undefined') {
    return
  }

  const nextBaseTitle = String(baseTitle || '').trim()
  if (!nextBaseTitle) {
    return
  }

  const currentTitle = String(document.title || '').trim()
  const previousBase = String(previousBaseTitle || '').trim()

  if (!currentTitle || currentTitle === previousBase || currentTitle === defaultSiteName) {
    document.title = nextBaseTitle
    return
  }

  if (previousBase && currentTitle.endsWith(` - ${previousBase}`)) {
    document.title = `${currentTitle.slice(0, -(` - ${previousBase}`).length)} - ${nextBaseTitle}`
    return
  }

  const separatorIndex = currentTitle.indexOf(' - ')
  if (separatorIndex > 0) {
    document.title = `${currentTitle.slice(0, separatorIndex)} - ${nextBaseTitle}`
  }
}
