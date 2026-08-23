import 'nprogress/nprogress.css'; // progress bar style

import NProgress from 'nprogress'; // progress bar
import { MessagePlugin } from 'tdesign-vue-next';
import type { RouteRecordRaw } from 'vue-router';

import { hasPermissionInList } from '@/constants/permissions';
import router from '@/router';
import { getPermissionStore, useUserStore } from '@/store';
import { resolveAdminHomePath } from '@/utils/route/adminHome';
import { PAGE_NOT_FOUND_ROUTE } from '@/utils/route/constant';
import { toUserMessage } from '@/utils/userMessage';

NProgress.configure({ showSpinner: false });

router.beforeEach(async (to, from, next) => {
  NProgress.start();

  const permissionStore = getPermissionStore();
  const { whiteListRouters } = permissionStore;

  const userStore = useUserStore();
  // 始终以 Cookie/session 中的 token 为准，避免 Pinia state 与 Cookie 不同步
  userStore.syncTokenFromSession();
  const token = userStore.token;

  if (token) {
    if (to.path === '/admin/login') {
      // 已登录访问登录页：放行（afterEach 会在落地后清理旧会话）
      next();
      return;
    }
    try {
      // 仅在动态路由未构建时拉取用户信息，避免每次导航都请求接口
      // （刷新或首次登录后 routesBuilt=false；后续跳转 routesBuilt=true 直接复用缓存）
      if (!permissionStore.routesBuilt || !userStore.userInfo?.name) {
        await userStore.getUserInfo();

        const routeList = await permissionStore.buildAsyncRoutes(userStore.userInfo?.permissions || []);
        routeList.forEach((item) => {
          router.addRoute(item as RouteRecordRaw);
        });

        if (to.name === PAGE_NOT_FOUND_ROUTE.name) {
          // 动态添加路由后，此处应当重定向到fullPath，否则会加载404页面内容
          next({ path: to.fullPath, replace: true, query: to.query });
        } else {
          const redirect = decodeURIComponent((from.query.redirect || to.path) as string);
          next(to.path === redirect ? { ...to, replace: true } : { path: redirect, query: to.query });
        }
        return;
      }

      // 权限校验：检查路由 meta.permission
      const requiredPermission = to.meta?.permission as string | undefined;
      if (requiredPermission && requiredPermission !== '') {
        const userPermissions = userStore.userInfo?.permissions || [];
        const hasPermission = hasPermissionInList(userPermissions, requiredPermission);
        if (!hasPermission) {
          MessagePlugin.warning('您没有访问该页面的权限');
          // 回退到当前角色可访问的落地页而非硬编码主页：仅持非视图权限的角色
          // 若被反复重定向回 /admin/dashboard 会形成自循环，导致登录后页面卡死。
          next({ path: resolveAdminHomePath(userPermissions), replace: true });
          NProgress.done();
          return;
        }
      }

      if (router.hasRoute(to.name)) {
        next();
      } else {
        next(`/`);
      }
    } catch (error) {
      // getUserInfo 失败时仅跳转登录页，不在此处清除 token，
      // 避免 401/网络抖动等瞬时错误被放大为强制登出。
      // 真正的会话失效由登录页 onMounted 或用户主动登出处理。
      MessagePlugin.error(toUserMessage(error instanceof Error ? error.message : '', '登录状态已失效，请重新登录'));
      next({
        path: '/admin/login',
        query: { redirect: encodeURIComponent(to.fullPath) },
      });
      NProgress.done();
    }
  } else {
    /* white list router */
    if (whiteListRouters.includes(to.path)) {
      next();
    } else {
      next({
        path: '/admin/login',
        query: { redirect: encodeURIComponent(to.fullPath) },
      });
    }
    NProgress.done();
  }
});

router.afterEach((to) => {
  // 落地到登录页时，清理上一轮可能残留的动态路由与本地用户信息，
  // 但仅在确实没有有效 token 时才清除，避免误清正在使用的会话。
  if (to.path === '/admin/login') {
    const userStore = useUserStore();
    userStore.syncTokenFromSession();
    if (!userStore.token) {
      userStore.resetLocalSession();
    }
    const permissionStore = getPermissionStore();
    permissionStore.restoreRoutes();
  }
  NProgress.done();
});
