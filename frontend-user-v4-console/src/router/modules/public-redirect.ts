import { defineComponent, h } from 'vue';
import type { RouteRecordRaw } from 'vue-router';

function resolvePublicSiteOrigin() {
  const configuredOrigin = String(import.meta.env.VITE_PUBLIC_SITE_URL || '')
    .trim()
    .replace(/\/+$/, '');
  if (configuredOrigin) {
    return configuredOrigin;
  }

  if (typeof window === 'undefined') {
    return '';
  }

  const { protocol, hostname, port } = window.location;
  if (hostname.startsWith('console.')) {
    return `${protocol}//www.${hostname.slice('console.'.length)}${port ? `:${port}` : ''}`;
  }

  if (hostname === '127.0.0.1' || hostname === 'localhost') {
    const publicSitePort = String(import.meta.env.VITE_WWW_DEV_PORT || '5175').trim();
    return `${protocol}//${hostname}${publicSitePort ? `:${publicSitePort}` : ''}`;
  }

  return '';
}

function openPublicSitePath(path: string) {
  const base = resolvePublicSiteOrigin();
  if (!base) {
    return;
  }
  window.location.assign(`${base}${path}`);
}

const routeTitle = (zhCN: string, enUS = zhCN) => ({ zh_CN: zhCN, en_US: enUS });

const EmptyComponent = defineComponent({
  name: 'EmptyRedirect',
  render: () => h('div'),
});

function redirectToPublicSite(path: string, titleText: string): RouteRecordRaw {
  return {
    path,
    beforeEnter: (to) => {
      openPublicSitePath(to.fullPath);
      return false;
    },
    component: EmptyComponent,
    meta: { title: routeTitle(titleText), robots: 'noindex,nofollow' },
  };
}

export default [
  redirectToPublicSite('/products', '产品与服务'),
  redirectToPublicSite('/products/:pathMatch(.*)*', '产品与服务'),
  redirectToPublicSite('/about', '关于我们'),
  redirectToPublicSite('/terms', '服务条款'),
  redirectToPublicSite('/privacy', '隐私政策'),
  redirectToPublicSite('/notices', '官方公告'),
  redirectToPublicSite('/notices/:pathMatch(.*)*', '官方公告'),
  redirectToPublicSite('/help', '帮助中心'),
  redirectToPublicSite('/help/:pathMatch(.*)*', '帮助中心'),
] satisfies RouteRecordRaw[];
