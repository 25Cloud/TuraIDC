import type { RouteRecordRaw } from 'vue-router';

/**
 * AI 助手管理端页面。
 *
 * 页面托管在核心（插件规范不允许插件注册管理端菜单与路由），能力来自
 * `addons/ai_assistant` 插件：插件未启用或已卸载时 `/api/v2/admin/ai/*` 整体 404。
 * 因此两个路由都带 `requiresFeature`——侧栏分组隐藏（permission store），
 * 直接输地址也会被守卫退回首页并提示，而不是渲染一个全是报错的页面。
 */
export const aiRoutes: RouteRecordRaw[] = [
  {
    path: 'ai/settings',
    name: 'AdminAiSettings',
    component: () => import('@/pages/ai/settings.vue'),
    meta: {
      title: {
        zh_CN: 'AI 设置',
        zh_TW: 'AI 設定',
        en_US: 'AI Settings',
      },
      permission: 'ai.manage',
      requiresFeature: 'ai_assistant',
    },
  },
  {
    path: 'ai/conversations',
    name: 'AdminAiConversations',
    component: () => import('@/pages/ai/conversations.vue'),
    meta: {
      title: {
        zh_CN: 'AI 会话审计',
        zh_TW: 'AI 會話稽核',
        en_US: 'AI Conversations',
      },
      permission: 'ai.view',
      requiresFeature: 'ai_assistant',
    },
  },
];
