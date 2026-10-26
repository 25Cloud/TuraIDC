import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「站点设置」页渲染回归。
 *
 * 该页由 pages/settings/index.vue 渲染，site_basic 标签页里包含品牌主色取色器与
 * 圆角「预设 + 自定义」联动控件，这类控件在参数缺失或值非法时会把整个页面渲染挂掉，
 * 而构建与类型检查都发现不了，只有运行时才暴露。所以这里钉住三件事：
 *  1) 设置接口返回最小配置时，页面必须渲染出输入框（不是整页空白）；
 *  2) 页内所有分段一次性铺开，不再用一排 radio 一次只显示一段；
 *  3) 字段排版是「标签一行、说明一行、控件一行」，标签不与控件同行；
 *  4) 渲染过程中不允许出现未捕获的运行时错误。
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
        { key: 'browser_title', value: '示例站点 · 云计算服务平台' },
        { key: 'brand_primary_color', value: '#165dff' },
        { key: 'brand_radius', value: '6' },
      ];
      return reply({ list, total: list.length, page: 1, page_size: 100 });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('站点设置页分段渲染且无未捕获错误', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/site-info', { waitUntil: 'domcontentloaded' });

  await expect(page.getByRole('textbox').first(), '站点设置页应渲染出输入框').toBeVisible({
    timeout: 15000,
  });

  // 分段导航已移除：四段同时可见，而不是点一下换一段。
  await expect(page.locator('.section-nav')).toHaveCount(0);
  for (const title of ['品牌与外观', '联系方式与客服', '备案与合规', '通知渠道总开关']) {
    await expect(page.getByRole('heading', { name: title }), `缺少分段：${title}`).toBeVisible();
  }

  // 标签与控件分属两行：标签是独立节点，控件包在 __control 里。
  const siteNameLabel = page.locator('.admin-field__label', { hasText: '站点名称' });
  await expect(siteNameLabel).toBeVisible();
  const siteNameField = page.locator('.admin-field').filter({ has: siteNameLabel });
  await expect(siteNameField.locator('.admin-field__control input')).toHaveValue('示例站点');

  // 开关字段用 grid 排版，说明独占下一行整行。
  const switchFields = page.locator('.admin-field--switch');
  await expect(switchFields.first()).toBeVisible();

  // 标签与控件绝不同行：标签底边必须落在控件顶边之上
  const labelBox = await siteNameLabel.boundingBox();
  const controlBox = await siteNameField.locator('.admin-field__control').boundingBox();
  expect(labelBox, '标签应可测量').not.toBeNull();
  expect(controlBox, '控件应可测量').not.toBeNull();
  expect(labelBox!.y + labelBox!.height).toBeLessThanOrEqual(controlBox!.y + 1);

  // 开关字段的说明也不与控件同行：说明顶边必须在开关底边之下
  const switchField = switchFields.first();
  const switchDesc = switchField.locator('.admin-field__desc').first();
  if ((await switchDesc.count()) > 0) {
    const descBox = await switchDesc.boundingBox();
    const switchControlBox = await switchField.locator('.admin-field__control').boundingBox();
    expect(descBox, '开关说明应可测量').not.toBeNull();
    expect(switchControlBox, '开关控件应可测量').not.toBeNull();
    expect(descBox!.y).toBeGreaterThanOrEqual(switchControlBox!.y + switchControlBox!.height - 1);
  }

  // 保存操作条吸底：滚到哪都点得到
  const saveBar = page.locator('.settings-bottom-actions');
  await expect(saveBar).toBeVisible();
  expect(await saveBar.evaluate((el) => getComputedStyle(el).position)).toBe('sticky');

  expect(pageErrors, `渲染期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);
});
