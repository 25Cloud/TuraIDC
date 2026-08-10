const ALLOWED_TAGS = new Set([
  'a',
  'b',
  'blockquote',
  'br',
  'code',
  'del',
  'div',
  'em',
  'h1',
  'h2',
  'h3',
  'h4',
  'h5',
  'h6',
  'hr',
  'i',
  'img',
  'li',
  'ol',
  'p',
  'pre',
  's',
  'span',
  'strong',
  'sub',
  'sup',
  'table',
  'tbody',
  'td',
  'th',
  'thead',
  'tr',
  'u',
  'ul',
])

const DROP_TAGS = new Set([
  'base',
  'embed',
  'form',
  'iframe',
  'link',
  'meta',
  'object',
  'script',
  'style',
  'svg',
])

const ALLOWED_ATTRS = new Set([
  'alt',
  'class',
  'colspan',
  'decoding',
  'height',
  'href',
  'id',
  'loading',
  'rel',
  'referrerpolicy',
  'rowspan',
  'src',
  'target',
  'title',
  'width',
])

const URI_ATTRS = new Set(['href', 'src'])
const URL_PROTOCOLS = new Set(['http:', 'https:', 'mailto:', 'tel:'])
const IMAGE_PROTOCOLS = new Set(['http:', 'https:'])

function normalizedUrl(value) {
  return String(value || '').trim().replace(/[\u0000-\u001F\u007F\s]+/g, '')
}

function isSafeUrl(value, tagName, attrName) {
  const url = normalizedUrl(value)

  if (!url) return false
  if (/^(javascript|vbscript|file|data):/i.test(url)) return false
  if (url.startsWith('#') || url.startsWith('/') || url.startsWith('./') || url.startsWith('../')) return true

  try {
    const parsed = new URL(url, typeof window !== 'undefined' ? window.location.origin : 'https://example.invalid')
    const allowedProtocols = tagName === 'img' || attrName === 'src' ? IMAGE_PROTOCOLS : URL_PROTOCOLS

    return allowedProtocols.has(parsed.protocol)
  } catch {
    return false
  }
}

function cleanAttribute(element, attr) {
  const tagName = element.tagName.toLowerCase()
  const attrName = attr.name.toLowerCase()

  if (attrName.startsWith('on') || !ALLOWED_ATTRS.has(attrName)) {
    element.removeAttribute(attr.name)
    return
  }

  if (URI_ATTRS.has(attrName) && !isSafeUrl(attr.value, tagName, attrName)) {
    element.removeAttribute(attr.name)
    return
  }

  if ((attrName === 'width' || attrName === 'height' || attrName === 'colspan' || attrName === 'rowspan') && !/^\d{1,4}$/.test(String(attr.value))) {
    element.removeAttribute(attr.name)
    return
  }

  if (attrName === 'loading' && !['lazy', 'eager'].includes(String(attr.value).toLowerCase())) {
    element.removeAttribute(attr.name)
    return
  }

  if (attrName === 'decoding' && !['async', 'sync', 'auto'].includes(String(attr.value).toLowerCase())) {
    element.removeAttribute(attr.name)
    return
  }

  if (attrName === 'referrerpolicy' && !['no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin'].includes(String(attr.value).toLowerCase())) {
    element.removeAttribute(attr.name)
  }
}

function sanitizeElement(element, imageAltFallback) {
  const tagName = element.tagName.toLowerCase()

  if (DROP_TAGS.has(tagName)) {
    element.remove()
    return
  }

  if (!ALLOWED_TAGS.has(tagName)) {
    walk(element, imageAltFallback)
    element.replaceWith(...Array.from(element.childNodes))
    return
  }

  for (const attr of Array.from(element.attributes)) {
    cleanAttribute(element, attr)
  }

  if (tagName === 'a') {
    element.setAttribute('target', '_blank')
    element.setAttribute('rel', 'noopener noreferrer nofollow')
  }

  if (tagName === 'img' && !element.getAttribute('alt')) {
    element.setAttribute('alt', imageAltFallback)
  }

  if (tagName === 'img') {
    element.setAttribute('loading', 'lazy')
    element.setAttribute('decoding', 'async')
    element.setAttribute('referrerpolicy', 'no-referrer')
  }
}

function walk(node, imageAltFallback) {
  for (const child of Array.from(node.childNodes)) {
    if (child.nodeType === Node.ELEMENT_NODE) {
      sanitizeElement(child, imageAltFallback)
      if (child.parentNode) {
        walk(child, imageAltFallback)
      }
    } else if (child.nodeType !== Node.TEXT_NODE) {
      child.remove()
    }
  }
}

export function sanitizeRenderedHtml(html, options = {}) {
  const source = String(html || '')

  if (!source || typeof DOMParser === 'undefined' || typeof Node === 'undefined') {
    return source
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
  }

  const imageAltFallback = String(options.imageAltFallback || 'image').trim() || 'image'
  const document = new DOMParser().parseFromString(source, 'text/html')

  walk(document.body, imageAltFallback)

  return document.body.innerHTML
}
