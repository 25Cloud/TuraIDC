import type { TabValue } from 'tdesign-vue-next';
import type { Component, DefineComponent, FunctionalComponent } from 'vue';
import type { LocationQueryRaw, RouteMeta, RouteRecordName } from 'vue-router';

import type { LocalizedTitle } from '@/locales';

export interface MenuRoute {
  // TODO: menuitem 组件实际支持 string 类型但是类型错误，暂时使用 any 类型避免打包错误待组件类型修复
  path: any;
  /** 多语言标题对象，如 { zh_CN: '仪表盘', en_US: 'Dashboard' } */
  title?: LocalizedTitle;
  name?: string;
  icon?: string | Component | FunctionalComponent | DefineComponent;
  redirect?: string;
  children: MenuRoute[];
  meta: RouteMeta;
}

export type ModeType = 'dark' | 'light';

export interface UserInfo {
  name: string;
  roles: string[];
  id?: number;
  username?: string;
  nickname?: string;
  email?: string;
  role?: string;
  permissions?: string[];
  /**
   * 由插件提供、但页面托管在核心的可选功能是否可用（后端 App\Support\OptionalFeature）。
   * 侧栏分组与路由 meta.requiresFeature 都按它判定；缺省视为全部不可用。
   */
  features?: Record<string, boolean>;
}

export interface NotificationItem {
  id: string;
  content: string;
  type: string;
  status: boolean;
  collected: boolean;
  date: string;
  quality: string;
}

export interface TRouterInfo {
  path: string;
  query?: LocationQueryRaw;
  routeIdx?: number;
  /** 多语言标题对象，如 { zh_CN: '仪表盘', en_US: 'Dashboard' } */
  title?: LocalizedTitle;
  name?: RouteRecordName;
  isAlive?: boolean;
  isHome?: boolean;
  meta?: any;
}

export interface TTabRouterType {
  isRefreshing: boolean;
  tabRouterList: Array<TRouterInfo>;
}

export interface TTabRemoveOptions {
  value: TabValue;
  index: number;
  e: MouseEvent;
}
