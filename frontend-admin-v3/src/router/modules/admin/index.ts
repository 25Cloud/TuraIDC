import { DashboardIcon } from 'tdesign-icons-vue-next';
import { shallowRef } from 'vue';
import type { RouteRecordRaw } from 'vue-router';

import Layout from '@/layouts/index.vue';

import { contentRoutes } from './content';
import { dashboardRoutes } from './dashboard';
import { financeRoutes } from './finance';
import { marketingRoutes } from './marketing';
import { productsRoutes } from './products';
import { systemRoutes } from './system';
import { ticketsRoutes } from './tickets';
import { usersRoutes } from './users';

const adminRoutes: RouteRecordRaw[] = [
  {
    path: '/admin',
    component: Layout,
    redirect: '/admin/dashboard',
    name: 'dashboard',
    meta: {
      title: {
        zh_CN: '仪表盘',
        en_US: 'Dashboard',
      },
      icon: shallowRef(DashboardIcon),
      orderNo: 0,
    },
    children: [
      ...dashboardRoutes,
      ...usersRoutes,
      ...financeRoutes,
      ...ticketsRoutes,
      ...productsRoutes,
      ...contentRoutes,
      ...marketingRoutes,
      ...systemRoutes,
    ],
  },
];

export default adminRoutes;
