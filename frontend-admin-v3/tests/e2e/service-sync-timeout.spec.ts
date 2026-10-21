import { expect, test } from '@playwright/test';

/**
 * 管理端实例「同步信息」在慢上游下的回归。
 *
 * 线上问题：上游面板接口本身就慢（module-status 实测约 5.4s、详情 refresh=1 实测约 26.2s），
 * 管理端 axios 默认超时 10s 会提前掐断请求，于是界面弹红色失败提示，
 * 其实后台已经开始同步（nginx 侧记录 499）。用户是手机端网络，更容易触发。
 *
 * 期望：
 * 1. module-status 读不到时，详情刷新仍然要发出去；
 * 2. 详情刷新等不到结果时给中性提示（同步已提交、后台仍在同步），不再报红色失败；
 * 3. 请求进行中「同步信息」按钮保持 loading。
 */

const USER_ID = 1086;
const SERVICE_ID = 3783;
const DETAIL_PATH = `/v2/admin/users/${USER_ID}/services/${SERVICE_ID}`;
const SYNC_PENDING_TEXT = '同步请求已提交，后台正在同步，请稍后刷新查看';

interface StubResponse {
  status: number;
  body: Record<string, unknown>;
}

interface MockOptions {
  /** 电源状态（module-status）的返回 */
  moduleStatus: StubResponse;
  /** 详情刷新（refresh=1）的返回；不传表示正常返回 */
  refresh?: StubResponse;
  /** 详情刷新命中时的钩子，可用于挂起请求模拟上游迟迟不返回 */
  onRefresh?: () => void | Promise<void>;
}

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

function moduleStatusOk(): StubResponse {
  return {
    status: 200,
    body: {
      code: 0,
      data: { type: 'host', type_label: '电源状态', status: 'on', description: '运行中', available: true },
    },
  };
}

async function seedAdminSession(page: import('@playwright/test').Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'service-sync-timeout-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockConsoleApi(
  page: import('@playwright/test').Page,
  options: MockOptions,
  refreshCalls: { count: number },
) {
  return page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname.replace(/^\/api/, '');
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'sync-timeout', nickname: 'sync-timeout', permissions: ['*'] } });
    }

    if (pathname === `${DETAIL_PATH}/module-status`) {
      return route.fulfill({
        status: options.moduleStatus.status,
        contentType: 'application/json',
        body: JSON.stringify(options.moduleStatus.body),
      });
    }

    if (pathname === DETAIL_PATH) {
      if (url.searchParams.get('refresh') === '1') {
        refreshCalls.count += 1;
        // 需要时可以挂起这次请求，模拟上游迟迟不返回
        await options.onRefresh?.();
        if (options.refresh) {
          return route.fulfill({
            status: options.refresh.status,
            contentType: 'application/json',
            body: JSON.stringify(options.refresh.body),
          });
        }
      }

      return respond(serviceDetail());
    }

    if (pathname === `${DETAIL_PATH}/remote-status`) return respond({});
    if (pathname === `${DETAIL_PATH}/connection`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/capabilities`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/tickets`) return respond({ ticket: '' });

    return respond({});
  });
}

/** 同步按钮：按文本定位，避免 loading 态下按钮可访问名变化导致定位失败 */
function syncButton(page: import('@playwright/test').Page) {
  return page.locator('button', { hasText: '同步信息' }).first();
}

test('电源状态失败时详情刷新仍然发出', async ({ page }) => {
  const refreshCalls = { count: 0 };

  await seedAdminSession(page);
  await mockConsoleApi(
    page,
    {
      moduleStatus: {
        status: 422,
        body: { code: 42200, message: '电源状态失败，请稍后重试' },
      },
    },
    refreshCalls,
  );

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  await syncButton(page).click();

  await expect.poll(() => refreshCalls.count, { message: '电源状态失败后仍必须重新拉取实例详情' }).toBeGreaterThan(0);
});

test('详情刷新等不到结果时给中性提示而不是失败', async ({ page }) => {
  const refreshCalls = { count: 0 };

  await seedAdminSession(page);
  await mockConsoleApi(
    page,
    {
      moduleStatus: moduleStatusOk(),
      // 后端等待上游超时（网关 504）：同步请求已提交，后台仍在同步
      refresh: { status: 504, body: { code: 50400, message: '上游响应超时' } },
    },
    refreshCalls,
  );

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  await syncButton(page).click();

  await expect(page.locator('.t-message').filter({ hasText: SYNC_PENDING_TEXT })).toBeVisible();
  // 不能出现红色失败提示
  await expect(page.locator('.t-message.t-is-error')).toHaveCount(0);
  await expect.poll(() => refreshCalls.count).toBeGreaterThan(0);
});

test('同步请求进行中按钮保持 loading', async ({ page }) => {
  const refreshCalls = { count: 0 };
  let releaseRefresh: () => void = () => {};
  // 详情刷新挂起，模拟上游迟迟不返回
  const gate = new Promise<void>((resolve) => {
    releaseRefresh = () => resolve();
  });

  await seedAdminSession(page);
  await mockConsoleApi(page, { moduleStatus: moduleStatusOk(), onRefresh: () => gate }, refreshCalls);

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  const button = syncButton(page);
  await button.click();

  await expect(button).toHaveClass(/t-is-loading/);

  releaseRefresh();

  await expect(page.locator('.t-message').filter({ hasText: '实例状态已同步' })).toBeVisible();
  await expect(button).not.toHaveClass(/t-is-loading/);
});
