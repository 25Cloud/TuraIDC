import type { RouteRecordRaw } from 'vue-router';

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
    },
  },
];
