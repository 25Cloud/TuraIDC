import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

const REFUND_GROUP = 'li.t-submenu[name="/admin/menu/finance/auto-refund"]';

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'plugin-gate-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page, features: Record<string, boolean>) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'plugin-gate', nickname: 'plugin-gate', permissions: ['*'] },
        features,
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('插件未启用时侧栏不出现自助退款分组', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组');

  await seedAdminSession(page);
  await mockAdminApi(page, { auto_refund: false });
  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });

  const sidebar = page.getByRole('navigation');
  await expect(sidebar).toBeVisible({ timeout: 15000 });

  await expect(sidebar.locator(REFUND_GROUP)).toHaveCount(0);
  await expect(sidebar.getByText('退款申请', { exact: true })).toHaveCount(0);
  await expect(sidebar.getByText('实例管理', { exact: true })).toBeVisible();
});

test('插件启用时侧栏照常出现自助退款分组', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组');

  await seedAdminSession(page);
  await mockAdminApi(page, { auto_refund: true });
  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });

  const sidebar = page.getByRole('navigation');
  await expect(sidebar.locator(REFUND_GROUP)).toHaveCount(1, { timeout: 15000 });
});

test('插件未启用时首次直接访问退款页被退回首页', async ({ page }) => {
  await seedAdminSession(page);
  await mockAdminApi(page, { auto_refund: false });

  await page.goto('/admin/auto-refund/applications', { waitUntil: 'domcontentloaded' });

  await expect(page).toHaveURL(/\/admin\/dashboard/, { timeout: 15000 });
});
