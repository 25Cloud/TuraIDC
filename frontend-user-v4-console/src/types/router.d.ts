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
    activeMenu?: string;
    keepAlive?: boolean;
    frameSrc?: string;
    frameBlank?: boolean;
    /**
     * 可选功能门（键名即后端 App\Support\OptionalFeature::map() 的插件 slug）。
     * 取值为 true 才允许出现侧栏入口与进入该路由，见 permission.ts 与 MenuContent.vue。
     */
    requiresFeature?: string;
    // roleCode?: string; // 前端 roles 控制菜单权限
  }
}
