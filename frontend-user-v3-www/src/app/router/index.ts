import { createRouter, createWebHashHistory, createWebHistory } from 'vue-router'
import { clientRoutes } from './routes'
import { registerClientGuards } from './guards'

/**
 * 站点基础路径：生产按后端下发的 VITE_BASE_URL（允许 /user 这种子路径），演示站同源；
 * 演示站改用 hash 路由，让静态托管下的 deep link 不依赖服务端回退。
 */
const routerBase = import.meta.env.VITE_BASE_URL || '/'

export function createClientRouter() {
  const router = createRouter({
    history: import.meta.env.VITE_DEMO_MODE === 'true'
      ? createWebHashHistory(routerBase)
      : createWebHistory(routerBase),
    routes: clientRoutes,
    scrollBehavior(_to, _from, savedPosition) {
      if (savedPosition) {
        return savedPosition
      }

      const prefersReducedMotion = typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches
      const isMobile = typeof window !== 'undefined' && window.innerWidth <= 768

      return {
        top: 0,
        behavior: (prefersReducedMotion || isMobile) ? 'auto' : 'smooth',
      }
    },
  })

  registerClientGuards(router)
  return router
}
