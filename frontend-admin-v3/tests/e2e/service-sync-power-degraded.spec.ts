import { expect, test } from '@playwright/test';

/**
 * 管理端实例「同步信息」与电源状态解耦回归。
 *
 * 线上故障：实例 3783（用户 1086）点「同步信息」直接返回
 * `{"code":42200,"message":"电源状态失败，请稍后重试"}`，整次同步被中断，
 * 到期时间、商品信息、IP 都没刷新。
 *
 * 期望：电源状态读不到时只提示「实例信息已同步，电源状态暂时不可用」，
 * 但仍要重新拉取实例详情（refresh=1）；电源状态正常时照旧提示同步成功。
 */

const USER_ID = 1086;
const SERVICE_ID = 3783;
const DETAIL_PATH = `/v2/admin/users/${USER_ID}/services/${SERVICE_ID}`;

function serviceDetail(): Record<string, unknown> {
  return {
    id: SERVICE_ID,
    user_id: USER_ID,
    name: '美国轻量区 2核2G',
    custom_service_name: '美国轻量区 2核2G',
    combined_display_name: '美国轻量区 2核2G',
    product_display_name: '美国轻量区 2核2G',
    product: {
      id: 1750,
      name: '美国轻量区 2核2G',
      type: 'vps',
      type_label: '云服务器',
      catalog_type: 'vps',
    },
    machine_category: { key: 'vps', label: '云服务器' },
    console_template: 'default',
    status: 2,
    status_label: '已到期',
    billing_cycle: 'monthly',
    billing_cycle_label: '月付',
    amount: '9.90',
    expires_at: '2026-10-20 19:17:41',
    connection: { hostname: 'us-lite-01', dedicated_ip: '203.0.113.10', internal_ip: '', port: 22 },
    upstream: { provider_key: 'zjmf_finance', host_id: 168, status: 'active' },
    actions: { refresh: true, module_status: true, power: true },
  };
}

function mockConsoleApi(
  page: import('@playwright/test').Page,
  moduleStatus: { status: number; body: Record<string, unknown> },
  refreshCalls: { count: number },
) {
  return page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname.replace(/^\/api/, '');
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'sync-test', nickname: 'sync-test', permissions: ['*'] } });
    }

    if (pathname === `${DETAIL_PATH}/module-status`) {
      return route.fulfill({
        status: moduleStatus.status,
        contentType: 'application/json',
        body: JSON.stringify(moduleStatus.body),
      });
    }

    if (pathname === DETAIL_PATH) {
      if (url.searchParams.get('refresh') === '1') {
        refreshCalls.count += 1;
      }

      return respond(serviceDetail());
    }

    if (pathname === `${DETAIL_PATH}/remote-status`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/capabilities`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/tickets`) return respond({ ticket: '' });

    return respond({});
  });
}

async function seedAdminSession(page: import('@playwright/test').Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'service-sync-power-degraded-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

test('电源状态失败时仍刷新实例详情并提示部分成功', async ({ page }) => {
  const refreshCalls = { count: 0 };

  await seedAdminSession(page);
  await mockConsoleApi(
    page,
    {
      status: 200,
      body: {
        code: 0,
        data: {
          type: 'host',
          type_label: '电源状态',
          status: 'unavailable',
          description: '电源状态失败，请稍后重试',
          available: false,
          error: '电源状态失败，请稍后重试',
        },
      },
    },
    refreshCalls,
  );

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: '同步信息' }).first().click();

  await expect(page.locator('.t-message').filter({ hasText: '实例信息已同步，电源状态暂时不可用' })).toBeVisible();
  await expect(page.locator('.t-message--error')).toHaveCount(0);
  await expect.poll(() => refreshCalls.count, { message: '电源状态失败后仍必须重新拉取实例详情' }).toBeGreaterThan(0);
});

test('电源状态正常时提示同步成功并刷新详情', async ({ page }) => {
  const refreshCalls = { count: 0 };

  await seedAdminSession(page);
  await mockConsoleApi(
    page,
    {
      status: 200,
      body: {
        code: 0,
        data: { type: 'host', type_label: '电源状态', status: 'on', description: '运行中', available: true },
      },
    },
    refreshCalls,
  );

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: '同步信息' }).first().click();

  await expect(page.locator('.t-message').filter({ hasText: '实例状态已同步' })).toBeVisible();
  await expect.poll(() => refreshCalls.count).toBeGreaterThan(0);
});

test('旧版后端直接抛 42200 时也不阻断详情刷新', async ({ page }) => {
  const refreshCalls = { count: 0 };

  await seedAdminSession(page);
  await mockConsoleApi(
    page,
    {
      status: 422,
      body: { code: 42200, message: '电源状态失败，请稍后重试' },
    },
    refreshCalls,
  );

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: '同步信息' }).first().click();

  await expect(page.locator('.t-message').filter({ hasText: '实例信息已同步，电源状态暂时不可用' })).toBeVisible();
  await expect.poll(() => refreshCalls.count, { message: '旧版后端报错时详情刷新也不能被跳过' }).toBeGreaterThan(0);
});
