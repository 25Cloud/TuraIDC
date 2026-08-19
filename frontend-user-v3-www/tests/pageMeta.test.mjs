import assert from 'node:assert/strict'

import { applyRouteMeta } from '../src/utils/pageMeta.js'

const originalDocument = globalThis.document
const nodes = new Map()

globalThis.document = {
  title: '',
  head: {
    querySelector(selector) {
      return nodes.get(selector) || null
    },
    appendChild(node) {
      nodes.set(`#${node.id}`, node)
    },
  },
  createElement() {
    return {
      setAttribute() {},
    }
  },
}

applyRouteMeta(
  { meta: { title: '云服务器' } },
  { siteName: '图拉云', browserTitle: '二五云' },
)

assert.equal(globalThis.document.title, '云服务器 - 二五云')

globalThis.document = originalDocument

console.log('Page meta tests passed')
