import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

import type { ProductGroupV2Record } from '../../src/api/product';

const thirdGroupName = '枣庄BGP I9-149 游戏优化';

/**
 * 商品只能挂在三级分组下，所以这里给出一条完整的 一级 → 二级 → 三级 链。
 * 详情里的 classification 必须同样指向 53，否则编辑页会用 productGroupOptionKey()
 * 生成 `3:53` 去匹配级联选项，匹配不到就拦在「请选择三级分类」上，测不到保存。
 */
function groupTree(): ProductGroupV2Record[] {
  return [
    {
      id: 50,
      name: '游戏云',
      level: 1,
      product_type: 'cloud_server',
      first_product_group_id: 50,
      first_product_group_code: 'game',
      effective_product_group_id: 50,
      effective_product_group_level: 1,
      status: 1,
      children: [
        {
          id: 52,
          name: 'NAT 游戏云 EGS',
          parent_id: 50,
          level: 2,
          product_type: 'cloud_server',
          first_product_group_id: 50,
          first_product_group_code: 'game',
          second_product_group_id: 52,
          effective_product_group_id: 52,
          effective_product_group_level: 2,
          status: 1,
          children: [
            {
              id: 53,
              name: thirdGroupName,
              parent_id: 52,
              level: 3,
              product_type: 'cloud_server',
              first_product_group_id: 50,
              first_product_group_code: 'game',
              second_product_group_id: 52,
              third_product_group_id: 53,
              effective_product_group_id: 53,
              effective_product_group_level: 3,
              status: 1,
              children: [],
            } as ProductGroupV2Record,
          ],
        } as ProductGroupV2Record,
      ],
    } as ProductGroupV2Record,
  ];
}

/** 商品详情（编辑页用）是嵌套结构，控制台面板在 configuration 下，顶层没有这个键 */
function productDetail(consoleTemplate: string): Record<string, unknown> {
  return {
    id: 101,
    display: {
      display_name: '枣庄BGP I9-149',
      product_display_name: '枣庄BGP I9-149',
      product_spec_display: 'I9-14900K / 32G',
      combined_display_name: '枣庄BGP I9-149 I9-14900K / 32G',
    },
    classification: {
      product_type: 'cloud_server',
      product_type_label: '游戏云',
      first_product_group_id: 50,
      first_product_group_code: 'game',
      second_product_group_id: 52,
      third_product_group_id: 53,
      effective_product_group_id: 53,
      effective_product_group_level: 3,
    },
    pricing: { items: { monthly: 88 }, primary_price: { cycle: 'monthly', amount: '88.00' } },
    configuration: { console_template: consoleTemplate, config_options: [] },
    purchase_requirements: { require_verification: false, require_phone: false },
    provisioning: { stock: -1, auto_setup: 1 },
    upstream_binding: { supplier_id: 3, upstream_product_id: 300 },
    statistics: { orders_count: 0, services_count: 0, active_services_count: 0 },
    lifecycle: { status: 1, lifecycle_status: 'active', deleted_at: null, sort_order: 1 },
    timestamps: { created_at: '2026-06-06 10:00:00', updated_at: '2026-06-06 10:00:00' },
  };
}

function productSummary(): Record<string, unknown> {
  return {
    id: 101,
    name: '枣庄BGP I9-149',
    display_name: '枣庄BGP I9-149',
    product_display_name: '枣庄BGP I9-149',
    product_spec_display: 'I9-14900K / 32G',
    product_type: 'cloud_server',
    product_type_label: '游戏云',
    type: 'cloud_server',
    effective_product_group_full_name: `游戏云 / NAT 游戏云 EGS / ${thirdGroupName}`,
    monthly_price: '88.00',
    primary_cycle: 'monthly',
    primary_price: { cycle: 'monthly', amount: '88.00' },
    lifecycle_status: 'active',
    status: 1,
    stock: 10,
    config_options: [],
  };
}

async function mockAdminApi(page: Page, consoleTemplate: string) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'console-template-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'console-panel', nickname: 'console-panel', permissions: ['*'] } });
    }
    if (url.pathname.endsWith('/product-types')) {
      return respond({
        list: [{ value: 'game', label: '游戏云', usage_count: 1, product_type: 'cloud_server' }],
      });
    }
    if (url.pathname.endsWith('/product-groups/tree')) {
      return respond({ tree: groupTree(), total: 1 });
    }
    if (url.pathname.endsWith('/products')) {
      return respond({ list: [productSummary()], total: 1, page: 1, page_size: 20 });
    }
    if (url.pathname.endsWith('/products/101/description')) {
      return respond({ product_id: 101, description: '', source: 'empty', auto_html: '', auto_items: [] });
    }
    if (url.pathname.endsWith('/products/101')) {
      return respond({ product: productDetail(consoleTemplate) });
    }
    return respond({});
  });
}

test.describe('商品控制台面板回填与提交', () => {
  test('详情里的端口映射控制台会回填到「控制台面板」下拉', async ({ page }) => {
    await mockAdminApi(page, 'port_mapping');
    await page.goto('/admin/products/101/edit', { waitUntil: 'domcontentloaded' });

    const field = page.locator('.product-edit-console-template-field').first();
    await expect(field).toBeVisible();

    // 回归点：normalizeV2ProductDetail() 以前没把 configuration.console_template
    // 平铺到 ProductRecord，这里会读到 undefined 并归一成 compute，下拉显示「通用计算控制台」。
    await expect(field.locator('input').first()).toHaveValue('端口映射控制台');
  });

  test('保存时原样提交控制台面板，不被归一成通用计算', async ({ page }) => {
    await mockAdminApi(page, 'port_mapping');

    const saveRequest = page.waitForRequest(
      (request) => request.method() === 'PUT' && new URL(request.url()).pathname.endsWith('/v2/admin/products/101'),
    );

    await page.goto('/admin/products/101/edit', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: '保存更改' }).click();

    const request = await saveRequest;
    expect(request.postDataJSON()).toMatchObject({ console_template: 'port_mapping' });
  });

  test('批量设置控制台类型没选就确认时，不发出任何修改请求', async ({ page }) => {
    await mockAdminApi(page, 'port_mapping');

    const writeRequests: string[] = [];
    page.on('request', (request) => {
      if (request.method() !== 'GET' && request.url().includes('/v2/admin/product')) {
        writeRequests.push(`${request.method()} ${request.url()}`);
      }
    });

    await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });

    const row = page.locator('tr').filter({ hasText: '枣庄BGP I9-149' }).first();
    await expect(row).toBeVisible();
    await row.locator('.t-checkbox').first().click();

    await page.getByRole('button', { name: '控制台类型' }).click();
    const dialog = page.locator('.t-dialog:visible');
    await expect(dialog).toContainText('设置控制台类型');

    // 不许有默认选中项：以前默认 compute，管理员点开看一眼再确认就会把
    // 选中商品里已经设成 port_mapping 的一起重置回通用计算。
    await dialog.getByRole('button', { name: '确认' }).click();

    await expect(page.getByText('请先选择要设置的控制台类型')).toBeVisible();
    expect(writeRequests).toEqual([]);
    await expect(dialog).toBeVisible();
  });
});
