import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

import type { ProductGroupV2Record } from '../../src/api/product';

const secondGroupName = '云服务器';

function groupTree(): ProductGroupV2Record[] {
  return [
    {
      id: 50,
      name: '产品分组',
      level: 1,
      product_type: 'cloud_server',
      first_product_group_id: 50,
      first_product_group_code: 'vps',
      effective_product_group_id: 50,
      effective_product_group_level: 1,
      status: 1,
      children: [
        {
          id: 52,
          name: secondGroupName,
          parent_id: 50,
          level: 2,
          product_type: 'cloud_server',
          first_product_group_id: 50,
          first_product_group_code: 'vps',
          second_product_group_id: 52,
          effective_product_group_id: 52,
          effective_product_group_level: 2,
          status: 1,
          children: [],
        } as ProductGroupV2Record,
      ],
    } as ProductGroupV2Record,
  ];
}

/** 商品详情（编辑页用）是嵌套结构 */
function productDetail(): Record<string, unknown> {
  return {
    id: 101,
    display: {
      display_name: '标准云服务器',
      product_display_name: '标准云服务器',
      product_spec_display: '2C4G',
      combined_display_name: '标准云服务器 2C4G',
    },
    classification: {
      product_type: 'cloud_server',
      product_type_label: '云服务器',
      first_product_group_id: 50,
      first_product_group_code: 'vps',
      second_product_group_id: 52,
      effective_product_group_id: 52,
      effective_product_group_level: 2,
    },
    pricing: { items: { monthly: 88 }, primary_price: { cycle: 'monthly', amount: '88.00' } },
    configuration: { console_template: 'compute', config_options: [] },
    purchase_requirements: { require_verification: false, require_phone: false },
    provisioning: { stock: -1, auto_setup: 1 },
    upstream_binding: { supplier_id: 3, upstream_product_id: 300 },
    statistics: { orders_count: 0, services_count: 0, active_services_count: 0 },
    lifecycle: { status: 1, lifecycle_status: 'active', deleted_at: null, sort_order: 1 },
    timestamps: { created_at: '2026-06-06 10:00:00', updated_at: '2026-06-06 10:00:00' },
  };
}

/**
 * 商品列表用的是扁平摘要记录（src/api/product.ts 的 normalizeV2ProductListItem
 * 直接读 name/display_name/monthly_price 等顶层字段），不是详情页的嵌套结构。
 */
function productSummary(): Record<string, unknown> {
  return {
    id: 101,
    name: '标准云服务器',
    display_name: '标准云服务器',
    product_display_name: '标准云服务器',
    product_spec_display: '2C4G',
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    type: 'cloud_server',
    effective_product_group_full_name: '计算 / 通用型',
    monthly_price: '88.00',
    primary_cycle: 'monthly',
    primary_price: { cycle: 'monthly', amount: '88.00' },
    lifecycle_status: 'active',
    status: 1,
    stock: 10,
    config_options: [],
  };
}

/** 打开分组树某行的操作菜单并点击指定项（限定桌面侧树，避免移动端重复渲染） */
async function clickGroupMenuItem(page: Page, groupName: string, item: string) {
  const tree = page.locator('.catalog-layout > .category-panel .category-tree');
  await tree.locator('.category-tree-row').filter({ hasText: groupName }).locator('.category-menu-trigger').click();
  await page.locator('.t-popup:visible .t-dropdown__item').filter({ hasText: item }).click();
}

test.describe('商品描述同步上游', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      window.localStorage.setItem('admin_token', 'description-sync-test-token');
      window.localStorage.setItem('admin_last_active_at', String(Date.now()));
    });

    await page.route('**/api/v2/admin/**', async (route) => {
      const url = new URL(route.request().url());
      const respond = (data: unknown) =>
        route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

      if (url.pathname.endsWith('/auth/info')) {
        return respond({ admin: { id: 1, username: 'desc-sync', nickname: 'desc-sync', permissions: ['*'] } });
      }
      if (url.pathname.endsWith('/product-types')) {
        return respond({
          list: [{ value: 'vps', label: '云服务器', usage_count: 1, product_type: 'cloud_server' }],
        });
      }
      if (url.pathname.endsWith('/product-groups/tree')) {
        return respond({ tree: groupTree(), total: 1 });
      }
      if (url.pathname.endsWith('/products')) {
        return respond({ list: [productSummary()], total: 1, page: 1, page_size: 20 });
      }
      if (url.pathname.endsWith('/description/sync-settings')) {
        return respond({ schedule_mode: 'daily', schedule_time: '03:45:00' });
      }
      if (url.pathname.endsWith('/products/101/description')) {
        return respond({ product_id: 101, description: '', source: 'empty', auto_html: '', auto_items: [] });
      }
      if (url.pathname.endsWith('/products/101')) {
        return respond({ product: productDetail() });
      }
      return respond({});
    });
  });

  test('分组菜单提供同步上游描述入口并调用分组同步接口', async ({ page }) => {
    const syncRequest = page.waitForRequest(
      (request) =>
        request.method() === 'POST' &&
        new URL(request.url()).pathname.endsWith('/v2/admin/product-groups/52/description/sync'),
    );

    await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });
    await clickGroupMenuItem(page, secondGroupName, '同步上游描述');

    const request = await syncRequest;
    expect(request.postDataJSON()).toMatchObject({ level: 2 });
  });

  test('商品行操作提供同步上游描述入口并调用批量同步接口', async ({ page }) => {
    const syncRequest = page.waitForRequest(
      (request) =>
        request.method() === 'POST' &&
        new URL(request.url()).pathname.endsWith('/v2/admin/products/description/sync-batch'),
    );

    await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });

    const row = page.locator('tr').filter({ hasText: '标准云服务器' }).first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: /更多/ }).first().click();
    await page.locator('.t-popup:visible .t-dropdown__item').filter({ hasText: '同步上游描述' }).click();

    const request = await syncRequest;
    expect(request.postDataJSON()).toMatchObject({ product_ids: [101] });
  });

  test('批量描述自动同步开关保存后调用商品级自动同步接口', async ({ page }) => {
    await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });

    const row = page.locator('tr').filter({ hasText: '标准云服务器' }).first();
    await expect(row).toBeVisible();
    await row.locator('.t-checkbox').first().click();

    const autoSyncRequest = page.waitForRequest(
      (request) =>
        request.method() === 'POST' &&
        new URL(request.url()).pathname.endsWith('/v2/admin/products/description/auto-sync'),
    );

    await page.getByRole('button', { name: '描述自动同步' }).click();
    const dialog = page.locator('.t-dialog:visible');
    await expect(dialog).toContainText('描述同步设置');
    await dialog.getByRole('button', { name: '确认' }).click();

    const request = await autoSyncRequest;
    expect(request.postDataJSON()).toMatchObject({ product_ids: [101], enabled: 0 });
  });

  test('商品编辑页可从上游同步描述', async ({ page }) => {
    const syncRequest = page.waitForRequest(
      (request) =>
        request.method() === 'POST' &&
        new URL(request.url()).pathname.endsWith('/v2/admin/products/101/description/sync'),
    );

    await page.goto('/admin/products/101/edit', { waitUntil: 'domcontentloaded' });

    // 描述分区默认收起，先切到「商品描述」分区
    await page.locator('.product-edit-nav-item').filter({ hasText: '商品描述' }).click();
    await page.getByRole('button', { name: '从上游同步' }).click();

    const request = await syncRequest;
    expect(request.postDataJSON()).toMatchObject({});
  });

  test('分组同步设置弹窗保存自动同步开关', async ({ page }) => {
    await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });

    const autoSyncRequest = page.waitForRequest(
      (request) =>
        request.method() === 'POST' &&
        new URL(request.url()).pathname.endsWith('/v2/admin/product-groups/52/description/auto-sync'),
    );

    await clickGroupMenuItem(page, secondGroupName, '同步设置');

    const dialog = page.locator('.t-dialog:visible');
    await expect(dialog).toContainText('描述同步设置');
    await expect(dialog).toContainText('自动同步');

    await dialog.getByRole('button', { name: '确认' }).click();

    const request = await autoSyncRequest;
    expect(request.postDataJSON()).toMatchObject({ level: 2, enabled: 0 });
  });
});
