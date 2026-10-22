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
        return respond({ list: [], total: 0, page: 1, page_size: 20 });
      }
      if (url.pathname.endsWith('/description/sync-settings')) {
        return respond({ schedule_mode: 'daily', schedule_time: '03:45:00' });
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
