const WEBSITE_PENDING_COUPON_KEY = 'website_pending_coupon'
const WEBSITE_PENDING_COUPON_TTL = 30 * 60 * 1000

function canUseSessionStorage() {
  return typeof window !== 'undefined' && typeof sessionStorage !== 'undefined'
}

export function savePendingWebsiteCoupon(userCouponId) {
  if (!canUseSessionStorage()) return

  const couponId = Number(userCouponId || 0)
  if (couponId <= 0) {
    sessionStorage.removeItem(WEBSITE_PENDING_COUPON_KEY)
    return
  }

  sessionStorage.setItem(WEBSITE_PENDING_COUPON_KEY, JSON.stringify({
    user_coupon_id: couponId,
    created_at: Date.now(),
  }))
}

export function getPendingWebsiteCouponId() {
  if (!canUseSessionStorage()) return 0

  const raw = sessionStorage.getItem(WEBSITE_PENDING_COUPON_KEY)
  if (!raw) return 0

  try {
    const payload = JSON.parse(raw)
    const couponId = Number(payload?.user_coupon_id || 0)
    const createdAt = Number(payload?.created_at || 0)

    if (couponId <= 0) {
      sessionStorage.removeItem(WEBSITE_PENDING_COUPON_KEY)
      return 0
    }

    if (createdAt > 0 && (Date.now() - createdAt) > WEBSITE_PENDING_COUPON_TTL) {
      sessionStorage.removeItem(WEBSITE_PENDING_COUPON_KEY)
      return 0
    }

    return couponId
  } catch {
    sessionStorage.removeItem(WEBSITE_PENDING_COUPON_KEY)
    return 0
  }
}

export function clearPendingWebsiteCoupon() {
  if (!canUseSessionStorage()) return
  sessionStorage.removeItem(WEBSITE_PENDING_COUPON_KEY)
}

export function buildPendingCouponRedirectUrl(targetPath, userCouponId) {
  const path = String(targetPath || '/client/checkout-resume').trim() || '/client/checkout-resume'
  const couponId = Number(userCouponId || 0)

  if (couponId <= 0) {
    return path
  }

  try {
    const base = typeof window !== 'undefined' ? window.location.origin : 'http://127.0.0.1'
    const url = new URL(path, base)
    url.searchParams.set('pending_coupon_id', String(couponId))

    if (/^https?:\/\//i.test(path)) {
      return url.toString()
    }

    return `${url.pathname}${url.search}${url.hash}`
  } catch {
    const [withoutHash, hash = ''] = path.split('#')
    const separator = withoutHash.includes('?') ? '&' : '?'
    return `${withoutHash}${separator}pending_coupon_id=${encodeURIComponent(String(couponId))}${hash ? `#${hash}` : ''}`
  }
}
