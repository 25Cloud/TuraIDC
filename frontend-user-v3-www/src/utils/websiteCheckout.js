const WEBSITE_CHECKOUT_KEY = 'website_pending_checkout'

export function buildIdempotencyKey(prefix = 'web-checkout') {
  const randomPart = crypto.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `${prefix}-${randomPart}`
}

function encodeBase64Url(value) {
  const json = JSON.stringify(value)
  const bytes = new TextEncoder().encode(json)
  let binary = ''

  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
}

function decodeBase64Url(value) {
  const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/')
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=')
  const binary = atob(padded)
  const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0))

  return JSON.parse(new TextDecoder().decode(bytes))
}

export function encodePendingWebsiteCheckout(payload) {
  if (!payload || typeof payload !== 'object') {
    return ''
  }

  try {
    return encodeBase64Url(payload)
  } catch {
    return ''
  }
}

export function decodePendingWebsiteCheckout(value) {
  if (!value) {
    return null
  }

  try {
    const parsed = decodeBase64Url(value)
    return parsed && typeof parsed === 'object' ? parsed : null
  } catch {
    return null
  }
}

export function savePendingWebsiteCheckout(payload) {
  if (!payload || typeof payload !== 'object') return
  if (typeof sessionStorage === 'undefined') return
  sessionStorage.setItem(WEBSITE_CHECKOUT_KEY, JSON.stringify(payload))
}

export function getPendingWebsiteCheckout() {
  if (typeof sessionStorage === 'undefined') return null
  const raw = sessionStorage.getItem(WEBSITE_CHECKOUT_KEY)
  if (!raw) return null

  try {
    const parsed = JSON.parse(raw)
    return parsed && typeof parsed === 'object' ? parsed : null
  } catch {
    return null
  }
}

export function clearPendingWebsiteCheckout() {
  if (typeof sessionStorage === 'undefined') return
  sessionStorage.removeItem(WEBSITE_CHECKOUT_KEY)
}
