import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「站点信息」设置页渲染回归。
 *
 * 该页由 pages/settings/index.vue 承载，site_basic 标签页里包含品牌主色取色器与
 * 圆角「预设 + 自定义」联动控件。这类控件在参数缺失或值非法时会把整个页面渲染挂掉，
 * 而构建与类型检查都发现不了，只有运行时才暴露，所以这里钉住两点：
 *  1) 设置接口返回最小配置时，页面必须渲染出输入框（不是整页空白）；
 *  2) 渲染过程中不允许出现未捕获的运行时错误。
 */

/** 预置 admin 会话，避免路由守卫跳登录页。 */
async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'site-info-render-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

/** 接管 /api/v2/admin，只回 auth/info 与 settings 两类必需响应。 */
async function mockAdminApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'render-check', nickname: 'render-check', permissions: ['*'] },
      });
    }

    if (pathname.endsWith('/settings')) {
      const list = [
        { key: 'site_name', value: '示例站点' },
        { key: 'browser_title', value: '示例站点' },
        { key: 'brand_color', value: '#165dff' },
        { key: 'brand_radius', value: '6' },
      ];
      return reply({ list, total: list.length, page: 1, page_size: 100 });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('站点信息页能渲染且无未捕获错误', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/site-info', { waitUntil: 'domcontentloaded' });

  await expect(page.getByRole('textbox').first(), '站点信息页应渲染出输入框').toBeVisible({
    timeout: 15000,
  });
  expect(pageErrors, `渲染期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);
});
