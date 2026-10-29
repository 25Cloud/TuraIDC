import 'vue-router';

import type { Component, DefineComponent, FunctionalComponent } from 'vue';

import type { LocalizedTitle } from '@/locales';

export {};
declare module 'vue-router' {
  interface RouteMeta {
    title?: LocalizedTitle;
    icon?: string | Component | FunctionalComponent | DefineComponent;
    expanded?: boolean;
    orderNo?: number;
    hidden?: boolean;
    hiddenBreadcrumb?: boolean;
    single?: boolean;
    keepAlive?: boolean;
    frameSrc?: string;
    frameBlank?: boolean;
    /** 访问该路由所需的权限码（守卫按角色权限列表判定） */
    permission?: string;
    /**
     * 该页面依赖的可选功能键（后端 App\Support\OptionalFeature）。
     * 插件未启用/已卸载时守卫拦下并退回首页，避免渲染一个接口全 404 的页面。
     */
    requiresFeature?: string;
    // roleCode?: string; // 前端 roles 控制菜单权限
  }
}
