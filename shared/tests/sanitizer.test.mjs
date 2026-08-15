/**
 * HTML Sanitizer 安全回归测试
 * 运行: node --experimental-strip-types ./tests/sanitizer.test.mjs
 */
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'

// 注入 JSDOM 的 DOMParser 和 Node
const dom = new JSDOM('<!DOCTYPE html>')
globalThis.DOMParser = dom.window.DOMParser
globalThis.Node = dom.window.Node

// 动态导入 sanitizer（依赖 DOMParser 全局变量）
const { sanitizeRenderedHtml } = await import('../htmlSanitizer.js')

// 1. script 标签应被移除
{
  const result = sanitizeRenderedHtml('<p>Hello</p><script>alert("xss")</script>')
  assert.equal(result.includes('<script>'), false, 'script 标签应被移除')
  assert.equal(result.includes('alert'), false, 'script 内容应被移除')
}

// 2. iframe 应被移除
{
  const result = sanitizeRenderedHtml('<p>Text</p><iframe src="evil.com"></iframe>')
  assert.equal(result.includes('<iframe'), false, 'iframe 应被移除')
  assert.equal(result.includes('evil.com'), false, 'iframe src 应被移除')
}

// 3. style 标签应被移除
{
  const result = sanitizeRenderedHtml('<style>body { color: red; }</style><p>OK</p>')
  assert.equal(result.includes('<style>'), false, 'style 标签应被移除')
}

// 4. on* 事件属性应被移除
{
  const result = sanitizeRenderedHtml('<p onclick="alert(1)">Click me</p>')
  assert.equal(result.includes('onclick'), false, 'onclick 事件属性应被移除')
}

{
  const result = sanitizeRenderedHtml('<div onmouseover="evil()">Hover</div>')
  assert.equal(result.includes('onmouseover'), false, 'onmouseover 事件属性应被移除')
}

// 5. javascript: 协议应被移除
{
  const result = sanitizeRenderedHtml('<a href="javascript:alert(1)">Link</a>')
  assert.equal(result.includes('javascript:'), false, 'javascript: 协议应被移除')
}

// 6. data: 协议应被移除
{
  const result = sanitizeRenderedHtml('<a href="data:text/html,<script>alert(1)</script>">Link</a>')
  assert.equal(result.includes('data:'), false, 'data: 协议应被移除')
}

// 7. 合法标签应保留
{
  const result = sanitizeRenderedHtml('<p><strong>Bold</strong> text</p>')
  assert.ok(result.includes('<p>'), '正常 p 标签应保留')
  assert.ok(result.includes('<strong>'), '正常 strong 标签应保留')
}

// 8. 合法 a 标签应保留并添加 target/rel
{
  const result = sanitizeRenderedHtml('<a href="https://example.com">Link</a>')
  assert.ok(result.includes('https://example.com'), '合法 href 应保留')
  assert.ok(result.includes('target="_blank"'), '应添加 target="_blank"')
  assert.ok(result.includes('rel="noopener'), '应添加 rel 属性')
}

// 9. 合法 img 标签应保留
{
  const result = sanitizeRenderedHtml('<img src="https://example.com/img.png" alt="test">')
  assert.ok(result.includes('https://example.com/img.png'), '合法 img src 应保留')
  assert.ok(result.includes('loading="lazy"'), 'img 应默认懒加载')
  assert.ok(result.includes('decoding="async"'), 'img 应默认异步解码')
  assert.ok(result.includes('referrerpolicy="no-referrer"'), 'img 应默认隐藏来源页')
}

// 10. 无 alt 的 img 应添加默认 alt
{
  const result = sanitizeRenderedHtml('<img src="https://example.com/img.png">')
  assert.ok(result.includes('alt="image"'), '无 alt 的 img 应添加默认 alt')
}

// 11. 空输入不应报错
{
  assert.equal(sanitizeRenderedHtml(''), '', '空输入应返回空字符串')
  assert.equal(sanitizeRenderedHtml(null), '', 'null 输入应返回空字符串')
}

// 12. 无 DOMParser 时返回原始输入
{
  const backup = globalThis.DOMParser
  globalThis.DOMParser = undefined
  const result = sanitizeRenderedHtml('<p>Raw</p>')
  assert.equal(result, '&lt;p&gt;Raw&lt;/p&gt;', '无 DOMParser 时应转义 HTML')
  globalThis.DOMParser = backup
}

// 13. 嵌套 svg 应被移除
{
  const result = sanitizeRenderedHtml('<svg><circle /></svg>')
  assert.equal(result.includes('<svg'), false, 'svg 应被移除')
}

// 14. form 元素应被移除
{
  const result = sanitizeRenderedHtml('<form action="evil"><input name="pwd"></form><p>OK</p>')
  assert.equal(result.includes('<form'), false, 'form 应被移除')
  assert.equal(result.includes('<input'), false, 'input 应被移除')
}

// 15. embed/object 应被移除
{
  const result = sanitizeRenderedHtml('<embed src="evil.swf"><object data="evil"></object>')
  assert.equal(result.includes('<embed'), false, 'embed 应被移除')
  assert.equal(result.includes('<object'), false, 'object 应被移除')
}

// 16. 合法 markdown 渲染结果应保留
{
  const mdContent = '<h1>Title</h1><p>Some <strong>bold</strong> text with <a href="https://example.com">link</a>.</p><ul><li>Item 1</li><li>Item 2</li></ul>'
  const result = sanitizeRenderedHtml(mdContent)
  assert.ok(result.includes('<h1>'), 'h1 应保留')
  assert.ok(result.includes('<ul>'), 'ul 应保留')
  assert.ok(result.includes('<li>'), 'li 应保留')
}

// 17. 表格标签应保留
{
  const result = sanitizeRenderedHtml('<table><thead><tr><th>Col</th></tr></thead><tbody><tr><td>Val</td></tr></tbody></table>')
  assert.ok(result.includes('<table>'), 'table 应保留')
  assert.ok(result.includes('<th>'), 'th 应保留')
  assert.ok(result.includes('<td>'), 'td 应保留')
}

// 18. colSpan/rowSpan 合法值应保留
{
  const result = sanitizeRenderedHtml('<table><tr><td colspan="2" rowspan="3">Cell</td></tr></table>')
  assert.ok(result.includes('colspan="2"'), '合法 colspan 应保留')
  assert.ok(result.includes('rowspan="3"'), '合法 rowspan 应保留')
}

// 19. 相对路径和锚点链接应保留
{
  const result = sanitizeRenderedHtml('<a href="/page">Page</a><a href="#section">Section</a>')
  assert.ok(result.includes('/page'), '相对路径应保留')
  assert.ok(result.includes('#section'), '锚点应保留')
}

console.log('sanitizer tests passed')
