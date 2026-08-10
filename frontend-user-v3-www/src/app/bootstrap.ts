import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { provideGlobalConfig } from 'element-plus/es/components/config-provider/index.mjs'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import 'element-plus/es/components/message/style/css'

import App from '@/App.vue'
import { createClientRouter } from '@/app/router'
import { initClientRuntimeConnectionHints, primeClientConnectionHints } from '@/app/runtime/network'
import { useAppStore } from '@/stores/app'
import '@/assets/styles/global.scss'

const SITE_CONFIG_PREFETCH_TIMEOUT = 1200

function isHomeRoute(pathname: string) {
  return pathname === '/' || pathname === ''
}

async function preloadSiteConfig(appStore: ReturnType<typeof useAppStore>) {
  if (typeof window === 'undefined' || isHomeRoute(window.location.pathname)) {
    return false
  }

  const timeoutPromise = new Promise<void>((resolve) => {
    window.setTimeout(resolve, SITE_CONFIG_PREFETCH_TIMEOUT)
  })

  const result = await Promise.race([
    appStore.fetchSiteConfig().catch(() => undefined),
    timeoutPromise,
  ])

  return Boolean(result)
}

export function bootstrapClientApp() {
  const app = createApp(App)
  const pinia = createPinia()
  const router = createClientRouter()

  app.use(pinia)
  provideGlobalConfig({ locale: zhCn, size: 'default', zIndex: 3200 }, app, true)
  app.use(router)

  initClientRuntimeConnectionHints({
    apiBaseUrl: import.meta.env.VITE_API_BASE_URL,
  })

  const appStore = useAppStore()
  const splash = document.getElementById('app-splash')

  void preloadSiteConfig(appStore)
    .then((preloaded) => {
      if (splash) {
        splash.classList.add('fade-out')
        splash.addEventListener('transitionend', () => splash.remove(), { once: true })
        setTimeout(() => splash.remove(), 400)
      }

      app.mount('#app')

      if (typeof window === 'undefined' || window.location.pathname !== '/') {
        const primeHints = () => {
          primeClientConnectionHints({
            urls: [
              appStore.siteLogo,
              appStore.siteFavicon,
            ],
          })
        }

        if (preloaded) {
          primeHints()
          return
        }

        void appStore.fetchSiteConfig()
          .finally(primeHints)
      }
    })
}
