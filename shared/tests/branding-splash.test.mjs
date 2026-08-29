import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

import {
  SPLASH_BRANDING_MAX_LENGTH,
  SPLASH_BRANDING_STORAGE_KEY,
  persistSplashBranding,
  readSplashBranding,
} from '../runtime/branding.ts'

function createStorage(initial = {}) {
  const map = new Map(Object.entries(initial))

  return {
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => {
      map.set(key, String(value))
    },
    removeItem: (key) => {
      map.delete(key)
    },
    size: () => map.size,
  }
}

// 1. 基本往返
{
  const storage = createStorage()
  persistSplashBranding('星辰云', storage)
  assert.equal(storage.getItem(SPLASH_BRANDING_STORAGE_KEY), '星辰云')
  assert.equal(readSplashBranding(storage), '星辰云')
}

// 2. 写入与读取都去掉首尾空白
{
  const storage = createStorage()
  persistSplashBranding('  星辰云  ', storage)
  assert.equal(readSplashBranding(storage), '星辰云')

  const dirty = createStorage({ [SPLASH_BRANDING_STORAGE_KEY]: '  星辰云  ' })
  assert.equal(readSplashBranding(dirty), '星辰云')
}

// 3. 超长站点名被截断，避免撑破启动屏单行布局
{
  const storage = createStorage()
  const long = 'A'.repeat(SPLASH_BRANDING_MAX_LENGTH + 20)
  persistSplashBranding(long, storage)
  assert.equal(readSplashBranding(storage).length, SPLASH_BRANDING_MAX_LENGTH)

  // 即使缓存被外部篡改成超长值，读取侧也要兜住
  const tampered = createStorage({ [SPLASH_BRANDING_STORAGE_KEY]: long })
  assert.equal(readSplashBranding(tampered).length, SPLASH_BRANDING_MAX_LENGTH)
}

// 4. 站点名被清空时要清掉缓存，否则启动屏会一直停在旧品牌
{
  const storage = createStorage({ [SPLASH_BRANDING_STORAGE_KEY]: '旧品牌' })
  persistSplashBranding('   ', storage)
  assert.equal(storage.getItem(SPLASH_BRANDING_STORAGE_KEY), null)
  assert.equal(readSplashBranding(storage), '')
}

// 5. storage 抛错（隐私模式 / 配额写满）不能冒泡打断主流程
{
  const exploding = {
    getItem() {
      throw new Error('storage disabled')
    },
    setItem() {
      throw new Error('quota exceeded')
    },
    removeItem() {
      throw new Error('storage disabled')
    },
  }

  assert.doesNotThrow(() => persistSplashBranding('星辰云', exploding))
  assert.doesNotThrow(() => persistSplashBranding('', exploding))
  assert.equal(readSplashBranding(exploding), '')
}

// 6. 没有可用 storage（SSR / window 缺失）时安静降级
{
  assert.doesNotThrow(() => persistSplashBranding('星辰云', null))
  assert.equal(readSplashBranding(null), '')
}

// 6b. 浏览器禁止存储访问时，读取 window.localStorage 属性本身就会抛 SecurityError
//     （"阻止所有 Cookie"、无 allow-same-origin 的 iframe）。这个异常绝不能冒泡出去：
//     否则 hydrateSiteConfig 会把一次纯装饰性的缓存写入变成整个站点配置请求的失败。
{
  const windowMock = {}
  Object.defineProperty(windowMock, 'localStorage', {
    get() {
      const error = new Error('Access is denied for this document.')
      error.name = 'SecurityError'
      throw error
    },
  })

  global.window = windowMock
  try {
    assert.doesNotThrow(() => persistSplashBranding('星辰云'))
    assert.doesNotThrow(() => persistSplashBranding(''))
    assert.doesNotThrow(() => readSplashBranding())
    assert.equal(readSplashBranding(), '')
  } finally {
    delete global.window
  }
}

// 7. index.html 的内联脚本与本模块的键名不得漂移。
//    该脚本在打包产物之外、无法 import 本模块，只能硬编码同一个字符串，
//    所以这里做一次跨包校验；否则键名一改，启动屏会静默地永远读不到缓存。
{
  const indexHtml = readFileSync(
    fileURLToPath(new URL('../../frontend-user-v3-www/index.html', import.meta.url)),
    'utf8',
  )

  assert.ok(
    indexHtml.includes(`'${SPLASH_BRANDING_STORAGE_KEY}'`),
    `index.html 的启动屏脚本必须使用与 SPLASH_BRANDING_STORAGE_KEY 相同的键名 ${SPLASH_BRANDING_STORAGE_KEY}`,
  )
  assert.ok(
    indexHtml.includes('textContent'),
    '启动屏脚本必须用 textContent 写入站点名',
  )
  assert.ok(
    !indexHtml.includes('innerHTML'),
    '启动屏脚本不得用 innerHTML 写入站点名：站点名是管理员可改的外部输入',
  )
  assert.ok(
    indexHtml.includes(`slice(0, ${SPLASH_BRANDING_MAX_LENGTH})`),
    `启动屏脚本必须按 SPLASH_BRANDING_MAX_LENGTH=${SPLASH_BRANDING_MAX_LENGTH} 截断`,
  )
  assert.ok(
    indexHtml.includes('Intl.Segmenter'),
    '角标必须按字素簇取首字：Array.from 会切断 ZWJ 组合与组合记号',
  )
}

// 8. 角标取首字素的逻辑（与 index.html 内联脚本同构）在本运行时下的实际行为。
//    内联脚本在打包产物之外无法 import，这里对同一段逻辑做等价验证。
{
  const firstGrapheme = (name) => {
    let first = Array.from(name)[0]
    try {
      if (typeof Intl !== 'undefined' && Intl.Segmenter) {
        first = new Intl.Segmenter(undefined, { granularity: 'grapheme' })
          .segment(name)[Symbol.iterator]()
          .next().value.segment
      }
    } catch {
      /* 保留码点切分的结果 */
    }
    return first
  }

  assert.equal(firstGrapheme('星辰云'), '星')
  assert.equal(firstGrapheme('NimbusCloud'), 'N')
  // 代理对：单个 emoji
  assert.equal(firstGrapheme('🚀火箭云'), '🚀')
  // ZWJ 家族：Array.from 会只取到 👨
  assert.equal(firstGrapheme('👨‍👩‍👧‍👦云'), '👨‍👩‍👧‍👦')
  // 基字符 + 组合记号：这里刻意用分解形式（e + U+0301）。
  // 下面那条前提断言同时起到守卫作用——若本文件哪天被编辑器规范化成 NFC，
  // decomposed 会变成单码点的 é，前提断言立刻失败，而不会让本用例静默失去意义。
  const decomposed = 'étoile'
  assert.equal(Array.from(decomposed)[0], 'e', '前提：码点切分确实会丢掉组合记号')
  assert.equal(firstGrapheme(decomposed), 'é')
}

console.log('shared splash branding tests passed')
