import type { Directive } from 'vue'

/**
 * 区块进入视口时上浮淡入。
 *
 * 首页各 section 原本加载完就静止，滚动时没有任何反馈，整页读起来像一张长图。
 * 这里用 IntersectionObserver 给每个 section 加一次性入场，只做 opacity + 位移，
 * 不碰布局、不阻塞首屏：Hero 不参与（第一眼必须立即可见），只给下方区块用。
 *
 * 约定：
 * - 元素加 `reveal-init` 进入初始态，进入视口后加 `is-revealed`，样式写在 global.scss
 * - 只触发一次，进入后立刻 unobserve，避免来回滚动反复动画
 * - 系统开了「减少动态效果」时直接落到终态，不做位移
 * - 浏览器不支持 IntersectionObserver 时同样直接显示，不能因为动效把内容藏死
 */

const REVEALED_CLASS = 'is-revealed'
const INIT_CLASS = 'reveal-init'

let observer: IntersectionObserver | null = null

function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined' || !window.matchMedia) return false
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function ensureObserver(): IntersectionObserver | null {
  if (typeof window === 'undefined' || !('IntersectionObserver' in window)) return null
  if (observer) return observer

  observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue
        entry.target.classList.add(REVEALED_CLASS)
        observer?.unobserve(entry.target)
      }
    },
    // 底部预留 12%：元素刚露头一点点就触发，用户不会盯着空白等
    { rootMargin: '0px 0px -12% 0px', threshold: 0.06 },
  )

  return observer
}

export const reveal: Directive<HTMLElement> = {
  mounted(el) {
    if (prefersReducedMotion()) {
      el.classList.add(REVEALED_CLASS)
      return
    }

    el.classList.add(INIT_CLASS)

    const io = ensureObserver()
    if (!io) {
      el.classList.add(REVEALED_CLASS)
      return
    }

    io.observe(el)
  },

  unmounted(el) {
    observer?.unobserve(el)
  },
}