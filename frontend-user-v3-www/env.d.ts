/// <reference types="vite/client" />

/**
 * SEO 动态渲染注入的站点配置。
 *
 * 由后端 SeoRenderService 内联到 window，值为 null 表示尚未注入；
 * 缺失或为 null 时前端回退到构建期默认值。
 */
interface Window {
  __CW_SITE_CONFIG__?: Record<string, unknown> | null
}