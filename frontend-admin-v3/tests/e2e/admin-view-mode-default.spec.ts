import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「默认展示方式」对列表页初始形态的约束回归。
 *
 * 背景：此前每个页面把「列表 / 卡片」偏好单独写进 localStorage，导致管理员在
 * 设置面板改了默认值之后，历史偏好仍然压住新默认值——表现为「明明设成列表，
 * 重新打开工单管理还是卡片」。现在只持久化一个默认值：
 *  - 列表页初始形态 = 默认值；页面内切换只在当前会话有效；
 *  - 旧版本遗留的页面级偏好键在启动时清掉，不再影响新默认值。
 */

async function seedAdminSession(page: Page, viewModeDefault?: 'list' | 'card') {
  await page.addInitScript((defaultMode) => {
    window.localStorage.setItem('admin_token', 'view-mode-default-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    if (defaultMode) {
      window.localStorage.setItem('turaidc:admin-view-mode-default', defaultMode);
    }
  }, viewModeDefault);
}

async function mockAdminApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'default-mode-check', nickname: 'default-mode-check', permissions: ['*'] },
      });
    }

    if (pathname.endsWith('/tickets')) {
      return reply({
        list: [
          {
            id: 2201,
            subject: '默认展示方式用例工单',
            status: 'staff_reply',
            priority: 3,
            department: 'support',
            user_id: 88,
            user: { id: 88, nickname: 'alice', email: 'alice@example.com' },
            assignee: { id: 2, nickname: 'ops-a' },
            updated_at: '2026-10-18 09:30:00',
            created_at: '2026-10-18 08:00:00',
          },
        ],
        total: 1,
        page: 1,
        page_size: 20,
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('默认设为列表时，工单页即使有历史卡片偏好也按列表打开', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端才展示列表/卡片切换');

  await seedAdminSession(page, 'list');
  // 模拟旧版本遗留的页面级偏好：不得再压住默认值
  await page.addInitScript(() => {
    window.localStorage.setItem('turaidc:admin-view-mode:admin-tickets', 'card');
  });
  await mockAdminApi(page);

  await page.goto('/admin/tickets', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.table-scroll .t-table')).toBeVisible({ timeout: 15000 });
  await expect(page.locator('.ticket-card')).toHaveCount(0);

  // 遗留键应被清掉，避免下次打开又被带偏
  const legacyValue = await page.evaluate(() =>
    window.localStorage.getItem('turaidc:admin-view-mode:admin-tickets'),
  );
  expect(legacyValue).toBeNull();
});

test('默认设为卡片时，工单页按卡片打开', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端才展示列表/卡片切换');

  await seedAdminSession(page, 'card');
  await mockAdminApi(page);

  await page.goto('/admin/tickets', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.ticket-card').first()).toBeVisible({ timeout: 15000 });
  await expect(page.locator('.table-scroll .t-table')).toHaveCount(0);
});

test('在设置面板切换默认值后，当前列表页立即跟随', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端才展示列表/卡片切换');

  await seedAdminSession(page, 'list');
  await mockAdminApi(page);

  await page.goto('/admin/tickets', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.table-scroll .t-table')).toBeVisible({ timeout: 15000 });

  // 顶栏设置入口（aria-label 来自 layout.header.setting）
  await page.locator('button[aria-label="系统设置"]').click();

  const drawer = page.locator('.setting-drawer-container');
  await expect(drawer).toBeVisible();
  await drawer.getByText('卡片式', { exact: true }).click();

  // 无需刷新：默认值变化后已打开的列表页立即切到卡片
  await expect(page.locator('.ticket-card').first()).toBeVisible();

  const storedDefault = await page.evaluate(() =>
    window.localStorage.getItem('turaidc:admin-view-mode-default'),
  );
  expect(storedDefault).toBe('card');
});
