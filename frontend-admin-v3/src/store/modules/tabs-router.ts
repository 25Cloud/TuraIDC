import { defineStore } from 'pinia';

import { store } from '@/store';
import type { TRouterInfo, TTabRouterType } from '@/types/interface';
import { ADMIN_HOME_PATH } from '@/utils/route/adminHome';

// 路径必须与 router/modules/admin 的实际注册一致（父 '/admin' + 子 'dashboard'）。
// 曾写成 '/dashboard/base'（组件文件路径而非路由路径），全仓无路由注册该地址，
// 点首页标签与左上角 logo 都会落到 404 通配路由。
const homeRoute: Array<TRouterInfo> = [
  {
    path: ADMIN_HOME_PATH,
    routeIdx: 0,
    title: { zh_CN: '仪表盘', en_US: 'Dashboard' },
    name: 'DashboardBase',
    isHome: true,
  },
];

/** 允许写入 localStorage 的 meta 字段：其余（icon 等）不可序列化或与渲染无关。 */
const PERSISTABLE_META_KEYS = ['keepAlive', 'keepAliveName', 'frameSrc', 'permission'] as const;

function pickPersistableMeta(meta: TRouterInfo['meta']): Record<string, unknown> | undefined {
  if (!meta || typeof meta !== 'object') {
    return undefined;
  }
  const picked: Record<string, unknown> = {};
  PERSISTABLE_META_KEYS.forEach((key) => {
    if (meta[key] !== undefined) {
      picked[key] = meta[key];
    }
  });
  return Object.keys(picked).length > 0 ? picked : undefined;
}

const state = {
  tabRouterList: homeRoute,
  isRefreshing: false,
};

// 不需要做多标签tabs页缓存的列表 值为每个页面对应的name 如 DashboardDetail
// const ignoreCacheRoutes = ['DashboardDetail'];
const ignoreCacheRoutes = ['login'];
/** 标签页最大数量，超出后移除最早的非常驻标签 */
const MAX_TAB_COUNT = 20;

export const useTabsRouterStore = defineStore('tabsRouter', {
  state: () => state,
  getters: {
    tabRouters: (state: TTabRouterType) => state.tabRouterList,
    refreshing: (state: TTabRouterType) => state.isRefreshing,
  },
  actions: {
    // 处理刷新
    toggleTabRouterAlive(routeIdx: number) {
      this.isRefreshing = !this.isRefreshing;
      this.tabRouters[routeIdx].isAlive = !this.tabRouters[routeIdx].isAlive;
    },
    // 处理新增
    appendTabRouterList(newRoute: TRouterInfo) {
      // 不要将判断条件newRoute.meta.keepAlive !== false修改为newRoute.meta.keepAlive，starter默认开启保活，所以meta.keepAlive未定义时也需要进行保活，只有显式说明false才禁用保活。
      const needAlive = !ignoreCacheRoutes.includes(newRoute.name as string) && newRoute.meta?.keepAlive !== false;
      const existing = this.tabRouters.find((route: TRouterInfo) => route.path === newRoute.path);
      if (existing) {
        // 从 localStorage 还原的标签只带持久化白名单里的 meta，且路径已存在时不会再走 append，
        // 补不回来就会让 keep-alive 匹配失效。这里用当前路由的实时 meta/query 回填，
        // 但保留 isAlive —— 它由「刷新标签」主动切换，不能被覆盖。
        existing.meta = { ...(existing.meta ?? {}), ...(newRoute.meta ?? {}) };
        existing.query = newRoute.query;
        if (newRoute.title) {
          existing.title = newRoute.title;
        }
        return;
      }
      // 超过最大标签数时，移除最早的非首页标签
      if (this.tabRouterList.length >= MAX_TAB_COUNT) {
        const removeIdx = this.tabRouterList.findIndex((r: TRouterInfo) => !r.isHome);
        if (removeIdx !== -1) {
          this.tabRouterList.splice(removeIdx, 1);
        }
      }
      this.tabRouterList = this.tabRouterList.concat({ ...newRoute, isAlive: needAlive });
    },
    // 处理关闭当前
    subtractCurrentTabRouter(newRoute: TRouterInfo) {
      const { routeIdx } = newRoute;
      this.tabRouterList = this.tabRouterList.slice(0, routeIdx).concat(this.tabRouterList.slice(routeIdx + 1));
    },
    // 处理关闭右侧
    subtractTabRouterBehind(newRoute: TRouterInfo) {
      const { routeIdx } = newRoute;
      const homeIdx: number = this.tabRouters.findIndex((route: TRouterInfo) => route.isHome);
      let tabRouterList: Array<TRouterInfo> = this.tabRouterList.slice(0, routeIdx + 1);
      if (routeIdx < homeIdx) {
        tabRouterList = tabRouterList.concat(homeRoute);
      }
      this.tabRouterList = tabRouterList;
    },
    // 处理关闭左侧
    subtractTabRouterAhead(newRoute: TRouterInfo) {
      const { routeIdx } = newRoute;
      const homeIdx: number = this.tabRouters.findIndex((route: TRouterInfo) => route.isHome);
      let tabRouterList: Array<TRouterInfo> = this.tabRouterList.slice(routeIdx);
      if (routeIdx > homeIdx) {
        tabRouterList = homeRoute.concat(tabRouterList);
      }
      this.tabRouterList = tabRouterList;
    },
    // 处理关闭其他
    subtractTabRouterOther(newRoute: TRouterInfo) {
      const { routeIdx } = newRoute;
      const homeIdx: number = this.tabRouters.findIndex((route: TRouterInfo) => route.isHome);
      this.tabRouterList = routeIdx === homeIdx ? homeRoute : homeRoute.concat([this.tabRouterList?.[routeIdx]]);
    },
    removeTabRouterList() {
      this.tabRouterList = [];
    },
    initTabRouterList(newRoutes: TRouterInfo[]) {
      newRoutes?.forEach((route: TRouterInfo) => this.appendTabRouterList(route));
    },
  },
  persist: {
    pick: ['tabRouterList'],
    serializer: {
      serialize: (state) => {
        const list = (state.tabRouterList || []).map((route: TRouterInfo) => ({
          path: route.path,
          name: route.name,
          title: route.title,
          isAlive: route.isAlive,
          isHome: route.isHome,
          routeIdx: route.routeIdx,
          // query 必须持久化：LayoutContent 切换/关闭/刷新标签都用 { path, query } 跳转，
          // 丢了以后带筛选条件的标签刷新一次就退回默认列表。
          query: route.query,
          // meta 整体不可序列化（icon 是 shallowRef 等），只留 Content.vue 真正消费的字段。
          // 其中 keepAliveName 是 keep-alive :include 的匹配依据，丢了会导致缓存全部失效，
          // 「切换标签页状态重置」的问题会在刷新后原样复现。
          meta: pickPersistableMeta(route.meta),
        }));
        return JSON.stringify({ tabRouterList: list });
      },
      deserialize: (raw) => JSON.parse(raw),
    },
  },
});

export function getTabsRouterStore() {
  return useTabsRouterStore(store);
}
