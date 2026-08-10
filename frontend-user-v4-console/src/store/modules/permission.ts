import { defineStore } from 'pinia';
import type { RouteRecordRaw } from 'vue-router';

import router, { fixedRouterList, homepageRouterList } from '@/router';
import { store } from '@/store';
import type { MenuRoute } from '@/types/interface';

function toMenuRoutes(routes: RouteRecordRaw[] = []): MenuRoute[] {
  return routes.map((route) => ({
    path: route.path,
    title: route.meta?.title as MenuRoute['title'],
    name: typeof route.name === 'string' ? route.name : String(route.name || ''),
    icon: route.meta?.icon as MenuRoute['icon'],
    redirect: typeof route.redirect === 'string' ? route.redirect : undefined,
    children: toMenuRoutes(route.children || []),
    meta: route.meta || {},
  }));
}

export const usePermissionStore = defineStore('permission', {
  state: () => ({
    whiteListRouters: ['/client/login', '/client/register', '/client/forgot-password', '/client/login-as'],
    routers: [],
    removeRoutes: [],
    asyncRoutes: [],
    routesBuilt: false,
  }),
  actions: {
    async initRoutes() {
      const clientRoute = ([...homepageRouterList, ...fixedRouterList, ...this.asyncRoutes] as RouteRecordRaw[]).find(
        (route: RouteRecordRaw) => route.path === '/client',
      );
      this.routers = toMenuRoutes(clientRoute?.children || []);
    },
    async buildAsyncRoutes() {
      try {
        this.asyncRoutes = [];
        this.routesBuilt = true;
        await this.initRoutes();
        return this.asyncRoutes;
      } catch (error) {
        throw new Error("Can't build routes", { cause: error });
      }
    },
    async restoreRoutes() {
      this.asyncRoutes.forEach((item: RouteRecordRaw) => {
        if (item.name) {
          router.removeRoute(item.name);
        }
      });
      this.asyncRoutes = [];
      this.routesBuilt = false;
    },
  },
});

export function getPermissionStore() {
  return usePermissionStore(store);
}
