import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「商品展示」独立设置页的渲染与保存回归。
 *
 * 背景：选购页的排版、卡片库存、独立配置页的机型对比与版心，此前混在
 * 「站点设置 → 站点信息」那张大表单里，既难找也容易误改。现在单独成一页，
 * 由 pages/product-display/index.vue 承载、settings 接口（basic 组）读写。
 *
 * 这里锚住两件事：
 *  1) 设置接口返回最小配置时页面必须正常渲染（不是空白或报错）；
 *  2) 保存时把这五项按 basic 组一次提交，且开关按 1 / 0 存。
 */

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'product-display-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

function settingItem(key: string, value: unknown) {
  return { key, value };
}

async function mockAdminApi(page: Page, savedPayloads: unknown[]) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data }),
      });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'display-check', nickname: 'display-check', permissions: ['*'] },
      });
    }

    if (pathname.endsWith('/settings')) {
      if (request.method() === 'POST') {
        savedPayloads.push(request.postDataJSON());
        return reply({ ok: true });
      }

      const list = [
        settingItem('product_display_mode', 'card'),
        settingItem('product_card_show_stock', '1'),
        settingItem('product_detail_spec_compare', '1'),
        settingItem('product_page_layout', 'contained'),
        settingItem('product_page_max_width', '1400'),
      ];

      return reply({ list, total: list.length, page: 1, page_size: 100 });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('商品展示页渲染分组配置并按 basic 组保存', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  const savedPayloads: unknown[] = [];
  await seedAdminSession(page);
  await mockAdminApi(page, savedPayloads);

  await page.goto('/admin/product-display', { waitUntil: 'domcontentloaded' });

  // 四个分组各自成段：展示形态 / 卡片样式 / 独立配置页 / 页面版心
  for (const title of ['展示形态', '卡片式样式', '独立配置页', '页面版心']) {
    await expect(page.getByText(title, { exact: true }).first()).toBeVisible({
      timeout: 15000,
    });
  }

  // 接口回读的值要落到表单上：卡片式、居中、最大宽度 1400
  await expect(page.getByText('卡片式', { exact: true }).first()).toBeVisible();
  await expect(page.getByText('居中', { exact: true }).first()).toBeVisible();

  // 切到列表式再保存，验证提交结构
  await page.getByText('列表式', { exact: true }).first().click();
  await page.getByRole('button', { name: '保存设置' }).click();

  await expect.poll(() => savedPayloads.length, { timeout: 10000 }).toBe(1);

  expect(savedPayloads[0]).toMatchObject({
    group: 'basic',
    settings: {
      product_display_mode: 'list',
      product_card_show_stock: 1,
      product_detail_spec_compare: 1,
      product_page_layout: 'contained',
      product_page_max_width: 1400,
    },
  });

  expect(pageErrors, `渲染期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);

  // 视觉快照落临时目录，便于人工比对三档视口下的分组排版
  await page.screenshot({
    path: `${process.env.TEMP || '.'}\\turaidc-admin-product-display-${test.info().project.name}.png`,
    fullPage: true,
  });
});
