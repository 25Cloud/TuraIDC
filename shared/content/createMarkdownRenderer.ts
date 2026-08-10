import MarkdownIt from 'markdown-it'
import type { RenderRule } from 'markdown-it/lib/renderer.mjs'
import type StateCore from 'markdown-it/lib/rules_core/state_core.mjs'
import { sanitizeRenderedHtml } from './htmlSanitizer'

export interface MarkdownRendererOptions {
  demoteHeadings?: boolean
  imageAltFallback?: string
}

export interface RenderMarkdownOptions {
  imageAltFallback?: string
}

export function createMarkdownRenderer(options: MarkdownRendererOptions = {}) {
  const markdown = new MarkdownIt({
    html: true,
    linkify: true,
    breaks: true,
  })

  const defaultValidateLink = markdown.validateLink

  markdown.validateLink = (url: string) => {
    const value = String(url || '').trim()
    const normalized = value.replace(/[\u0000-\u001F\u007F\s]+/g, '')

    if (/^(javascript|vbscript|file|data):/i.test(normalized)) {
      return false
    }

    return defaultValidateLink(value)
  }

  if (options.demoteHeadings) {
    markdown.core.ruler.after('block', 'demote_headings', (state: StateCore) => {
      for (const token of state.tokens) {
        if (token.type !== 'heading_open' && token.type !== 'heading_close') {
          continue
        }

        const currentLevel = Number.parseInt(String(token.tag || 'h2').replace(/\D/g, ''), 10) || 2
        const shifted = Math.min(6, Math.max(2, currentLevel + 1))
        token.tag = `h${shifted}`
      }
    })
  }

  const defaultImageRule = markdown.renderer.rules.image
  const imageRule: RenderRule = (tokens, idx, renderOptions, env, self) => {
    const token = tokens[idx]
    const altIndex = token.attrIndex('alt')
    const altFromAttr = altIndex >= 0 ? (token.attrs?.[altIndex]?.[1] || '') : ''
    const altFromChildren = token.children && token.children.length
      ? token.children.map((child) => child.content || '').join('').trim()
      : ''
    const fallback = String((env as RenderMarkdownOptions | undefined)?.imageAltFallback || options.imageAltFallback || 'image').trim() || 'image'
    const resolvedAlt = (altFromAttr || altFromChildren || fallback).trim() || 'image'

    if (altIndex < 0) {
      token.attrPush(['alt', resolvedAlt])
    } else if (token.attrs) {
      token.attrs[altIndex][1] = resolvedAlt
    }

    if (defaultImageRule) {
      return defaultImageRule(tokens, idx, renderOptions, env, self)
    }

    return self.renderToken(tokens, idx, renderOptions)
  }
  markdown.renderer.rules.image = imageRule

  const defaultLinkOpenRule = markdown.renderer.rules.link_open
  const linkOpenRule: RenderRule = (tokens, idx, renderOptions, env, self) => {
    const token = tokens[idx]
    const targetIndex = token.attrIndex('target')

    if (targetIndex < 0) {
      token.attrPush(['target', '_blank'])
    } else if (token.attrs) {
      token.attrs[targetIndex][1] = '_blank'
    }

    const relIndex = token.attrIndex('rel')
    if (relIndex < 0) {
      token.attrPush(['rel', 'noopener noreferrer nofollow'])
    } else if (token.attrs) {
      token.attrs[relIndex][1] = 'noopener noreferrer nofollow'
    }

    if (defaultLinkOpenRule) {
      return defaultLinkOpenRule(tokens, idx, renderOptions, env, self)
    }

    return self.renderToken(tokens, idx, renderOptions)
  }
  markdown.renderer.rules.link_open = linkOpenRule

  return function renderMarkdown(content = '', renderOptions: RenderMarkdownOptions = {}) {
    const source = String(content || '').trim()

    if (!source) {
      return ''
    }

    const env = {
      imageAltFallback: String(renderOptions.imageAltFallback || options.imageAltFallback || 'image').trim() || 'image',
    }

    return sanitizeRenderedHtml(markdown.render(source, env), env)
  }
}
