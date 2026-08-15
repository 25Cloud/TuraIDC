const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'scroll', 'focus']
const ACTIVITY_TOUCH_INTERVAL_MS = 1000

export interface SessionDriverOptions {
  tokenKey: string
  lastActiveKey: string
  idleTimeoutMs?: number
  /**
   * Cookie 作用域。设置后会话信息写入 Cookie 而非 localStorage，
   * 以便在同一主域的不同子域 / 端口间共享登录态。
   * 例如生产环境传 `.coyjs.cn`，开发环境可留空（同主机不同端口的 Cookie 默认共享）。
   */
  cookieDomain?: string
}

export interface SessionDriver {
  getToken: () => string | null
  setToken: (_token: string) => void
  removeToken: () => void
  isLoggedIn: () => boolean
  isSessionExpired: () => boolean
  touchSessionActivity: () => void
  readStorageItem: (_key: string) => string
}

const COOKIE_MAX_AGE_SECONDS = 30 * 24 * 60 * 60

function readCookie(name: string) {
  if (typeof document === 'undefined') {
    return ''
  }

  const prefix = `${encodeURIComponent(name)}=`
  const segments = document.cookie ? document.cookie.split('; ') : []
  for (const segment of segments) {
    if (segment.startsWith(prefix)) {
      return decodeURIComponent(segment.slice(prefix.length))
    }
  }

  return ''
}

function writeCookie(name: string, value: string, cookieDomain?: string) {
  if (typeof document === 'undefined') {
    return
  }

  const secure = typeof window !== 'undefined' && window.location.protocol === 'https:'
  const parts = [
    `${encodeURIComponent(name)}=${encodeURIComponent(value)}`,
    'path=/',
    `max-age=${COOKIE_MAX_AGE_SECONDS}`,
    'SameSite=Lax',
  ]
  if (cookieDomain) {
    parts.push(`domain=${cookieDomain}`)
  }
  if (secure) {
    parts.push('Secure')
  }

  document.cookie = parts.join('; ')
}

function deleteCookie(name: string, cookieDomain?: string) {
  if (typeof document === 'undefined') {
    return
  }

  const parts = [
    `${encodeURIComponent(name)}=`,
    'path=/',
    'max-age=0',
    'SameSite=Lax',
  ]
  if (cookieDomain) {
    parts.push(`domain=${cookieDomain}`)
  }

  document.cookie = parts.join('; ')
}

function readStorageItem(_key: string) {
  if (typeof window === 'undefined') {
    return ''
  }

  return window.localStorage.getItem(_key) || ''
}

export function createSessionDriver(options: SessionDriverOptions): SessionDriver {
  const idleTimeoutMs = Number(options.idleTimeoutMs || 3 * 60 * 60 * 1000)
  const cookieDomain = options.cookieDomain || undefined

  function readSession(key: string) {
    const fromCookie = readCookie(key)
    if (fromCookie) {
      return fromCookie
    }
    // 平滑迁移：历史登录态存于 localStorage，读到后回写 Cookie
    const legacy = readStorageItem(key)
    if (legacy) {
      writeCookie(key, legacy, cookieDomain)
      window.localStorage.removeItem(key)
      return legacy
    }
    return ''
  }

  function getLastActiveAt() {
    const raw = readSession(options.lastActiveKey)
    const timestamp = Number(raw)

    return Number.isFinite(timestamp) && timestamp > 0 ? timestamp : 0
  }

  function setLastActiveAt(timestamp = Date.now()) {
    writeCookie(options.lastActiveKey, String(timestamp), cookieDomain)
  }

  function clearStoredToken() {
    deleteCookie(options.tokenKey, cookieDomain)
    deleteCookie(options.lastActiveKey, cookieDomain)
    // 清理历史遗留的 localStorage 数据，避免新旧机制并存
    if (typeof window !== 'undefined') {
      window.localStorage.removeItem(options.tokenKey)
      window.localStorage.removeItem(options.lastActiveKey)
    }
  }

  function isSessionExpired() {
    const storedToken = readSession(options.tokenKey)

    if (!storedToken) {
      return false
    }

    const lastActiveAt = getLastActiveAt()

    if (!lastActiveAt) {
      setLastActiveAt()
      return false
    }

    return Date.now() - lastActiveAt >= idleTimeoutMs
  }

  function getToken() {
    const storedToken = readSession(options.tokenKey)

    if (!storedToken) {
      return null
    }

    if (isSessionExpired()) {
      clearStoredToken()
      return null
    }

    if (!getLastActiveAt()) {
      setLastActiveAt()
    }

    return storedToken
  }

  function setToken(token: string) {
    if (!token || typeof token !== 'string') {
      return
    }

    writeCookie(options.tokenKey, token, cookieDomain)
    setLastActiveAt()
  }

  function touchSessionActivity() {
    if (!readSession(options.tokenKey)) {
      return
    }

    if (isSessionExpired()) {
      clearStoredToken()
      return
    }

    setLastActiveAt()
  }

  function removeToken() {
    clearStoredToken()
  }

  function isLoggedIn() {
    return !!getToken()
  }

  return {
    getToken,
    setToken,
    removeToken,
    isLoggedIn,
    isSessionExpired,
    touchSessionActivity,
    readStorageItem,
  }
}

const initializedTrackers = new Set<string>()
const pendingTouchTimers = new Map<string, number>()

export function initSessionActivityTracking(key: string, touch: () => void) {
  if (initializedTrackers.has(key) || typeof window === 'undefined' || typeof document === 'undefined') {
    return
  }

  const scheduleTouch = () => {
    if (pendingTouchTimers.has(key)) {
      return
    }

    const timer = window.setTimeout(() => {
      pendingTouchTimers.delete(key)
      touch()
    }, ACTIVITY_TOUCH_INTERVAL_MS)

    pendingTouchTimers.set(key, timer)
  }

  ACTIVITY_EVENTS.forEach((eventName) => {
    window.addEventListener(eventName, scheduleTouch, { passive: true })
  })

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      scheduleTouch()
    }
  })

  initializedTrackers.add(key)
}
