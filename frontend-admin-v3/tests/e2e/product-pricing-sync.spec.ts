import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 商品定价「按月价同步到其他周期」（Issue #77）。
 *
 * 契约：`PUT /api/v2/admin/products/{product}/pricing/copy`，入参
 * `{from_cycle, to_cycles, overwrite}`；金额由服务端按 `BillingCycle` 月数换算。
 * 这里只桩接口，不打真实网络。
 *
 * 覆盖四件真会坏的事：
 * 1. 预览弹窗按「月付价 × 月数」算出季/半年/年，并标出已有价格的周期；
 * 2. 勾选决定提交哪些周期（未勾的年付不进 to_cycles），提交体键名与契约一致；
 * 3. 关闭「覆盖已有价格」时，结果按「覆盖 / 跳过」分列，跳过的那档要说出原因；
 * 4. 定价有未保存改动时拦住同步——服务端只认库里的价，不拦就会「按 A 预览、按 B 落库」。
 */

const PRICING_SECTION = 'section.product-edit-section';

// 桩数据：显式标成 Record<string, unknown>（tsconfig 没开 strictNullChecks，
// 裸对象字面量里的 null/[] 会被判成隐式 any，vue-tsc 直接报 TS7018）
const PRODUCT_DETAIL: Record<string, unknown> = {
  product: {
    id: 77,
    display: {
      display_name: '香港 CN2 测试机',
      custom_display_name: '',
      product_spec_display: '2C2G',
      description: '',
    },
    classification: {
      product_type: 'vps',
      product_type_label: '云服务器',
      first_product_group_id: 1,
      second_product_group_id: 2,
      third_product_group_id: 3,
      second_product_group_name: '香港',
      third_product_group_name: '香港机房',
      category_full_name: '云服务器 / 香港 / 香港机房',
    },
    pricing: {
      items: { monthly: '33.33', quarterly: '9.99', semiannually: '0.00', annually: '0.00' },
      primary_price: { cycle: 'monthly', amount: '33.33' },
      setup_fee: '0.00',
    },
    configuration: { console_template: 'compute', config_options: [] },
    purchase_requirements: { require_verification: false, require_phone: false, provision_hostname: null },
    provisioning: { stock: -1, auto_setup: 0 },
    lifecycle: { status: 1, lifecycle_status: 'active', sort_order: 0, deleted_at: null },
    timestamps: { created_at: '2026-11-01 10:00:00', updated_at: '2026-11-01 10:00:00' },
    upstream_binding: null,
  },
};

/** 服务端返回的同步结果：pricing 是同步后的完整价目表，前端直接回填表单 */
function syncResult(overwrite: boolean) {
  if (overwrite) {
    return {
      product_id: 77,
      from_cycle: 'monthly',
      from_price: '33.33',
      pricing: { monthly: '33.33', quarterly: '99.99', semiannually: '199.98', annually: '0.00' },
      updated: [
        { cycle: 'quarterly', label: '季付', months: 3, before: '9.99', after: '99.99' },
        { cycle: 'semiannually', label: '半年付', months: 6, before: '0.00', after: '199.98' },
      ],
      skipped: [],
    };
  }

  return {
    product_id: 77,
    from_cycle: 'monthly',
    from_price: '33.33',
    pricing: { monthly: '33.33', quarterly: '9.99', semiannually: '199.98', annually: '0.00' },
    updated: [{ cycle: 'semiannually', label: '半年付', months: 6, before: '0.00', after: '199.98' }],
    skipped: [{ cycle: 'quarterly', label: '季付', reason: 'existing_price', current: '9.99' }],
  };
}

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'product-pricing-sync-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page, bodies: Array<Record<string, unknown>>) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname;
    const method = route.request().method();
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'pricing-admin', nickname: '定价专员', permissions: ['*'] },
        features: {},
      });
    }

    if (pathname.endsWith('/products/77/pricing/copy') && method === 'PUT') {
      const body = (route.request().postDataJSON() || {}) as Record<string, unknown>;
      bodies.push(body);

      return reply(syncResult(Boolean(body.overwrite)));
    }

    if (pathname.endsWith('/products/77') && method === 'GET') {
      return reply(PRODUCT_DETAIL);
    }

    if (pathname.endsWith('/products/77/description')) {
      return reply({ product_id: 77, description: '', html: '', source: 'empty', description_sync_enabled: 0 });
    }

    if (pathname.endsWith('/product-groups/tree')) {
      return reply({ tree: [] });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

async function openPricingSection(page: Page) {
  await page.goto('/admin/products/77/edit', { waitUntil: 'domcontentloaded' });

  // 定价区在左侧导航里切换（section 用 v-show，未切换时是 display:none）
  const navItem = page.locator('.product-edit-nav-item', { hasText: '定价' }).first();
  await expect(navItem).toBeVisible({ timeout: 15000 });
  await navItem.click();

  const pricingSection = page.locator(PRICING_SECTION).filter({ hasText: '定价' }).first();
  await expect(pricingSection.locator('.t-input-number input').first()).toBeVisible();

  return pricingSection;
}

test('预览按「月付价 × 月数」算出各周期，并标出已有价格', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '定价编辑区在桌面端布局稳定');

  const bodies: Array<Record<string, unknown>> = [];
  await seedAdminSession(page);
  await mockAdminApi(page, bodies);

  const pricingSection = await openPricingSection(page);
  await pricingSection.getByRole('button', { name: '同步到其他周期' }).click();

  const dialog = page.locator('.t-dialog').filter({ hasText: '按月价同步到其他周期' });
  await expect(dialog).toBeVisible({ timeout: 15000 });

  // 基准价：月付 33.33
  await expect(dialog).toContainText('33.33');

  // 季 9.99 → 99.99（已有价格）；半年 0.00 → 199.98；年 0.00 → 399.96
  await expect(dialog).toContainText('99.99');
  await expect(dialog).toContainText('199.98');
  await expect(dialog).toContainText('399.96');
  await expect(dialog).toContainText('×3 个月');
  await expect(dialog).toContainText('×6 个月');
  await expect(dialog).toContainText('×12 个月');
  await expect(dialog).toContainText('已有价格');

  // 折扣与优惠券的处置必须写明白，不能静默丢弃
  await expect(dialog).toContainText('折扣分组与代理折扣按「商品 / 折扣分组」生效');
  await expect(dialog).toContainText('优惠券与优惠活动的「适用周期」是独立配置');
});

test('勾选决定提交哪些周期，提交体是 {from_cycle, to_cycles, overwrite}', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '定价编辑区在桌面端布局稳定');

  const bodies: Array<Record<string, unknown>> = [];
  await seedAdminSession(page);
  await mockAdminApi(page, bodies);

  const pricingSection = await openPricingSection(page);
  await pricingSection.getByRole('button', { name: '同步到其他周期' }).click();

  const dialog = page.locator('.t-dialog').filter({ hasText: '按月价同步到其他周期' });
  await expect(dialog).toBeVisible({ timeout: 15000 });

  // 默认全选；取消年付后只剩季付与半年付
  await expect(dialog.getByRole('checkbox', { name: '年付', exact: true })).toBeChecked();
  await dialog.getByText('年付', { exact: true }).click();
  await expect(dialog.getByRole('checkbox', { name: '年付', exact: true })).not.toBeChecked();

  await dialog.getByRole('button', { name: '确认同步' }).click();

  await expect.poll(() => bodies.length).toBe(1);
  expect(bodies[0]).toEqual({
    from_cycle: 'monthly',
    to_cycles: ['quarterly', 'semiannually'],
    overwrite: true,
  });

  // 落库成功后表单按服务端返回回填：季付 9.99 → 99.99
  const inputs = pricingSection.locator('.t-input-number input');
  await expect(inputs.nth(1)).toHaveValue('99.99');
  await expect(inputs.nth(2)).toHaveValue('199.98');
});

test('关闭「覆盖已有价格」时结果按覆盖/跳过分列并说明原因', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '定价编辑区在桌面端布局稳定');

  const bodies: Array<Record<string, unknown>> = [];
  await seedAdminSession(page);
  await mockAdminApi(page, bodies);

  const pricingSection = await openPricingSection(page);
  await pricingSection.getByRole('button', { name: '同步到其他周期' }).click();

  const dialog = page.locator('.t-dialog').filter({ hasText: '按月价同步到其他周期' });
  await expect(dialog).toBeVisible({ timeout: 15000 });

  // 关掉覆盖开关：只补空档
  await dialog.locator('.pricing-sync-overwrite .t-switch').click();
  await dialog.getByRole('button', { name: '确认同步' }).click();

  await expect.poll(() => bodies.length).toBe(1);
  expect(bodies[0]).toEqual({
    from_cycle: 'monthly',
    to_cycles: ['quarterly', 'semiannually', 'annually'],
    overwrite: false,
  });

  // 结果分列：覆盖半年付、跳过季付（已有价格 9.99）
  const result = pricingSection.locator('.product-edit-pricing-sync-result');
  await expect(result).toBeVisible({ timeout: 15000 });
  await expect(result).toContainText('同步完成：覆盖 1 个周期，跳过 1 个');
  await expect(result).toContainText('覆盖 半年付：0.00 → 199.98');
  await expect(result).toContainText('跳过 季付：已有价格 9.99');
});

test('定价有未保存改动时不允许同步', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '定价编辑区在桌面端布局稳定');

  const bodies: Array<Record<string, unknown>> = [];
  await seedAdminSession(page);
  await mockAdminApi(page, bodies);

  const pricingSection = await openPricingSection(page);
  const monthlyInput = pricingSection.locator('.t-input-number input').first();
  await monthlyInput.fill('40');
  await monthlyInput.blur();

  await pricingSection.getByRole('button', { name: '同步到其他周期' }).click();

  // 服务端只按库里的价换算：未保存就点同步会「按 A 预览、按 B 落库」，必须拦住
  await expect(page.getByText('定价有未保存的修改，请先保存再同步')).toBeVisible();
  await expect(page.locator('.t-dialog').filter({ hasText: '按月价同步到其他周期' })).toBeHidden();
  expect(bodies).toHaveLength(0);
});
