import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 后台菜单分组回归：服务实例从「工单处理」移出，独立成「服务管理」。
 *
 * 背景：`/admin/services` 长期挂在「工单处理 → 服务与工单」下——服务实例是开通、
 * 到期、续费与实例控制台的入口，跟工单是两件事；管理员找服务入口要先点进
 * 「工单处理」再在「服务与工单」里翻，菜单层级也和实际业务对不上。
 *
 * 这里锁定：服务实例归在「服务管理」分组里、工单分组只剩工单相关页面，
 * 旧的「服务与工单」子分组标题消失。
 */

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'menu-group-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'menu-check', nickname: 'menu-check', permissions: ['*'] },
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('服务实例独立成服务管理分组，不再挂在工单处理下', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组关系');

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/services', { waitUntil: 'domcontentloaded' });

  const sidebar = page.getByRole('navigation');
  await expect(sidebar).toBeVisible({ timeout: 15000 });

  // 分组标题：新的独立分组存在，旧的「服务与工单」标题已移除
  await expect(sidebar.getByText('服务管理', { exact: true })).toBeVisible();
  await expect(sidebar.getByText('服务与工单', { exact: true })).toHaveCount(0);

  // 菜单节点本身就是分组路径，用 name 精确定位，避免折叠状态下文本串组
  const serviceGroup = sidebar.locator('li.t-submenu[name="/admin/menu/service-management"]');
  const ticketGroup = sidebar.locator('li.t-submenu[name="/admin/menu/ticket-processing"]');
  await expect(serviceGroup).toHaveCount(1);
  await expect(ticketGroup).toHaveCount(1);

  // 服务实例挂在服务管理分组下；工单分组（含其「工单管理」子分组）里没有服务实例
  await expect(serviceGroup.getByText('服务实例', { exact: true })).toHaveCount(1);
  await expect(ticketGroup.getByText('服务实例', { exact: true })).toHaveCount(0);
  await expect(ticketGroup.getByText('工单管理', { exact: true })).toHaveCount(1);

  // 两个分组都是侧边栏一级分组：父节点同为菜单根容器，且同级还有其它一级分组
  const parents = await page.evaluate(() => {
    const service = document.querySelector('li.t-submenu[name="/admin/menu/service-management"]');
    const ticket = document.querySelector('li.t-submenu[name="/admin/menu/ticket-processing"]');
    if (!service || !ticket) return null;

    return {
      sameParent: service.parentElement === ticket.parentElement,
      siblingGroups: service.parentElement?.querySelectorAll(':scope > li.t-submenu').length || 0,
    };
  });
  expect(parents).not.toBeNull();
  expect(parents!.sameParent).toBe(true);
  expect(parents!.siblingGroups).toBeGreaterThanOrEqual(5);

  await page.screenshot({
    path: `${process.env.TEMP || '.'}\\turaidc-admin-menu-service-group.png`,
    fullPage: true,
  });
});
