import { createRouter, createWebHistory } from 'vue-router'
import { clientRoutes } from './routes'
import { registerClientGuards } from './guards'

export function createClientRouter() {
  const router = createRouter({
    history: createWebHistory(),
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
