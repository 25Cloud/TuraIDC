import { computed, ref } from 'vue'
import { useSiteBrandingStore } from '@/app/stores/siteBranding'

// 全站商品展示方式：list=商品表格（列表式）/ card=商品卡片（卡片式）。
//
// 与魔方的 cookie("cart_theme") 同构：后台设定的是「站点默认」，用户可以用
// cookie 临时改一次形态。不落 users 表——展示偏好不是账号资产，换设备重新
// 落在默认值上是可接受的行为，也省掉一张表和一条写入链路。
const COOKIE_NAME = 'product_display_mode'
const COOKIE_MAX_AGE = 365 * 24 * 60 * 60

export const DISPLAY_MODE_LIST = 'list'
export const DISPLAY_MODE_CARD = 'card'

function normalizeMode(value) {
  return String(value ?? '')
    .trim()
    .toLowerCase() === DISPLAY_MODE_CARD
    ? DISPLAY_MODE_CARD
    : DISPLAY_MODE_LIST
}

function readCookie() {
  if (typeof document === 'undefined') {
    return ''
  }

  for (const part of document.cookie.split(';')) {
    const [name, ...rest] = part.split('=')
    if (name.trim() !== COOKIE_NAME) {
      continue
    }

    const value = decodeURIComponent(rest.join('=').trim()).toLowerCase()
    // 只认合法取值：手工改过 cookie 或被旧版本写入脏值时回落到站点默认
    return value === DISPLAY_MODE_CARD || value === DISPLAY_MODE_LIST
      ? value
      : ''
  }

  return ''
}

function writeCookie(mode) {
  if (typeof document === 'undefined') {
    return
  }

  const secure = location.protocol === 'https:' ? '; Secure' : ''
  document.cookie = `${COOKIE_NAME}=${encodeURIComponent(mode)}; Path=/; Max-Age=${COOKIE_MAX_AGE}; SameSite=Lax${secure}`
}

export function useProductDisplayMode() {
  const siteBranding = useSiteBrandingStore()
  // 模块内共享：同一次浏览里切换一次，所有调用方一起变
  const override = ref(readCookie())

  const siteMode = computed(() => normalizeMode(siteBranding.productDisplayMode))
  const hasOverride = computed(() => override.value !== '')
  const displayMode = computed(() =>
    override.value === '' ? siteMode.value : override.value,
  )
  const alternateMode = computed(() =>
    displayMode.value === DISPLAY_MODE_LIST
      ? DISPLAY_MODE_CARD
      : DISPLAY_MODE_LIST,
  )

  function setMode(mode) {
    override.value = normalizeMode(mode)
    writeCookie(override.value)
  }

  function useSiteDefault() {
    override.value = ''
    if (typeof document === 'undefined') {
      return
    }

    document.cookie = `${COOKIE_NAME}=; Path=/; Max-Age=0; SameSite=Lax`
  }

  return {
    displayMode,
    siteMode,
    hasOverride,
    alternateMode,
    setMode,
    useSiteDefault,
    toggle: () => setMode(alternateMode.value),
  }
}