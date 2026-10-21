import { expect, test } from '@playwright/test';

/**
 * 管理端「切换上游绑定」后的补齐轮询回归。
 *
 * 切换上游绑定现在只写本地库（请求 0.4s 返回），新上游主机的 IP / 系统 / 连接信息
 * 由后台同步任务回填，而队列默认每分钟消费一次——不轮询的话，页面会一直空着，
 * 只能靠管理员手动刷新页面才看得到新数据。
 *
 * 期望：保存成功后前端开始轮询 remote-status，直到上游数据回填为止。
 */

const USER_ID = 1086;
const SERVICE_ID = 3783;
const DETAIL_PATH = `/v2/admin/users/${USER_ID}/services/${SERVICE_ID}`;

interface ServiceDetailOverrides {
  upstream?: Record<string, unknown>;
}

function serviceDetail(overrides: ServiceDetailOverrides = {}): Record<string, unknown> {
  return {
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
      status: 'Active',
      dedicated_ip: '',
      ...(overrides.upstream || {}),
    },
    actions: { refresh: true, module_status: true, power: true },
  };
}

test('切换上游绑定后轮询远端状态直到上游数据回填', async ({ page }) => {
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
      // 第一次还没回填，第二次才带上上游 IP（模拟后台同步任务落地）
      if (remoteStatusCalls.count < 2) return respond({});
      return respond({ upstream: { provider_key: 'zjmf_finance_api', host_id: 10308, dedicated_ip: '203.0.113.10' } });
    }

    if (pathname === DETAIL_PATH) return respond(serviceDetail());
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
  // 保存后必须继续轮询远端状态，等后台同步把上游数据回填
  await expect
    .poll(() => remoteStatusCalls.count, { message: '保存成功后必须轮询 remote-status 等上游数据回填' })
    .toBeGreaterThan(1);
  await expect(page.locator('.t-message').filter({ hasText: '实例状态已刷新' })).toBeVisible();
});
