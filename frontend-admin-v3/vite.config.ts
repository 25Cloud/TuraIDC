import path from 'node:path';
import { fileURLToPath } from 'node:url';

import vue from '@vitejs/plugin-vue';
import vueJsx from '@vitejs/plugin-vue-jsx';
import type { ConfigEnv, PluginOption, UserConfig } from 'vite';
import { loadEnv } from 'vite';
import svgLoader from 'vite-svg-loader';

import { createApiConnectSrcPlugin } from '../shared/vite/apiConnectSrcPlugin.mjs';
import { createPrecompressedAssetsPlugin, resolveAssetFileName } from '../shared/vite/buildAssets.mjs';

const CWD = process.cwd();
const __dirname = path.dirname(fileURLToPath(import.meta.url));

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

  if (normalized.includes('/tdesign-icons-vue-next/')) {
    return 'vendor-tdesign-icons';
  }

  if (normalized.includes('/tdesign-vue-next/')) {
    return 'vendor-tdesign';
  }

  if (
    normalized.includes('/echarts/charts/') ||
    normalized.includes('/echarts/components/') ||
    normalized.includes('/echarts/renderers/')
  ) {
    return 'vendor-echarts-modules';
  }

  if (normalized.includes('/echarts/') || normalized.includes('/zrender/')) {
    return 'vendor-echarts-core';
  }

  if (normalized.includes('/vue-i18n/')) {
    return 'vendor-i18n';
  }

  if (normalized.includes('/lodash-es/')) {
    return 'vendor-lodash';
  }

  return undefined;
}

function createIndexNetworkHintsPlugin(assetBase: string) {
  return {
    name: 'admin-index-network-hints',
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

// https://vitejs.dev/config/
export default ({ mode }: ConfigEnv): UserConfig => {
  const env = loadEnv(mode, CWD, '');
  const assetBase = resolveAssetBase(
    env.VITE_ADMIN_ASSET_BASE_URL || env.VITE_CDN_ASSET_HOST || env.VITE_ASSET_BASE_URL || env.VITE_BASE_URL || '',
  );
  return {
    base: assetBase,
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
        '@shared': path.resolve(__dirname, '../shared'),
        '@turaidc/shared': path.resolve(__dirname, '../shared'),
        // 演示站示例数据（demo/fixtures）：只在 VITE_DEMO_MODE 下被动态 import
        '@demo-fixtures': path.resolve(__dirname, '../demo/fixtures'),
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
      // vite-svg-loader 未声明 vite peer，其类型 import 在 pnpm .pnpm 层级可能解析到
      // docs-web(vitepress) 引入的 vite@5，而运行时实际解析为本端 vite@6（API 兼容），
      // 仅做类型对齐，避免双 vite 版本造成的类型伪冲突。
      svgLoader() as unknown as PluginOption,
      createIndexNetworkHintsPlugin(assetBase),
      // CSP 是静态书写（index.html），开发态的 http API 源站与登录验证码代理脚本来不及写死：
      // 前者补进 connect/img/media/frame-src，后者（跨源脚本、无 https: 兜底）补进 script-src
      createApiConnectSrcPlugin(env, { scriptDirective: 'script-src' }),
      createPrecompressedAssetsPlugin(),
    ],

    server: {
      headers: {
        'X-Content-Type-Options': 'nosniff',
      },
      fs: {
        allow: [path.resolve(__dirname, '..')],
      },
      port: 5174,
      host: '127.0.0.1',
    },

    build: {
      target: 'es2018',
      sourcemap: false,
      minify: 'esbuild',
      cssMinify: 'esbuild',
      cssCodeSplit: true,
      reportCompressedSize: false,
      chunkSizeWarningLimit: 1500,
      assetsInlineLimit: 4096,
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
        'tdesign-icons-vue-next',
        'echarts',
        'vue-i18n',
        'dayjs',
        'lodash-es',
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
