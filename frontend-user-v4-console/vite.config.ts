import { readdir, readFile, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { brotliCompressSync, constants as zlibConstants, gzipSync } from 'node:zlib';

import vue from '@vitejs/plugin-vue';
import vueJsx from '@vitejs/plugin-vue-jsx';
import { TDesignResolver } from 'unplugin-vue-components/resolvers';
import Components from 'unplugin-vue-components/vite';
import type { ConfigEnv, UserConfig } from 'vite';
import { loadEnv } from 'vite';
import svgLoader from 'vite-svg-loader';

const CWD = process.cwd();
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const compressionExtensions = new Set(['.css', '.html', '.js', '.json', '.map', '.mjs', '.svg', '.txt', '.xml']);
const compressionThreshold = 1024;

function resolveAssetBase(rawValue = '') {
  const normalized = String(rawValue || '').trim();

  if (!normalized) {
    return '/';
  }

  if (/^https?:\/\//i.test(normalized)) {
    return normalized.endsWith('/') ? normalized : `${normalized}/`;
  }

  if (normalized.startsWith('/')) {
    return normalized.endsWith('/') ? normalized : `${normalized}/`;
  }

  return `/${normalized.replace(/^\/+/, '').replace(/\/+$/, '')}/`;
}

function resolveHintOrigin(assetBase: string) {
  if (!/^https?:\/\//i.test(assetBase)) {
    return '';
  }

  try {
    return new URL(assetBase).origin;
  } catch {
    return '';
  }
}

function resolveManualChunk(id: string) {
  const normalized = id.split(path.sep).join('/');

  if (!normalized.includes('/node_modules/')) {
    return undefined;
  }

  if (normalized.includes('/axios/')) {
    return 'vendor-axios';
  }

  if (
    normalized.includes('/vue/') ||
    normalized.includes('/vue-router/') ||
    normalized.includes('/pinia/') ||
    normalized.includes('/@vue/')
  ) {
    return 'vendor-vue';
  }

  if (normalized.includes('/tdesign-vue-next/') || normalized.includes('/tdesign-icons-vue-next/')) {
    return 'vendor-tdesign';
  }

  if (normalized.includes('/qrcode.vue/')) {
    return 'vendor-qrcode';
  }

  if (
    normalized.includes('/markdown-it/') ||
    normalized.includes('/entities/') ||
    normalized.includes('/linkify-it/') ||
    normalized.includes('/mdurl/') ||
    normalized.includes('/uc.micro/')
  ) {
    return 'vendor-content';
  }

  return undefined;
}

async function collectOutputFiles(rootDirectory: string): Promise<string[]> {
  const entries = await readdir(rootDirectory, { withFileTypes: true });
  const files = await Promise.all(
    entries.map(async (entry) => {
      const nextPath = path.join(rootDirectory, entry.name);

      if (entry.isDirectory()) {
        return collectOutputFiles(nextPath);
      }

      return [nextPath];
    }),
  );

  return files.flat();
}

function createPrecompressedAssetsPlugin() {
  return {
    name: 'client-precompressed-assets',
    apply: 'build' as const,
    async closeBundle() {
      const distDirectory = path.resolve(__dirname, 'dist');
      const distStats = await stat(distDirectory).catch(() => null);

      if (!distStats?.isDirectory()) {
        return;
      }

      const files = await collectOutputFiles(distDirectory);

      await Promise.all(
        files.map(async (filePath) => {
          const extension = path.extname(filePath).toLowerCase();

          if (!compressionExtensions.has(extension)) {
            return;
          }

          const buffer = await readFile(filePath);

          if (buffer.byteLength < compressionThreshold) {
            return;
          }

          const gzipBuffer = gzipSync(buffer, { level: 9 });
          const brotliBuffer = brotliCompressSync(buffer, {
            params: {
              [zlibConstants.BROTLI_PARAM_QUALITY]: 11,
            },
          });

          if (gzipBuffer.byteLength < buffer.byteLength) {
            await writeFile(`${filePath}.gz`, gzipBuffer);
          }

          if (brotliBuffer.byteLength < buffer.byteLength) {
            await writeFile(`${filePath}.br`, brotliBuffer);
          }
        }),
      );
    },
  };
}

function createIndexNetworkHintsPlugin(assetBase: string) {
  return {
    name: 'client-index-network-hints',
    apply: 'build' as const,
    transformIndexHtml(html: string) {
      const hintOrigin = resolveHintOrigin(assetBase);

      if (!hintOrigin) {
        return html;
      }

      const hintHost = new URL(hintOrigin).host;
      const dnsPrefetchTag = `    <link rel="dns-prefetch" href="//${hintHost}" />`;
      const preconnectTag = `    <link rel="preconnect" href="${hintOrigin}" crossorigin="anonymous" />`;

      return html.replace('</head>', `${dnsPrefetchTag}\n${preconnectTag}\n  </head>`);
    },
  };
}

function resolveAssetFileName(assetInfo: { name?: string }) {
  const name = String(assetInfo.name || '');
  const extension = path.extname(name).toLowerCase();

  if (extension === '.css') {
    return 'assets/css/[name]-[hash][extname]';
  }

  if (['.png', '.jpg', '.jpeg', '.webp', '.gif', '.svg', '.avif'].includes(extension)) {
    return 'assets/img/[name]-[hash][extname]';
  }

  if (['.woff', '.woff2', '.ttf', '.otf', '.eot'].includes(extension)) {
    return 'assets/fonts/[name]-[hash][extname]';
  }

  return 'assets/misc/[name]-[hash][extname]';
}

// https://vitejs.dev/config/
export default ({ mode }: ConfigEnv): UserConfig => {
  const env = loadEnv(mode, CWD, '');
  const assetBase = resolveAssetBase(
    env.VITE_CONSOLE_ASSET_BASE_URL || env.VITE_CDN_ASSET_HOST || env.VITE_ASSET_BASE_URL || env.VITE_BASE_URL || '',
  );
  return {
    base: assetBase,
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
        '@shared': path.resolve(__dirname, '../shared'),
        '@turaidc/shared': path.resolve(__dirname, '../shared'),
      },
      dedupe: ['vue'],
    },

    css: {
      preprocessorOptions: {
        less: {
          modifyVars: {
            hack: `true; @import (reference) "${path.resolve('src/style/variables.less')}";`,
          },
          math: 'strict',
          javascriptEnabled: true,
        },
      },
    },

    plugins: [
      vue(),
      vueJsx(),
      Components({
        // 仅按需解析 TDesign 组件（含其样式），替代 main.ts 的全量 app.use(TDesign)。
        // 图标仍由各页面显式 import（tdesign-icons-vue-next），故关闭 resolveIcons。
        // esm: true → 从 tdesign-vue-next/esm 引入，支持 rollup 静态 tree-shaking 且自动注入样式。
        dts: false,
        resolvers: [
          TDesignResolver({
            library: 'vue-next',
            resolveIcons: false,
            esm: true,
          }),
        ],
      }),
      svgLoader(),
      createIndexNetworkHintsPlugin(assetBase),
      createPrecompressedAssetsPlugin(),
    ],

    server: {
      headers: {
        'X-Content-Type-Options': 'nosniff',
      },
      fs: {
        allow: [path.resolve(__dirname, '..')],
      },
      port: 5173,
      host: '127.0.0.1',
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
    optimizeDeps: {
      noDiscovery: true,
      include: [
        'vue',
        'vue-router',
        'pinia',
        'axios',
        'tdesign-vue-next',
        'tdesign-vue-next/esm',
        'tdesign-icons-vue-next',
        'vue-i18n',
        'dayjs',
        'nprogress',
        'qs',
        // tvision-color 依赖旧版 @babel/runtime-corejs3@7.18.9，其内部混用 ESM/CJS
        // （arrayWithoutHoles.js 以 default 方式 import 仅有 CommonJS 导出的 is-array.js），
        // 浏览器原生 ESM 加载会报 "does not provide an export named 'default'"。
        // 显式纳入预构建，交由 esbuild 处理 CJS→ESM 互操作。
        'tvision-color',
      ],
    },
  };
};
