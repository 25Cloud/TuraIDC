import type { RouteRecordRaw } from 'vue-router';

import Layout from '@/layouts/index.vue';

export const userRoutes: RouteRecordRaw[] = [
  {
    path: '/user',
    component: Layout,
    redirect: '/user/index',
    name: 'user',
    children: [
      {
        path: 'index',
        name: 'UserIndex',
        component: () => import('@/pages/user/index.vue'),
        meta: {
          title: {
            zh_CN: '个人中心',
            en_US: 'User Center',
          },
        },
      },
    ],
  },
];

export default userRoutes;
