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
}

console.log('shared splash branding tests passed')
