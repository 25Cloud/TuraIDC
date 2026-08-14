import { createMarkdownRenderer } from '@turaidc/shared/content'

export const renderMarkdown = createMarkdownRenderer({
  demoteHeadings: true,
  imageAltFallback: 'image',
})
