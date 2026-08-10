import 'nprogress/nprogress.css';

import NProgress from 'nprogress';
import { MessagePlugin } from 'tdesign-vue-next';

import { getClientToken } from '@/app/runtime/session';
import { useSiteBrandingStore } from '@/app/stores/siteBranding';
import router from '@/router';
import { getPermissionStore, useUserStore } from '@/store';

const DYNAMIC_IMPORT_RELOAD_KEY = 'client-router-dynamic-import-reload';
const dynamicImportErrorPattern = /Failed to fetch dynamically imported module|Importing a module script failed/i;

let fetchingUser = false;

NProgress.configure({ showSpinner: false });

function resolveRedirectPath(raw: unknown, fallback = '/client/dashboard') {
  const redirect = typeof raw === 'string' ? redirectURIComponentSafe(raw) : '';
  return redirect.startsWith('/') ? redirect : fallback;
}

function redirectURIComponentSafe(value: string) {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

function resolvePageTitle(raw: unknown) {
  if (typeof raw === 'string') {
    return raw;
  }
  if (raw && typeof raw === 'object' && 'zh_CN' in raw) {
    return String((raw as { zh_CN?: string }).zh_CN || '');
  }
  return '';
}

router.beforeEach(async (to, _from, next) => {
  NProgress.start();

  const siteBranding = useSiteBrandingStore();
  siteBranding.applyPageTitle(resolvePageTitle(to.meta.title));
  void siteBranding.fetchSiteConfig();

  const permissionStore = getPermissionStore();
  await permissionStore.initRoutes();

  const token = getClientToken();
  const allowGuestWithToken = to.name === 'ClientLoginAs';

  if (to.meta.guest) {
    if (token && !allowGuestWithToken) {
      next(resolveRedirectPath(to.query.redirect));
      return;
    }
    next();
    return;
  }

  if (to.meta.requireAuth) {
    if (!token) {
      useUserStore().clearLocalSession();
      next({
        path: '/client/login',
        query: { redirect: to.fullPath },
      });
      return;
    }

    const userStore = useUserStore();
    userStore.syncTokenFromSession();

    if ((!userStore.profileHydrated || !userStore.userInfo.name) && !fetchingUser) {
      fetchingUser = true;
      try {
        await userStore.getUserInfo();
      } catch {
        fetchingUser = false;
        userStore.clearLocalSession();
        next({
          path: '/client/login',
          query: { redirect: to.fullPath },
        });
        return;
      }
      fetchingUser = false;
    }
  }

  next();
});

router.afterEach((to) => {
  if (typeof window !== 'undefined' && window.sessionStorage.getItem(DYNAMIC_IMPORT_RELOAD_KEY) === to.fullPath) {
    window.sessionStorage.removeItem(DYNAMIC_IMPORT_RELOAD_KEY);
  }
  NProgress.done();
});

router.onError((error, to) => {
  if (typeof window === 'undefined') {
    return;
  }

  const message = String(error?.message || '');
  if (!dynamicImportErrorPattern.test(message)) {
    MessagePlugin.error(message || '页面加载失败');
    return;
  }

  const targetPath =
    typeof to?.fullPath === 'string'
      ? to.fullPath
      : `${window.location.pathname}${window.location.search}${window.location.hash}`;

  if (window.sessionStorage.getItem(DYNAMIC_IMPORT_RELOAD_KEY) === targetPath) {
    window.sessionStorage.removeItem(DYNAMIC_IMPORT_RELOAD_KEY);
    return;
  }

  window.sessionStorage.setItem(DYNAMIC_IMPORT_RELOAD_KEY, targetPath);
  window.location.assign(targetPath);
});
