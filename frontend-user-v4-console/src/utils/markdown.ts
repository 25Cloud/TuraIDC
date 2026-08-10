import { createMarkdownRenderer } from '@ewyfinance/shared/content';

export const renderMarkdown = createMarkdownRenderer({
  demoteHeadings: true,
  imageAltFallback: 'image',
});
