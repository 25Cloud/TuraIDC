import type { RouteRecordRaw } from 'vue-router';

import { hasPermissionInList } from '@/constants/permissions';
import adminRoutes from '@/router/modules/admin';

/**
 * 管理端首页路径的唯一真源。
 * 标签栏首页标签、左上角 logo 跳转、登录后落地页都必须复用它，
 * 不要再各自写字符串——历史上写死的 '/dashboard/base' 并无对应路由，会直接 404。
 */
export const ADMIN_HOME_PATH = '/admin/dashboard';

/**
 * 解析管理端默认落地页（登录后跳转、守卫无权限回退共用）：
 * 有 dashboard.view 时固定回主页；否则按路由注册顺序返回第一个可访问的子路由；
 * 没有任何可访问页面时回退到 403 页，避免无权限角色被反复重定向回 /admin/dashboard 造成自循环卡死。
 */
export function resolveAdminHomePath(permissions: string[]): string {
  const home = ADMIN_HOME_PATH;
  if (hasPermissionInList(permissions, 'dashboard.view')) {
    return home;
  }

  const children = (adminRoutes[0]?.children ?? []) as RouteRecordRaw[];
  for (const child of children) {
    const meta = child.meta as { permission?: string; hidden?: boolean } | undefined;
    if (meta?.hidden) {
      continue;
    }
    const path = child.path;
    if (typeof path !== 'string' || path === '' || path.includes(':') || path.startsWith('/')) {
      continue;
    }
    // 仅 redirect 的包装路由（如 /admin/payment）没有可渲染页面：落到它会二次重定向
    // 到带权限的页面，被守卫拒绝后再次回跳到该包装路由，形成新的自循环。
    if (child.redirect && !child.component) {
      continue;
    }
    const required = meta?.permission;
    if (required && required !== '' && !hasPermissionInList(permissions, required)) {
      continue;
    }

    return `/admin/${path}`;
  }

  return '/admin/403';
}
