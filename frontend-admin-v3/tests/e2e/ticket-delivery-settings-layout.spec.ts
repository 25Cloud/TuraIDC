import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「工单传递设置」页回归。
 *
 * 该页由两张卡片组成：规则列表 + 上传防护配置。此前的问题是排版层面的：
 * 两张卡片之间没有间距、防护表单里开关与说明文字挤在一起、输入控件宽窄不一，
 * 视觉上像随手堆出来的。这里锁定：
 *  1) 页面卡片之间有一致的纵向间距；
 *  2) 开关与说明文字之间留出空隙，说明独占一行而不贴着开关；
 *  3) 渲染期没有未捕获错误。
 */

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'delivery-settings-token');
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
        admin: { id: 1, username: 'delivery-check', nickname: 'delivery-check', permissions: ['*'] },
      });
    }

    if (pathname.endsWith('/ticket-delivery-rules')) {
      return reply({
        list: [
          {
            id: 1,
            name: '默认传递规则',
            department: 'support',
            supplier_id: 9,
            product_scope_mode: 'all',
            upstream_department_id: '5',
            enabled: 1,
            sync_admin_replies: 1,
          },
        ],
      });
    }

    if (pathname.endsWith('/ticket-delivery-upload-guard')) {
      return reply({
        upload_image_enabled: true,
        allowed_ips: '1.1.1.1\n2.2.2.2',
        rate_limit: 30,
        block_non_whitelisted: true,
        unused_retention_minutes: 5,
      });
    }

    if (pathname.endsWith('/suppliers')) {
      return reply({
        list: [{ id: 9, name: '智简魔方财务', provider_key: 'zjmf_finance_api', status: 1 }],
        total: 1,
      });
    }

    if (pathname.endsWith('/products')) {
      return reply({
        list: [{ id: 11, display_name: '香港精品 2核2G', name: '香港精品 2核2G' }],
        total: 1,
        page: 1,
        page_size: 100,
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('工单传递设置页排版：卡片有间距、开关与说明不粘连', async ({ page }, testInfo) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/ticket-delivery-rules', { waitUntil: 'domcontentloaded' });

  const cards = page.locator('.ticket-delivery-card');
  await expect(cards).toHaveCount(2, { timeout: 15000 });
  await expect(page.getByText('默认传递规则')).toBeVisible();

  // 两张卡片之间要有 16px 级别的纵向间距，不能贴在一起
  const cardGap = await page.locator('.ticket-delivery-page').evaluate((element) => {
    const computed = window.getComputedStyle(element);
    return { display: computed.display, gap: computed.gap };
  });
  expect(cardGap.display).toBe('flex');
  expect(parseFloat(cardGap.gap)).toBeGreaterThanOrEqual(12);

  if (testInfo.project.name === 'desktop') {
    // 开关与说明文字之间要有间距，说明不能紧贴开关
    const switchRow = page.locator('.ticket-delivery-guard-form .t-form__item').first();
    const spacing = await switchRow.evaluate((element) => {
      const controls = element.querySelector('.t-form__controls-content');
      const hint = element.querySelector('.ticket-delivery-guard-hint');
      if (!controls || !hint) return null;

      const controlsStyle = window.getComputedStyle(controls);
      const hintRect = hint.getBoundingClientRect();
      const switchRect = controls.firstElementChild?.getBoundingClientRect();

      return {
        wrap: controlsStyle.flexWrap,
        gap: parseFloat(controlsStyle.columnGap || '0'),
        leftGap: switchRect ? hintRect.left - switchRect.right : null,
      };
    });

    expect(spacing, '防护表单首个开关行应存在说明文字').not.toBeNull();
    expect(spacing!.wrap).toBe('wrap');
    expect(spacing!.gap).toBeGreaterThanOrEqual(8);
    expect(spacing!.leftGap).toBeGreaterThanOrEqual(8);
  }

  expect(pageErrors, `渲染期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);

  await page.screenshot({
    path: `${process.env.TEMP || '.'}\\turaidc-admin-delivery-settings-${testInfo.project.name}.png`,
    fullPage: true,
  });

  if (testInfo.project.name === 'desktop') {
    // 新增规则弹窗：两列表单 + 下方整宽字段，检查不会被挤成一列窄条
    await page.getByRole('button', { name: '新增规则' }).click();

    const dialog = page.locator('.t-dialog:visible');
    await expect(dialog).toBeVisible();
    // 弹窗有 200~300ms 的缩放/淡入动画，截图要等动画结束，否则会截到两层叠影
    await page.waitForTimeout(400);

    // 新建规则默认「全部产品」，打开时不应挂着「至少选择一个产品」的必填红框
    await expect(dialog.locator('.t-is-error')).toHaveCount(0);

    const columns = await dialog.locator('.ticket-delivery-form-grid').evaluate((element) => {
      const computed = window.getComputedStyle(element);
      return { display: computed.display, template: computed.gridTemplateColumns };
    });
    expect(columns.display).toBe('grid');
    expect(columns.template.split(' ').length).toBe(2);

    await page.screenshot({
      path: `${process.env.TEMP || '.'}\\turaidc-admin-delivery-settings-dialog.png`,
      fullPage: true,
    });
  }
});
