import { expect, test } from '@playwright/test';

import type { ProductGroupV2Record } from '../../src/api/product';

const cloudServer = '云服务器';
const gameCloud = '游戏云';

function categoryTree(): ProductGroupV2Record[] {
  return [
    {
      id: 52,
      name: cloudServer,
      level: 1,
      product_type: 'cloud_server',
      first_product_group_id: 52,
      first_product_group_code: 'vps',
      effective_product_group_id: 52,
      effective_product_group_level: 1,
      status: 1,
      children: [
        {
          id: 31,
          name: cloudServer,
          parent_id: 52,
          level: 2,
          product_type: 'cloud_server',
          first_product_group_id: 52,
          first_product_group_code: 'vps',
          second_product_group_id: 31,
          effective_product_group_id: 31,
          effective_product_group_level: 2,
          status: 1,
          children: [],
        },
      ],
    },
    {
      id: 56,
      name: gameCloud,
      level: 1,
      product_type: 'game_cloud',
      first_product_group_id: 56,
      first_product_group_code: 'dedicated',
      effective_product_group_id: 56,
      effective_product_group_level: 1,
      status: 1,
      children: [
        {
          id: 41,
          name: 'Gold',
          parent_id: 56,
          level: 2,
          product_type: 'game_cloud',
          first_product_group_id: 56,
          first_product_group_code: 'dedicated',
          second_product_group_id: 41,
          effective_product_group_id: 41,
          effective_product_group_level: 2,
          status: 1,
          children: [
            {
              id: 42,
              name: '西安',
              parent_id: 41,
              level: 3,
              product_type: 'game_cloud',
              first_product_group_id: 56,
              first_product_group_code: 'dedicated',
              second_product_group_id: 41,
              third_product_group_id: 42,
              effective_product_group_id: 42,
              effective_product_group_level: 3,
              status: 1,
              children: [],
            },
          ],
        },
        {
          id: 43,
          name: 'Platinum',
          parent_id: 56,
          level: 2,
          product_type: 'game_cloud',
          first_product_group_id: 56,
          first_product_group_code: 'dedicated',
          second_product_group_id: 43,
          effective_product_group_id: 43,
          effective_product_group_level: 2,
          status: 1,
          children: [],
        },
      ],
    },
  ];
}

test('scopes the category tree to the selected product type', async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'product-catalog-scope-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'scope-test', nickname: 'scope-test', permissions: ['*'] } });
    }

    if (url.pathname.endsWith('/product-types')) {
      return respond({
        list: [
          { value: 'vps', label: cloudServer, usage_count: 106, product_type: 'cloud_server' },
          { value: 'dedicated', label: gameCloud, usage_count: 10, product_type: 'game_cloud' },
        ],
      });
    }

    if (url.pathname.endsWith('/product-groups/tree')) {
      return respond({ tree: categoryTree(), total: 2 });
    }

    if (url.pathname.endsWith('/products')) {
      return respond({ list: [], total: 0, page: 1, page_size: 20 });
    }

    return respond({});
  });

  await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });
  const isNarrowViewport = (page.viewportSize()?.width || 1440) <= 768;
  if (isNarrowViewport) {
    await page.getByRole('button', { name: '筛选分类' }).click();
  }

  const categoryTreeElement = isNarrowViewport
    ? page.locator('.mobile-category-tree')
    : page.locator('.catalog-layout > .category-panel .category-tree');
  await expect(categoryTreeElement).toContainText(cloudServer);

  const categoryRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return (
      url.pathname.endsWith('/product-groups/tree') && url.searchParams.get('first_product_group_code') === 'dedicated'
    );
  });
  const typeSelector = isNarrowViewport ? '.mobile-type-sidebar' : '.type-strip';
  await page
    .locator(typeSelector)
    .getByRole('button', { name: new RegExp(gameCloud) })
    .click();
  await categoryRequest;

  await expect(categoryTreeElement).toContainText('Gold');
  await expect(categoryTreeElement).toContainText('Platinum');
  await expect(categoryTreeElement).not.toContainText(cloudServer);
});
