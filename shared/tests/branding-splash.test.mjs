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
  persistSplashBranding(
    {
      site_name: '星辰云',
      browser_title: '星辰云 - 云服务器',
      site_logo: 'https://api.example.com/uploads/logo.png',
      site_favicon: 'https://api.example.com/uploads/favicon.png',
    },
    storage,
  )
  assert.deepEqual(readSplashBranding(storage), {
    site_name: '星辰云',
    browser_title: '星辰云 - 云服务器',
    site_logo: 'https://api.example.com/uploads/logo.png',
    site_favicon: 'https://api.example.com/uploads/favicon.png',
  })
}

// 2. 写入与读取都去掉首尾空白，并只保留白名单字段
{
  const storage = createStorage()
  persistSplashBranding({ site_name: '  星辰云  ', icp_record: 'x' }, storage)
  assert.deepEqual(readSplashBranding(storage), { site_name: '星辰云' })
}

// 3. 超长值被截断，避免撑破标题等单行布局；资源地址另有 URL 上限
{
  const storage = createStorage()
  const long = 'A'.repeat(SPLASH_BRANDING_MAX_LENGTH + 20)
  persistSplashBranding({ site_name: long, site_favicon: long }, storage)
  assert.equal(readSplashBranding(storage).site_name.length, SPLASH_BRANDING_MAX_LENGTH)
  // 60 字符虽超出站点名上限，但未达 URL 上限，不该被截断
  assert.equal(readSplashBranding(storage).site_favicon, long)

  // 即使缓存被外部篡改成超长值，读取侧也要兜住
  const tampered = createStorage({
    [SPLASH_BRANDING_STORAGE_KEY]: JSON.stringify({ site_name: long }),
  })
  assert.equal(readSplashBranding(tampered).site_name.length, SPLASH_BRANDING_MAX_LENGTH)

  // 带长路径与签名参数的 logo 不能被站点名的短上限截坏
  const longUrl = `https://api.example.com/uploads/${'a'.repeat(SPLASH_BRANDING_MAX_LENGTH)}/logo.png?sig=${'b'.repeat(80)}`
  persistSplashBranding({ site_logo: longUrl }, storage)
  assert.equal(readSplashBranding(storage).site_logo, longUrl)
}

// 4. 站点名被清空时要清掉缓存，避免页面一直停在旧品牌
{
  const storage = createStorage({
    [SPLASH_BRANDING_STORAGE_KEY]: JSON.stringify({ site_name: '旧品牌' }),
  })
  persistSplashBranding({ site_name: '   ' }, storage)
  assert.equal(storage.getItem(SPLASH_BRANDING_STORAGE_KEY), null)
  assert.deepEqual(readSplashBranding(storage), {})

  persistSplashBranding(null, storage)
  assert.deepEqual(readSplashBranding(storage), {})
}

// 5. 缓存内容不是合法 JSON / 不是对象 / 是数组时安静降级
{
  assert.deepEqual(readSplashBranding(createStorage({ [SPLASH_BRANDING_STORAGE_KEY]: '{oops' })), {})
  assert.deepEqual(readSplashBranding(createStorage({ [SPLASH_BRANDING_STORAGE_KEY]: '"str"' })), {})
  assert.deepEqual(readSplashBranding(createStorage({ [SPLASH_BRANDING_STORAGE_KEY]: '[1,2]' })), {})
  assert.deepEqual(readSplashBranding(createStorage()), {})
}

// 6. storage 抛错（隐私模式 / 配额写满）不能冒泡打断主流程
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

  assert.doesNotThrow(() => persistSplashBranding({ site_name: '星辰云' }, exploding))
  assert.doesNotThrow(() => persistSplashBranding({}, exploding))
  assert.deepEqual(readSplashBranding(exploding), {})
}

// 7. 没有可用 storage（SSR / window 缺失）时安静降级
{
  assert.doesNotThrow(() => persistSplashBranding({ site_name: '星辰云' }, null))
  assert.deepEqual(readSplashBranding(null), {})
}

// 7b. 浏览器禁止存储访问时，读取 window.localStorage 属性本身就会抛 SecurityError
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
    assert.doesNotThrow(() => persistSplashBranding({ site_name: '星辰云' }))
    assert.doesNotThrow(() => persistSplashBranding({}))
    assert.doesNotThrow(() => readSplashBranding())
    assert.deepEqual(readSplashBranding(), {})
  } finally {
    delete global.window
  }
}

// 8. index.html 的内联脚本与本模块的键名不得漂移。
//    该脚本在打包产物之外、无法 import 本模块，只能硬编码同一个字符串，
//    所以这里做一次跨包校验；否则键名一改，首帧注入会静默地永远读不到缓存。
{
  const indexHtml = readFileSync(
    fileURLToPath(new URL('../../frontend-user-v3-www/index.html', import.meta.url)),
    'utf8',
  )

  assert.ok(
    indexHtml.includes(`'${SPLASH_BRANDING_STORAGE_KEY}'`),
    `index.html 的首帧注入脚本必须使用与 SPLASH_BRANDING_STORAGE_KEY 相同的键名 ${SPLASH_BRANDING_STORAGE_KEY}`,
  )
  assert.ok(
    indexHtml.includes('JSON.parse(raw)'),
    '首帧注入脚本必须按 JSON 解析缓存（缓存值是 JSON 字符串，不是裸文本）',
  )
  assert.ok(
    !indexHtml.includes('innerHTML'),
    '首帧注入脚本不得用 innerHTML 写入站点名：站点名是管理员可改的外部输入',
  )
  assert.ok(
    indexHtml.includes(`slice(0, ${SPLASH_BRANDING_MAX_LENGTH})`),
    `首帧注入脚本必须按 SPLASH_BRANDING_MAX_LENGTH=${SPLASH_BRANDING_MAX_LENGTH} 截断`,
  )

  // 首帧真实存在的品牌元素只有 <title> 与 favicon <link>；
  // logo 由 Vue 挂载后从同一份缓存读取，不需要在静态 HTML 里出现。
  for (const field of ['site_name', 'browser_title', 'site_favicon']) {
    assert.ok(
      indexHtml.includes(field),
      `首帧注入脚本必须读取缓存字段 ${field}`,
    )
  }
  assert.ok(
    !indexHtml.includes('site_logo'),
    '首帧注入脚本不处理 logo：静态 HTML 里没有 logo 元素，它由 Vue 挂载后从缓存读取',
  )
}

// 10. 构建产物不得写死默认品牌：加载屏与 head meta 都不该出现默认站点名。
//     否则自建站首帧仍会闪出别人的品牌，SEO 也会被抓到错误名称。
//     注意 localStorage 键名 turaidc:site-config 里的包名不算品牌露出。
{
  const indexHtml = readFileSync(
    fileURLToPath(new URL('../../frontend-user-v3-www/index.html', import.meta.url)),
    'utf8',
  )
  const withoutStorageKey = indexHtml.split(SPLASH_BRANDING_STORAGE_KEY).join('')

  assert.ok(
    !/图拉/.test(withoutStorageKey),
    'index.html 不得出现写死的默认站点名（应由站点配置注入）',
  )
  assert.ok(
    !/TuraIDC|Tura/.test(withoutStorageKey),
    'index.html 不得出现写死的默认品牌名（应由站点配置注入）',
  )
  const sharedBrandingSource = readFileSync(
    fileURLToPath(new URL('../runtime/branding.ts', import.meta.url)),
    'utf8',
  )

  assert.ok(
    !/图拉/.test(sharedBrandingSource),
    'shared/runtime/branding.ts 不得写死默认站点名',
  )
}

console.log('shared splash branding tests passed')