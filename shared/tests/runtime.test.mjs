import assert from 'node:assert/strict'

import { deriveInitials } from '../runtime/branding.ts'
import { createSessionDriver } from '../runtime/session.ts'

assert.equal(deriveInitials('图拉云'), '图拉')
assert.equal(deriveInitials('Cloud Union'), 'CU')

const storage = new Map()
const cookies = new Map()
const cookieWrites = []

const documentMock = {}
Object.defineProperty(documentMock, 'cookie', {
  get() {
    return Array.from(cookies.entries())
      .map(([key, value]) => `${key}=${value}`)
      .join('; ')
  },
  set(value) {
    cookieWrites.push(String(value))
    const [pair, ...parts] = String(value).split('; ')
    const [rawKey, rawValue = ''] = pair.split('=')
    const key = decodeURIComponent(rawKey)
    const maxAgePart = parts.find((part) => part.startsWith('max-age='))
    if (maxAgePart === 'max-age=0') {
      cookies.delete(key)
      return
    }
    cookies.set(key, decodeURIComponent(rawValue))
  },
})

global.window = {
  location: { protocol: 'http:' },
  localStorage: {
    getItem(key) {
      return storage.get(key) || null
    },
    setItem(key, value) {
      storage.set(key, String(value))
    },
    removeItem(key) {
      storage.delete(key)
    },
  },
}
global.document = documentMock

const driver = createSessionDriver({
  tokenKey: 'token',
  lastActiveKey: 'active_at',
  idleTimeoutMs: 60_000,
})

driver.setToken('abc')
assert.equal(driver.getToken(), 'abc')
assert.equal(driver.isLoggedIn(), true)
assert.equal(cookieWrites.at(-1).includes('Secure'), false)
driver.removeToken()
assert.equal(driver.getToken(), null)

global.window.location.protocol = 'https:'
driver.setToken('secure-token')
assert.equal(cookieWrites.at(-1).includes('Secure'), true)

delete global.window
delete global.document

console.log('shared runtime tests passed')
