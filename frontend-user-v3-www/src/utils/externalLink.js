export function normalizeExternalLink(value) {
  const normalized = String(value || '').trim().replace(/^`\s*|\s*`$/g, '')
  if (!normalized) return ''

  try {
    const url = new URL(normalized)
    return /^https?:$/.test(url.protocol) ? url.toString() : ''
  } catch {
    return ''
  }
}
