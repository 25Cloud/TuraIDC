import { useAppStore } from '@/stores/app'
import { applyRouteMeta } from '@/utils/pageMeta'

const DEFAULT_PUBLIC_SITE_URL = 'https://www.coyjs.cn'
const DYNAMIC_IMPORT_RELOAD_KEY = 'www-router-dynamic-import-reload'
const dynamicImportErrorPattern = /Failed to fetch dynamically imported module|Importing a module script failed/i

export function registerClientGuards(router) {
  router.afterEach((to) => {
    if (typeof window === 'undefined') {
      return
    }

    // 同步 title / description / og:* / canonical / robots，避免动态页面沿用首页 meta
    const appStore = useAppStore()
    applyRouteMeta(to, {
      siteUrl: import.meta.env.VITE_PUBLIC_SITE_URL || DEFAULT_PUBLIC_SITE_URL,
      siteName: appStore.siteName || '',
    })

    if (window.sessionStorage.getItem(DYNAMIC_IMPORT_RELOAD_KEY) === to.fullPath) {
      window.sessionStorage.removeItem(DYNAMIC_IMPORT_RELOAD_KEY)
    }
  })

  router.onError((error, to) => {
    if (typeof window === 'undefined') {
      return
    }

    const message = String(error?.message || '')
    if (!dynamicImportErrorPattern.test(message)) {
      return
    }

    const targetPath = typeof to?.fullPath === 'string'
      ? to.fullPath
      : `${window.location.pathname}${window.location.search}${window.location.hash}`

    if (window.sessionStorage.getItem(DYNAMIC_IMPORT_RELOAD_KEY) === targetPath) {
      window.sessionStorage.removeItem(DYNAMIC_IMPORT_RELOAD_KEY)
      return
    }

    window.sessionStorage.setItem(DYNAMIC_IMPORT_RELOAD_KEY, targetPath)
    window.location.assign(targetPath)
  })
}
