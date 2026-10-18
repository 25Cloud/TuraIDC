import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 集成插件管理「列表 / 卡片」切换回归。
 *
 * 后台其它列表页都已有统一的两图标切换，只有插件页固定卡片形态：
 * 插件多起来以后，管理员想按「状态 + 最近运行」逐行扫一遍很不方便。
 * 这里锁定：
 *  1) 切换条在页面工具条右侧、与整体列表页位置一致；
 *  2) 卡片式仍展示插件名/域/版本/运行状态；
 *  3) 列表式显示同一批插件的表格（名称、域、版本、状态、最近运行、操作）；
 *  4) 两种视图的启用/停用条件来自同一处，不因视图不同而分叉。
 */

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'plugin-view-mode-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

const PLUGINS = [
  {
    id: 101,
    domain: 'captcha',
    slug: 'geetest',
    key: 'geetest',
    name: '极验行为验证',
    version: '1.2.0',
    is_installed: true,
    is_enabled: true,
    latest_runtime_log: { status: 'success', created_at: '2026-10-18 09:00:00' },
  },
  {
    id: 102,
    domain: 'captcha',
    slug: 'turnstile',
    key: 'turnstile',
    name: 'Cloudflare Turnstile',
    version: '0.9.1',
    is_installed: true,
    is_enabled: false,
    latest_runtime_log: { status: 'failed', created_at: '2026-10-18 08:00:00' },
  },
  {
    id: 103,
    domain: 'captcha',
    slug: 'recaptcha',
    key: 'recaptcha',
    name: 'Google reCAPTCHA',
    version: '2.0.0',
    is_installed: false,
    is_enabled: false,
    latest_runtime_log: null,
  },
];

async function mockAdminApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: {
          id: 1,
          username: 'plugin-view-check',
          nickname: 'plugin-view-check',
          permissions: ['*'],
        },
      });
    }

    if (pathname.endsWith('/integration-plugins')) {
      return reply({ list: PLUGINS, total: PLUGINS.length });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('插件管理：卡片/列表切换可用，两种视图内容一致', async ({ page }, testInfo) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/integration-plugins/captcha', { waitUntil: 'domcontentloaded' });

  // 默认仍是卡片式（既有形态）
  await expect(page.locator('.plugin-card')).toHaveCount(PLUGINS.length, { timeout: 15000 });
  await expect(page.locator('.plugin-card').first()).toContainText('极验行为验证');

  if (testInfo.project.name !== 'desktop') {
    // 平板竖屏与手机不出现切换入口，仍是卡片
    await expect(page.locator('.shop-view-switch, .admin-view-mode-switch')).toHaveCount(0);
    await expect(page.locator('.plugin-card')).toHaveCount(PLUGINS.length);
    await page.screenshot({
      path: `${process.env.TEMP || '.'}\\turaidc-admin-plugins-card-${testInfo.project.name}.png`,
      fullPage: true,
    });
    return;
  }

  // 切换条在工具条右侧，不另起一行悬在半空
  const toolbar = page.locator('.plugins-toolbar');
  const toolbarBox = await toolbar.boundingBox();
  const switchBox = await page.locator('.admin-view-mode-switch').boundingBox();
  expect(toolbarBox).not.toBeNull();
  expect(switchBox).not.toBeNull();
  expect(switchBox!.x).toBeGreaterThan(toolbarBox!.x + toolbarBox!.width / 2);

  // 卡片态保留运行状态标签
  await expect(page.locator('.plugin-card').nth(0)).toContainText('最近运行 成功');

  // 切到列表式
  await page.getByRole('button', { name: '列表式' }).click();
  await expect(page.locator('.plugin-card')).toHaveCount(0);

  const table = page.locator('.plugins-table-card .t-table');
  await expect(table).toBeVisible();
  await expect(table.locator('.t-table__body tr')).toHaveCount(PLUGINS.length);

  // 名称与业务标识同格展示，状态/运行记录不丢失
  await expect(table.locator('.plugin-name-cell').first()).toContainText('极验行为验证');
  await expect(table.locator('.plugin-name-cell').first()).toContainText('geetest');
  await expect(table).toContainText('极验行为验证');
  await expect(table).toContainText('Cloudflare Turnstile');
  await expect(table).toContainText('v1.2.0');
  await expect(table).toContainText('最近运行 成功');
  await expect(table).toContainText('最近运行 失败');
  await expect(table).toContainText('暂无运行记录');
  // 未安装的插件操作列是「安装」，已启用的可停用
  await expect(table.getByRole('button', { name: '安装' })).toBeVisible();
  await expect(table.getByRole('button', { name: '停用' })).toBeVisible();

  await page.screenshot({
    path: `${process.env.TEMP || '.'}\\turaidc-admin-plugins-list-desktop.png`,
    fullPage: true,
  });

  // 切回卡片式，两种视图互不残留
  await page.getByRole('button', { name: '卡片式' }).click();
  await expect(page.locator('.plugin-card')).toHaveCount(PLUGINS.length);
  await expect(page.locator('.plugins-table-card')).toHaveCount(0);

  expect(pageErrors, `切换期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);
});
