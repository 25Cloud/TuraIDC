import { cloneDeep } from 'lodash-es';
import { defineStore } from 'pinia';
import {
  ArticleIcon,
  DashboardIcon,
  FileIcon,
  GiftIcon,
  ServerIcon,
  ShopIcon,
  ToolsIcon,
  UserCircleIcon,
} from 'tdesign-icons-vue-next';
import { shallowRef } from 'vue';
import type { RouteRecordRaw } from 'vue-router';

import { hasPermissionInList } from '@/constants/permissions';
import type { LocalizedTitle } from '@/locales';
import router, { homepageRouterList } from '@/router';
import { store } from '@/store';

interface MenuRouteConfig {
  path: string;
  title?: LocalizedTitle;
  orderNo?: number;
  showWhenHidden?: boolean;
}

type MenuNodeConfig = string | MenuRouteConfig | MenuGroupConfig;

interface MenuGroupConfig {
  path: string;
  title: LocalizedTitle;
  icon?: ReturnType<typeof shallowRef>;
  orderNo?: number;
  children: MenuNodeConfig[];
}

const ADMIN_MENU_GROUPS: MenuGroupConfig[] = [
  {
    path: '/admin/menu/data-dashboard',
    title: { zh_CN: '数据看板', en_US: 'Data Dashboard' },
    icon: shallowRef(DashboardIcon),
    orderNo: 0,
    children: [
      {
        path: '/admin/menu/data-dashboard/business-overview',
        title: { zh_CN: '经营概览', en_US: 'Business Overview' },
        children: [
          {
            path: '/admin/finance/new-customers',
            title: { zh_CN: '新客户日报', en_US: 'New Customer Daily' },
            showWhenHidden: true,
          },
        ],
      },
    ],
  },
  {
    path: '/admin/menu/product-config',
    title: { zh_CN: '产品配置', en_US: 'Product Configuration' },
    icon: shallowRef(ShopIcon),
    orderNo: 10,
    children: [
      {
        path: '/admin/menu/product-config/product-management',
        title: { zh_CN: '商品管理', en_US: 'Product Management' },
        children: ['/admin/products', '/admin/products/traffic-packages', '/admin/specs', '/admin/cpu-models'],
      },
      {
        path: '/admin/menu/product-config/upstream-supply',
        title: { zh_CN: '上游供应', en_US: 'Upstream Supply' },
        children: ['/admin/products/suppliers'],
      },
    ],
  },
  {
    path: '/admin/menu/user-management',
    title: { zh_CN: '用户管理', en_US: 'User Management' },
    icon: shallowRef(UserCircleIcon),
    orderNo: 20,
    children: [
      {
        path: '/admin/menu/user-management/customer-management',
        title: { zh_CN: '客户管理', en_US: 'Customer Management' },
        children: ['/admin/users', '/admin/users/verification', '/admin/users/verification/manage'],
      },
      {
        path: '/admin/menu/user-management/member-benefits',
        title: { zh_CN: '会员权益', en_US: 'Member Benefits' },
        children: ['/admin/member-levels'],
      },
    ],
  },
  {
    path: '/admin/menu/ticket-processing',
    title: { zh_CN: '工单处理', en_US: 'Ticket Processing' },
    icon: shallowRef(ServerIcon),
    orderNo: 30,
    children: [
      {
        path: '/admin/menu/ticket-processing/services-tickets',
        title: { zh_CN: '服务与工单', en_US: 'Services & Tickets' },
        children: [
          { path: '/admin/services', title: { zh_CN: '服务实例', en_US: 'Service Instances' } },
          '/admin/tickets',
          '/admin/ticket-delivery-rules',
        ],
      },
    ],
  },
  {
    path: '/admin/menu/finance',
    title: { zh_CN: '财务管理', en_US: 'Finance Management' },
    icon: shallowRef(FileIcon),
    orderNo: 40,
    children: [
      {
        path: '/admin/menu/finance/order-management',
        title: { zh_CN: '订单管理', en_US: 'Order Management' },
        children: [
          '/admin/finance/orders',
          '/admin/finance/orders/normal',
          '/admin/finance/orders/renewals',
          '/admin/finance/orders/upgrades',
        ],
      },
      {
        path: '/admin/menu/finance/accounting-management',
        title: { zh_CN: '账务管理', en_US: 'Accounting Management' },
        children: [
          '/admin/finance/invoices',
          { path: '/admin/finance/recharges', title: { zh_CN: '充值记录', en_US: 'Recharge Records' } },
        ],
      },
    ],
  },
  {
    path: '/admin/menu/marketing-promotion',
    title: { zh_CN: '营销推广', en_US: 'Marketing Promotion' },
    icon: shallowRef(GiftIcon),
    orderNo: 50,
    children: [
      {
        path: '/admin/menu/marketing-promotion/coupons',
        title: { zh_CN: '优惠券', en_US: 'Coupons' },
        children: ['/admin/coupons', '/admin/coupon-campaigns'],
      },
      {
        path: '/admin/menu/marketing-promotion/referral-management',
        title: { zh_CN: '推广管理', en_US: 'Referral Management' },
        children: [
          '/admin/referral',
          '/admin/referral/rewards',
          '/admin/referral/withdrawals',
          '/admin/referral-settings',
        ],
      },
    ],
  },
  {
    path: '/admin/menu/site-content',
    title: { zh_CN: '站点内容', en_US: 'Site Content' },
    icon: shallowRef(ArticleIcon),
    orderNo: 60,
    children: [
      {
        path: '/admin/menu/site-content/site-config',
        title: { zh_CN: '站点配置', en_US: 'Site Configuration' },
        children: [
          { path: '/admin/site-info', title: { zh_CN: '站点设置', en_US: 'Site Settings' } },
          { path: '/admin/site-hero', title: { zh_CN: '首页 Banner', en_US: 'Home Banner' } },
        ],
      },
      {
        path: '/admin/menu/site-content/content-management',
        title: { zh_CN: '内容管理', en_US: 'Content Management' },
        children: ['/admin/content/notices', '/admin/content/help', '/admin/content/media-library'],
      },
      {
        path: '/admin/menu/site-content/notifications-api',
        title: { zh_CN: '通知与接口', en_US: 'Notifications & APIs' },
        children: ['/admin/notifications', '/admin/notifications/sms-templates', '/admin/notifications/api-directory'],
      },
    ],
  },
  {
    path: '/admin/menu/system-settings',
    title: { zh_CN: '系统设置', en_US: 'System Settings' },
    icon: shallowRef(ToolsIcon),
    orderNo: 70,
    children: [
      {
        path: '/admin/menu/system-settings/integration-plugins',
        title: { zh_CN: '集成插件', en_US: 'Integration Plugins' },
        children: [
          '/admin/integration-plugins/captcha',
          '/admin/integration-plugins/verification',
          '/admin/integration-plugins/payment',
          '/admin/integration-plugins/mail',
          '/admin/integration-plugins/sms',
          '/admin/integration-plugins/upstream',
          '/admin/integration-plugins/addons',
        ],
      },
      {
        path: '/admin/menu/system-settings/automation',
        title: { zh_CN: '自动化', en_US: 'Automation' },
        children: ['/admin/automation'],
      },
      {
        path: '/admin/menu/system-settings/permissions',
        title: { zh_CN: '权限管理', en_US: 'Permission Management' },
        children: ['/admin/system/staff', '/admin/system/roles'],
      },
      {
        path: '/admin/menu/system-settings/database',
        title: { zh_CN: '数据库状态', en_US: 'Database Status' },
        children: ['/admin/system/database'],
      },
    ],
  },
  {
    path: '/admin/menu/log-center',
    title: { zh_CN: '日志中心', en_US: 'Log Center' },
    icon: shallowRef(FileIcon),
    orderNo: 80,
    children: [
      {
        path: '/admin/menu/log-center/log-management',
        title: { zh_CN: '日志管理', en_US: 'Log Management' },
        children: [
          '/admin/logs/system',
          '/admin/logs/runtime',
          '/admin/logs/admin-logins',
          '/admin/logs/api',
          '/admin/logs/sms',
          '/admin/logs/email',
          '/admin/logs/tasks',
          '/admin/logs/gateway',
          '/admin/logs/upstream',
          '/admin/logs/schedules',
          '/admin/logs/cleanup',
        ],
      },
    ],
  },
];

export const usePermissionStore = defineStore('permission', {
  state: () => ({
    whiteListRouters: ['/admin/login'],
    routers: [],
    removeRoutes: [],
    asyncRoutes: [],
    routesBuilt: false,
  }),
  actions: {
    async initRoutes(permissions: string[] = []) {
      const accessedRouters = this.asyncRoutes;

      // 菜单展示业务分组；真实路由仍使用 homepageRouterList 注册，不改变历史路径。
      this.routers = buildGroupedMenuRouters(cloneDeep([...homepageRouterList, ...accessedRouters]), permissions);
      // 在菜单只展示动态路由和首页
      // this.routers = [...homepageRouterList, ...accessedRouters];
      // 在菜单只展示动态路由
      // this.routers = [...accessedRouters];
    },
    async buildAsyncRoutes(permissions: string[] = []) {
      try {
        this.asyncRoutes = [];
        this.routesBuilt = true;
        await this.initRoutes(permissions);
        return this.asyncRoutes;
      } catch (error) {
        throw new Error("Can't build routes", { cause: error });
      }
    },
    async restoreRoutes() {
      // 不需要在此额外调用initRoutes更新侧边导肮内容，在登录后asyncRoutes为空会调用
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

function buildGroupedMenuRouters(routes: RouteRecordRaw[], permissions: string[]) {
  return routes.flatMap((route) => {
    if (route.path !== '/admin') return route;

    const children = Array.isArray(route.children) ? route.children : [];
    const routeMap = buildAdminRouteMap(children);
    return ADMIN_MENU_GROUPS.map((group, index) => buildMenuGroup(group, routeMap, permissions, index)).filter(
      (group): group is RouteRecordRaw => Boolean(group?.children?.length),
    );
  });
}

function buildAdminRouteMap(adminChildren: RouteRecordRaw[]) {
  const routeMap = new Map<string, RouteRecordRaw>();
  adminChildren.forEach((child) => {
    const fullPath = resolveAdminChildPath(child.path);
    routeMap.set(fullPath, {
      ...child,
      path: fullPath,
    });
  });

  return routeMap;
}

function buildMenuGroup(
  group: MenuGroupConfig,
  routeMap: Map<string, RouteRecordRaw>,
  permissions: string[],
  siblingIndex: number,
): RouteRecordRaw | null {
  const groupChildren = group.children
    .map((child, index) => buildMenuNode(child, routeMap, permissions, index))
    .filter((item): item is RouteRecordRaw => Boolean(item));
  const redirect = findFirstMenuLeafPath(groupChildren);

  if (!redirect) {
    return null;
  }

  return {
    path: group.path,
    redirect,
    meta: {
      title: group.title,
      ...(group.icon ? { icon: group.icon } : {}),
      orderNo: group.orderNo ?? siblingIndex,
    },
    children: groupChildren,
  };
}

function buildMenuNode(
  node: MenuNodeConfig,
  routeMap: Map<string, RouteRecordRaw>,
  permissions: string[],
  siblingIndex: number,
): RouteRecordRaw | null {
  const config = normalizeMenuNode(node);

  if (isMenuGroupConfig(config)) {
    return buildMenuGroup(config, routeMap, permissions, siblingIndex);
  }

  const route = routeMap.get(config.path);
  if (!route || !canAccessMenuRoute(route, permissions)) {
    return null;
  }

  return applyMenuRouteOverrides(route, config, siblingIndex);
}

function normalizeMenuNode(child: MenuNodeConfig): MenuRouteConfig | MenuGroupConfig {
  return typeof child === 'string' ? { path: child } : child;
}

function isMenuGroupConfig(config: MenuRouteConfig | MenuGroupConfig): config is MenuGroupConfig {
  return Array.isArray((config as MenuGroupConfig).children);
}

function applyMenuRouteOverrides(route: RouteRecordRaw, config: MenuRouteConfig, siblingIndex: number): RouteRecordRaw {
  return {
    ...route,
    meta: {
      ...route.meta,
      ...(config.title ? { title: config.title } : {}),
      ...(config.showWhenHidden ? { hidden: false } : {}),
      orderNo: config.orderNo ?? siblingIndex,
    },
  };
}

function findFirstMenuLeafPath(routes: RouteRecordRaw[]): string | undefined {
  for (const route of routes) {
    if (Array.isArray(route.children) && route.children.length > 0) {
      const childPath = findFirstMenuLeafPath(route.children);
      if (childPath) return childPath;
      continue;
    }

    return String(route.path);
  }

  return undefined;
}

function resolveAdminChildPath(path: RouteRecordRaw['path']) {
  const rawPath = String(path);
  if (rawPath.startsWith('/')) return rawPath;
  return `/admin/${rawPath}`.replace(/\/+/g, '/');
}

function canAccessMenuRoute(route: RouteRecordRaw, permissions: string[]) {
  const permission = route.meta?.permission;
  if (typeof permission !== 'string' || permission === '') return true;

  return hasPermissionInList(permissions, permission);
}
