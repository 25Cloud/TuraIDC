import type { RouteRecordRaw } from 'vue-router';

export const dashboardRoutes: RouteRecordRaw[] = [
  {
    path: 'dashboard',
    name: 'DashboardBase',
    component: () => import('@/pages/dashboard/base/index.vue'),
    meta: {
      title: {
        zh_CN: '仪表盘',
        zh_TW: '儀表盤',
        en_US: 'Dashboard',
      },
      permission: 'dashboard.view',
      keepAlive: false,
    },
  },
];
