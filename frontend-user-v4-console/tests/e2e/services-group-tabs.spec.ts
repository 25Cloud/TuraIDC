import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 控制台「服务实例」按一级分组切分。
 *
 * 背景：实例列表此前只有一个不起眼的「服务分类」下拉，一级分组（云服务器 / 游戏云 /
 * 虚拟主机……）混在一起看不出自己有哪些产品。现在桌面端顶部是一条一级分组 tab，
 * 第一项是「全部产品」，每项带该分组的实例数；窄屏仍用同源的下拉（改的是同一个
 * filters.catalog_type）。切换后地址要带上 catalog_type，刷新与分享停在同一分组。
 */

const USER_INFO = {
  id: 1,
  name: 'e2e用户',
  nickname: 'e2e用户',
  email: 'e2e@example.com',
  phone: '13800000000',
  cash_balance: '0.00',
  is_verified: 1,
};

const CATALOG_TYPES = [
  { label: '云服务器', value: 'vps', icon: 'cloud', count: 2 },
  { label: '游戏云', value: 'dedicated', icon: 'game', count: 1 },
];

const SERVICES: Record<string, Record<string, unknown>> = {
  vps: [
    {
      id: 101,
      name: '香港云服务器 2C4G',
      custom_service_name: '',
      status: 1,
      billing_cycle: 'monthly',
      expires_at: '2027-01-01 00:00:00',
      auto_renew: 1,
      product: { type_label: '云服务器', group_name: '香港', console_template: 'default' },
      upstream: { dedicated_ip: '1.1.1.1', os: 'ubuntu 22.04' },
      specs: [],
      remark: '',
    },
    {
      id: 102,
      name: '上海云服务器 4C8G',
      custom_service_name: '',
      status: 1,
      billing_cycle: 'monthly',
      expires_at: '2027-02-01 00:00:00',
      auto_renew: 0,
      product: { type_label: '云服务器', group_name: '上海', console_template: 'default' },
      upstream: { dedicated_ip: '1.1.1.2', os: 'debian 12' },
      specs: [],
      remark: '',
    },
  ],
  dedicated: [
    {
      id: 201,
      name: 'NAT 游戏云 EGS',
      custom_service_name: '',
      status: 1,
      billing_cycle: 'monthly',
      expires_at: '2027-03-01 00:00:00',
      auto_renew: 0,
      product: { type_label: '游戏云', group_name: 'NAT 游戏云', console_template: 'default' },
      upstream: { dedicated_ip: '2.2.2.2', os: 'ubuntu 20.04' },
      specs: [],
      remark: '',
    },
  ],
};

const listRequests: URLSearchParams[] = [];

async function mockServicesApi(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'services-group-tabs-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond(USER_INFO);
    }

    if (url.pathname.endsWith('/services/grouped-overview')) {
      return respond({
        total: 3,
        category_total: 2,
        list: [],
        catalog_types: CATALOG_TYPES,
      });
    }

    if (url.pathname.endsWith('/services')) {
      listRequests.push(url.searchParams);
      const catalogType = url.searchParams.get('catalog_type') || '';
      const list = catalogType ? SERVICES[catalogType] || [] : [...(SERVICES.vps || []), ...(SERVICES.dedicated || [])];
      return respond({ list, total: list.length, page: 1, page_size: 10 });
    }

    return respond({});
  });
}

test.describe('服务实例按一级分组切分', () => {
  test('桌面端一级分组 tab 带实例数，切换后地址与列表同步', async ({ page }) => {
    await mockServicesApi(page);
    await page.goto('/client/services');

    const tabs = page.locator('.service-group-tabs');
    await expect(tabs).toBeVisible();

    // 第一个是「全部产品」，后面按一级分组排，每项带数量
    const tabItems = tabs.locator('.service-group-tab');
    await expect(tabItems).toHaveCount(3);
    await expect(tabItems.nth(0)).toContainText('全部产品');
    await expect(tabItems.nth(0)).toContainText('3');
    await expect(tabItems.nth(1)).toContainText('云服务器');
    await expect(tabItems.nth(1)).toContainText('2');
    await expect(tabItems.nth(2)).toContainText('游戏云');
    await expect(tabItems.nth(2)).toContainText('1');
    await expect(tabItems.nth(0)).toHaveClass(/is-active/);

    // 切到「云服务器」：地址带上 catalog_type，请求带上，列表只剩这一组
    await tabItems.nth(1).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('catalog_type')).toBe('vps');
    await expect.poll(() => listRequests[listRequests.length - 1].get('catalog_type')).toBe('vps');
    await expect(page.locator('.service-row-card')).toHaveCount(2);
    await expect(page.locator('.service-row-card').filter({ hasText: '香港云服务器 2C4G' })).toHaveCount(1);
    await expect(page.locator('.service-row-card').filter({ hasText: 'NAT 游戏云 EGS' })).toHaveCount(0);
    await expect(tabItems.nth(1)).toHaveClass(/is-active/);

    // 切到「游戏云」
    await tabItems.nth(2).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('catalog_type')).toBe('dedicated');
    await expect(page.locator('.service-row-card')).toHaveCount(1);

    // 回到「全部产品」：地址清掉 catalog_type，列表恢复全部
    await tabItems.nth(0).click();
    await expect.poll(() => new URL(page.url()).searchParams.has('catalog_type')).toBe(false);
    await expect(page.locator('.service-row-card')).toHaveCount(3);
  });

  test('带 catalog_type 进入时选中对应 tab', async ({ page }) => {
    await mockServicesApi(page);
    await page.goto('/client/services?catalog_type=dedicated');

    const tabItems = page.locator('.service-group-tabs .service-group-tab');
    await expect(tabItems.nth(2)).toHaveClass(/is-active/);
    await expect(page.locator('.service-row-card')).toHaveCount(1);
  });

  test('窄屏用下拉而不是 tab，两者同源', async ({ page }) => {
    await page.setViewportSize({ width: 480, height: 900 });
    await mockServicesApi(page);
    await page.goto('/client/services');

    await expect(page.locator('.service-group-tabs')).toBeHidden();
    await expect(page.locator('.service-filter-bar__catalog')).toBeVisible();
  });
});
