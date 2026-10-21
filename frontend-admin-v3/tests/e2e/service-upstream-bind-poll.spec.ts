import { expect, test } from '@playwright/test';

/**
 * 管理端「切换上游绑定」后的补齐轮询回归。
 *
 * 切换上游绑定现在只写本地库（请求 0.4s 返回），新上游主机的 IP / 系统 / 连接信息
 * 由后台同步任务回填，而队列默认每分钟消费一次——不轮询的话，页面会一直空着，
 * 只能靠管理员手动刷新页面才看得到新数据。
 *
 * 期望：
 * 1. 保存成功后前端开始轮询，直到上游数据回填为止；
 * 2. 轮询只打本地详情接口（命中后端 30s 缓存），不许打管理端那个强制回源的
 *    remote-status 接口——否则每拍都回源一次上游，几分钟能打几十次。
 */

const USER_ID = 1086;
const SERVICE_ID = 3783;
const DETAIL_PATH = `/v2/admin/users/${USER_ID}/services/${SERVICE_ID}`;

interface ServiceDetailOverrides {
  upstream?: Record<string, unknown>;
  syncChanged?: boolean;
}

function serviceDetail(overrides: ServiceDetailOverrides = {}): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    id: SERVICE_ID,
    user_id: USER_ID,
    name: '30 vCPU 16G',
    product: { id: 2513, name: '30 vCPU 16G', type: 'cloud', type_label: '云服务器' },
    machine_category: { key: 'cloud', label: '云服务器' },
    console_template: 'compute',
    status: 1,
    status_label: '已开通',
    billing_cycle: 'monthly',
    amount: '99.00',
    connection: { hostname: '', dedicated_ip: '', internal_ip: '', port: 0 },
    upstream: {
      provider_key: 'zjmf_finance_api',
      host_id: 10308,
      status: '',
      os: '',
      dedicated_ip: '',
      ...(overrides.upstream || {}),
    },
    actions: { refresh: true, module_status: true, power: true },
  };

  if (overrides.syncChanged) {
    payload._sync = { changed: true, changed_at: '2026-10-21 10:00:00' };
  }

  return payload;
}

test('切换上游绑定后轮询本地详情直到上游数据回填', async ({ page }) => {
  const detailCalls = { count: 0 };
  const remoteStatusCalls = { count: 0 };
  const metaCalls = { count: 0 };

  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'bind-poll-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname.replace(/^\/api/, '');
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'bind-poll', nickname: 'bind-poll', permissions: ['*'] } });
    }

    if (pathname === '/v2/admin/suppliers') {
      return respond({ list: [{ id: 15, name: '二五云上游', provider_label: 'zjmf_finance_api' }] });
    }

    if (pathname === `${DETAIL_PATH}/meta` && route.request().method() === 'PUT') {
      metaCalls.count += 1;
      return respond(serviceDetail());
    }

    if (pathname === `${DETAIL_PATH}/remote-status`) {
      remoteStatusCalls.count += 1;
      return respond({});
    }

    if (pathname === DETAIL_PATH) {
      detailCalls.count += 1;
      // 前两次还没回填（首屏 + 保存后立即刷新），第三次起后台同步任务已落地
      if (detailCalls.count < 3) return respond(serviceDetail());
      return respond(
        serviceDetail({
          upstream: { status: 'Active', os: 'Ubuntu-24.04-LTS-x64', dedicated_ip: '203.0.113.10' },
          syncChanged: true,
        }),
      );
    }

    if (pathname === `${DETAIL_PATH}/connection`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/capabilities`) return respond({});
    if (pathname === `${DETAIL_PATH}/module-status`) {
      return respond({ type: 'host', type_label: '电源状态', status: 'on', description: '运行中', available: true });
    }

    return respond({});
  });

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  const dialog = page.locator('.t-dialog:visible').filter({ hasText: '上游绑定' });
  await page.locator('button', { hasText: '上游绑定' }).first().click();
  await expect(dialog).toBeVisible();

  await dialog.locator('.t-select').first().click();
  await page.locator('.t-select-option', { hasText: '二五云上游' }).first().click();

  const hostIdInput = dialog.locator('.t-input-number input').first();
  await hostIdInput.pressSequentially('10308');

  await dialog.getByRole('button', { name: '保存' }).click();

  await expect.poll(() => metaCalls.count, { message: '上游绑定必须提交到后端' }).toBe(1);
  // 保存成功后必须继续轮询，等上游数据回填
  await expect
    .poll(() => detailCalls.count, { message: '保存成功后必须轮询本地详情等上游数据回填' })
    .toBeGreaterThanOrEqual(3);
  await expect(page.locator('.t-message').filter({ hasText: '上游信息已同步' })).toBeVisible();
  // 轮询不许打管理端那个强制回源的 remote-status 接口（首屏那一次除外）
  expect(remoteStatusCalls.count).toBe(1);
});
