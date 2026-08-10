export interface SanitizeRenderedHtmlOptions {
  imageAltFallback?: string
}

export function sanitizeRenderedHtml(html?: unknown, options?: SanitizeRenderedHtmlOptions): string
