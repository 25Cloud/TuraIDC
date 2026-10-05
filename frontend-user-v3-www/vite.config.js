import { readFile, readdir, stat, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { brotliCompressSync, constants as zlibConstants, gzipSync } from 'node:zlib'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const compressionExtensions = new Set([
  '.css',
  '.html',
  '.js',
  '.json',
  '.map',
  '.mjs',
  '.svg',
  '.txt',
  '.xml',
])
const compressionThreshold = 1024

// 静态资源与站点同源，不再使用外部 CDN，统一以根路径发布。
// 如未来需要部署到子路径，可在 .env 中设置 VITE_WWW_ASSET_BASE_URL=/子路径/。
function resolveAssetBase(rawValue = '') {
  const normalized = String(rawValue || '').trim()

  if (!normalized) {
    return '/'
  }

  if (normalized.startsWith('/')) {
    return normalized.endsWith('/') ? normalized : `${normalized}/`
  }

  return `/${normalized.replace(/^\/+/, '').replace(/\/+$/, '')}/`
}

function resolveManualChunk(id) {
  const normalized = id.split(path.sep).join('/')

  if (normalized.includes('/node_modules/')) {
    if (normalized.includes('/axios/')) {
      return 'vendor-axios'
    }

    if (
      normalized.includes('/vue/')
      || normalized.includes('/vue-router/')
      || normalized.includes('/pinia/')
      || normalized.includes('/@vue/')
    ) {
      return 'vendor-vue'
    }

    if (
      normalized.includes('/markdown-it/')
      || normalized.includes('/entities/')
      || normalized.includes('/linkify-it/')
      || normalized.includes('/mdurl/')
      || normalized.includes('/uc.micro/')
    ) {
      return 'vendor-content'
    }
  }

  return undefined
}

async function collectOutputFiles(rootDirectory) {
  const entries = await readdir(rootDirectory, { withFileTypes: true })
  const files = await Promise.all(entries.map(async (entry) => {
    const nextPath = path.join(rootDirectory, entry.name)

    if (entry.isDirectory()) {
      return collectOutputFiles(nextPath)
    }

    return [nextPath]
  }))

  return files.flat()
}

function createPrecompressedAssetsPlugin() {
  return {
    name: 'client-precompressed-assets',
    apply: 'build',
    async closeBundle() {
      const distDirectory = path.resolve(__dirname, 'dist')
      const distStats = await stat(distDirectory).catch(() => null)

      if (!distStats?.isDirectory()) {
        return
      }

      const files = await collectOutputFiles(distDirectory)

      await Promise.all(files.map(async (filePath) => {
        const extension = path.extname(filePath).toLowerCase()

        if (!compressionExtensions.has(extension)) {
          return
        }

        const buffer = await readFile(filePath)

        if (buffer.byteLength < compressionThreshold) {
          return
        }

        const gzipBuffer = gzipSync(buffer, { level: 9 })
        const brotliBuffer = brotliCompressSync(buffer, {
          params: {
            [zlibConstants.BROTLI_PARAM_QUALITY]: 11,
          },
        })

        if (gzipBuffer.byteLength < buffer.byteLength) {
          await writeFile(`${filePath}.gz`, gzipBuffer)
        }

        if (brotliBuffer.byteLength < buffer.byteLength) {
          await writeFile(`${filePath}.br`, brotliBuffer)
        }
      }))
    },
  }
}

function resolveAssetFileName(assetInfo) {
  const name = String(assetInfo.name || '')
  const extension = path.extname(name).toLowerCase()

  if (extension === '.css') {
    return 'assets/css/[name]-[hash][extname]'
  }

  if (['.png', '.jpg', '.jpeg', '.webp', '.gif', '.svg', '.avif'].includes(extension)) {
    return 'assets/img/[name]-[hash][extname]'
  }

  if (['.woff', '.woff2', '.ttf', '.otf', '.eot'].includes(extension)) {
    return 'assets/fonts/[name]-[hash][extname]'
  }

  return 'assets/misc/[name]-[hash][extname]'
}

// 构建期校验控制台地址。多域名部署（console.example.com 与 www.example.com 不同源）
// 不受此限制；只有单域名子路径部署把两者放成同源时，才要求控制台地址必须带子路径，
// 否则 /client/login 会落进官网 SPA。多域名用户不会因为这个守卫被卡住。
function assertConsoleUrlResolves(env) {
  const publicSiteUrl = String(env.VITE_PUBLIC_SITE_URL || '').trim().replace(/\/+$/, '')
  const consoleSiteUrl = String(env.VITE_CONSOLE_SITE_URL || '').trim().replace(/\/+$/, '')
  const consoleBasePath = String(env.VITE_CONSOLE_BASE_PATH || '/console').trim()

  if (!consoleSiteUrl) {
    throw new Error(
      '缺少 VITE_CONSOLE_SITE_URL。官网跳登录/注册全靠这个地址，留空会退化成同源 /client/*，落到官网页面上。' +
        '多域名部署填 https://console.你的域名；单域名子路径部署填到子路径，例如 https://你的域名/console。'
    )
  }

  let publicUrl
  try {
    publicUrl = publicSiteUrl ? new URL(publicSiteUrl) : null
  } catch {
    throw new Error(`VITE_PUBLIC_SITE_URL 不是合法地址：${publicSiteUrl}`)
  }

  let consoleUrl
  try {
    consoleUrl = new URL(consoleSiteUrl)
  } catch {
    throw new Error(`VITE_CONSOLE_SITE_URL 不是合法地址：${consoleSiteUrl}`)
  }

  // 禁止带 query 或 hash：构建产物的 SPA 路由由框架处理，外部链接不应带参数
  if (publicUrl.search || publicUrl.hash) {
    throw new Error(`VITE_PUBLIC_SITE_URL 不应包含 query 或 hash：${publicSiteUrl}`)
  }
  if (consoleUrl.search || consoleUrl.hash) {
    throw new Error(`VITE_CONSOLE_SITE_URL 不应包含 query 或 hash：${consoleSiteUrl}`)
  }

  if (!publicUrl || publicUrl.origin !== consoleUrl.origin) {
    return
  }

  // 官网与控制台同源时，控制台只能靠子路径区分；
  // 少了子路径，/client/login 会落进官网 SPA，登录和注册都点不进去。
  if (consoleUrl.pathname === '' || consoleUrl.pathname === '/') {
    throw new Error(
      `VITE_CONSOLE_SITE_URL 与官网同源（${consoleUrl.origin}）但没有子路径。` +
        `请写成 ${consoleUrl.origin}${consoleBasePath || '/console'}。`
    )
  }
}

export default defineConfig(({ mode, command }) => {
  const env = loadEnv(mode, process.cwd(), '')
  // 资源与页面同源，去掉外部 CDN 相关变量（VITE_CDN_ASSET_HOST / VITE_ASSET_BASE_URL）。
  const assetBase = resolveAssetBase(env.VITE_WWW_ASSET_BASE_URL || '')

  if (command === 'build') {
    assertConsoleUrlResolves(env)
  }

  return {
    base: assetBase,
    plugins: [
      vue(),
      AutoImport({
        imports: ['vue', 'vue-router', 'pinia'],
      }),
      Components({
        resolvers: [ElementPlusResolver({ importStyle: 'sass', directives: true })],
      }),
      createPrecompressedAssetsPlugin(),
      {
        name: 'client-fetch-priority',
        apply: 'build',
        transformIndexHtml(html) {
          // 为主入口 script 标签添加 fetchpriority="high"，优化首屏 LCP
          return html.replace(
            /<script type="module" crossorigin src="\/assets\/js\/index-([^"]+)\.js"><\/script>/g,
            '<script type="module" crossorigin fetchpriority="high" src="/assets/js/index-$1.js"></script>'
          )
        },
      },
    ],
    resolve: {
      dedupe: ['vue', 'element-plus'],
      alias: {
        '@': path.resolve(__dirname, 'src'),
        '@shared': path.resolve(__dirname, '../shared'),
        '@turaidc/shared': path.resolve(__dirname, '../shared'),
        // 指向本包自己的 node_modules（pnpm 严格隔离下仓库根无 hoist）
        'element-plus': path.resolve(__dirname, 'node_modules/element-plus'),
      },
    },
    server: {
      headers: {
        'X-Content-Type-Options': 'nosniff',
      },
      fs: {
        allow: [path.resolve(__dirname, '..')],
      },
      host: '127.0.0.1',
      port: 5175,
    },
    optimizeDeps: {
      include: [
        'vue',
        'vue-router',
        'pinia',
        'axios',
        'dayjs',
        'dayjs/plugin/advancedFormat.js',
        'dayjs/plugin/customParseFormat.js',
        'dayjs/plugin/dayOfYear.js',
        'dayjs/plugin/isSameOrAfter.js',
        'dayjs/plugin/isSameOrBefore.js',
        'dayjs/plugin/localeData.js',
        'dayjs/plugin/weekOfYear.js',
        'dayjs/plugin/weekYear.js',
        '@element-plus/icons-vue',
        'markdown-it',
      ],
    },
    build: {
      target: 'es2018',
      sourcemap: false,
      minify: 'esbuild',
      cssMinify: 'esbuild',
      cssCodeSplit: true,
      reportCompressedSize: false,
      chunkSizeWarningLimit: 1200,
      rollupOptions: {
        output: {
          entryFileNames: 'assets/js/[name]-[hash].js',
          chunkFileNames: 'assets/js/[name]-[hash].js',
          assetFileNames: resolveAssetFileName,
          manualChunks: resolveManualChunk,
        },
      },
    },
    css: {
      preprocessorOptions: {
        scss: {
          additionalData: `@use "@/assets/styles/element/index.scss"; @use "@/assets/styles/variables.scss" as *;`,
        },
      },
    },
  }
})
