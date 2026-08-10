/**
 * 前端运行时可观测基础设施。
 * 两端共享：错误采集、上报字段构造、资源加载失败恢复策略。
 */

export interface RuntimeErrorReport {
  app_name: string
  app_version: string
  route: string
  user_id: string | null
  request_id: string | null
  trace_id: string | null
  browser: string
  network_status: string
  message: string
  stack: string
  timestamp: number
}

let reportHandler: ((report: RuntimeErrorReport) => void) | null = null

export function setErrorReportHandler(handler: (report: RuntimeErrorReport) => void) {
  reportHandler = handler
}

function detectBrowser(): string {
  if (typeof navigator === 'undefined') return 'unknown'
  const ua = navigator.userAgent || ''
  if (ua.includes('Edg/')) return 'Edge'
  if (ua.includes('Chrome/')) return 'Chrome'
  if (ua.includes('Firefox/')) return 'Firefox'
  if (ua.includes('Safari/') && !ua.includes('Chrome/')) return 'Safari'
  return 'Other'
}

function detectNetworkStatus(): string {
  if (typeof navigator === 'undefined') return 'unknown'
  if (!navigator.onLine) return 'offline'
  const conn = (navigator as any).connection
  if (conn) {
    return `${conn.effectiveType || 'unknown'}/${conn.saveData ? 'savedata' : 'normal'}`
  }
  return 'online'
}

export function buildErrorReport(overrides: Partial<RuntimeErrorReport> = {}): RuntimeErrorReport {
  return {
    app_name: overrides.app_name || '',
    app_version: overrides.app_version || '',
    route: overrides.route || (typeof window !== 'undefined' ? window.location.pathname : ''),
    user_id: overrides.user_id || null,
    request_id: overrides.request_id || null,
    trace_id: overrides.trace_id || null,
    browser: detectBrowser(),
    network_status: detectNetworkStatus(),
    message: overrides.message || '',
    stack: overrides.stack || '',
    timestamp: Date.now(),
  }
}

export function reportError(report: RuntimeErrorReport) {
  if (reportHandler) {
    try {
      reportHandler(report)
    } catch {
      // 上报自身出错静默
    }
  }
  if (import.meta.env.DEV) {
    console.error('[RuntimeMonitor]', report)
  }
}

const DYNAMIC_IMPORT_ERROR_PATTERN = /Failed to fetch dynamically imported module|Importing a module script failed/i
const RELOAD_SESSION_KEY = 'route-dynamic-import-reload'

export function isDynamicImportError(error: unknown): boolean {
  const message = String((error as any)?.message || '')
  return DYNAMIC_IMPORT_ERROR_PATTERN.test(message)
}

export function shouldReloadForDynamicImportError(targetPath: string): boolean {
  if (typeof window === 'undefined' || typeof sessionStorage === 'undefined') return false
  if (sessionStorage.getItem(RELOAD_SESSION_KEY) === targetPath) {
    sessionStorage.removeItem(RELOAD_SESSION_KEY)
    return false
  }
  sessionStorage.setItem(RELOAD_SESSION_KEY, targetPath)
  return true
}

export function clearDynamicImportReloadRecord(targetPath: string) {
  if (typeof window === 'undefined' || typeof sessionStorage === 'undefined') return
  if (sessionStorage.getItem(RELOAD_SESSION_KEY) === targetPath) {
    sessionStorage.removeItem(RELOAD_SESSION_KEY)
  }
}

export function getAppVersion(): string {
  try {
    return (import.meta as any).env?.VITE_APP_VERSION || '0.0.0'
  } catch {
    return '0.0.0'
  }
}
