import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 服务控制台「实例概览」信息密度对齐上游面板。
 *
 * 数据样本取自真机 service 3993（香港PCCW 4核8G300Mbps），字段含义：
 * - `connection.port = 0`：上游 host.port 为空，前端兜底显示 22（前提是有登录用户名）
 * - `upstream.os = "97"`：这是上游的系统 **ID**，真实系统名在 `specs` 里 `key === 'os'` 的 value
 * - `traffic.usage_percent = 57.7`：进度条百分比
 * - `actions.reinstall / password_reset`：操作系统行右侧两个入口的开关
 *
 * 本用例只验证只读展示：点击重装 / 重置只允许打开对话框，不得下发任何写操作（下面用请求记录断言）。
 */

const SERVICE_ID = 3993;

const BASE_DETAIL = {
  id: SERVICE_ID,
  name: '香港PCCW 4核8G300Mbps',
  status: 1,
  billing_cycle: 'monthly',
  billing_cycle_label: '月付',
  product_spec_display: '4核 8G 300Mbps',
  specs: [
    { key: 'os', label: '操作系统', value: 'Debian-12.1-x64' },
    { key: 'area', label: '区域', value: '香港PCCW数据中心' },
    { key: 'cpu', label: 'CPU', value: '4核' },
    { key: 'memory', label: '内存', value: '8G' },
    { key: 'system_disk_size', label: '系统盘', value: '30G' },
    { key: 'data_disk_size', label: '数据盘', value: '70G' },
    { key: 'bw', label: '带宽', value: '300Mbps峰值' },
    { key: 'ip_num', label: 'IP数量', value: '1个IPv4' },
  ],
  traffic: {
    usage: '577.02',
    limit: 1000,
    remaining: '422.98',
    usage_label: '577.02G',
    limit_label: '1000G',
    remaining_label: '422.98G',
    usage_percent: 57.7,
    limited: true,
    button_text: '购买流量包',
    purchase_enabled: true,
  },
  actions: {
    password_reset: true,
    reinstall: true,
    rescue: true,
    traffic_package: true,
    power: true,
  },
  upstream: { os: '97' },
};

interface MockHandle {
  /** 改连接信息（端口兜底的正反用例各用一次） */
  setConnection: (patch: Record<string, unknown>) => void;
  /** 记录所有非 GET 的 /services/** 请求，用来断言「没有触发真实操作」 */
  writes: string[];
}

async function mockClientApi(page: Page): Promise<MockHandle> {
  const writes: string[] = [];
  let connection: Record<string, unknown> = {
    port: 0,
    username: 'root',
    hostname: 'hk-pccw-3993.example.com',
    dedicated_ip: '203.0.113.10',
    internal_ip: '10.0.0.10',
    password: 'e2e-secret',
  };

  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'service-overview-e2e-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  // 只读断言：只要出现写请求就说明按钮点下去触发了真实操作
  page.on('request', (request) => {
    if (request.method() === 'GET') return;

    const { pathname } = new URL(request.url());
    if (pathname.startsWith(`/api/v2/client/services/${SERVICE_ID}`)) {
      writes.push(`${request.method()} ${pathname}`);
    }
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const ok = (data: unknown) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return ok({ id: 1000, name: 'e2e用户', nickname: 'e2e用户', email: 'e2e@example.com', cash_balance: '0.00' });
    }

    if (url.pathname.endsWith(`/services/${SERVICE_ID}`) && method === 'GET') {
      return ok({
        service: {
          ...BASE_DETAIL,
          connection: { ...connection },
        },
      });
    }

    if (url.pathname.endsWith(`/services/${SERVICE_ID}/connection`)) {
      // 真实响应是 { connection: {...} }（前端 v2ServiceBaseDetail 从 data.connection 取）
      return ok({ connection: { ...connection } });
    }

    // 重装对话框打开时只读系统列表，顺带证明「打开对话框 ≠ 执行重装」
    if (url.pathname.endsWith(`/services/${SERVICE_ID}/reinstallations/options`)) {
      return ok({
        request_timeout_ms: 60000,
        os: [{ group_name: 'Debian', items: [{ os_id: 12, name: 'Debian 12' }] }],
      });
    }

    return ok({});
  });

  return {
    setConnection: (patch) => {
      connection = { ...connection, ...patch };
    },
    writes,
  };
}

const osCellValue = (page: Page) => page.locator('.os-cell__value');
const portCell = (page: Page) => page.locator('.credential-grid .detail-cell').filter({ hasText: '端口' });

test.describe('服务控制台 · 实例概览（真机 3993 样本）', () => {
  test('流量：显示用量百分比进度条，保留「已用 / 总量」与购买入口', async ({ page }) => {
    await mockClientApi(page);
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    const trafficCell = page.locator('.traffic-cell');
    await expect(trafficCell).toBeVisible();

    // 文字部分保持原样
    await expect(trafficCell).toContainText('577.02G / 1000G');

    // 进度条来自 traffic.usage_percent
    await expect(trafficCell.locator('.t-progress')).toBeVisible();
    await expect(trafficCell).toContainText('流量已用 57.7%');

    // 「购买流量包」按钮仍在（点击走既有 TrafficPackageDialog，这里不点，避免打接口）
    await expect(page.getByRole('button', { name: '购买流量包' })).toBeVisible();
  });

  test('操作系统：显示 specs 里的真实系统名，而不是上游 os ID', async ({ page }) => {
    await mockClientApi(page);
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('.os-cell__name')).toHaveText('Debian-12.1-x64');
    // upstream.os = "97" 是 ID，不能出现在操作系统这一格里
    await expect(osCellValue(page)).not.toContainText('97');
  });

  test('操作系统行右侧的重装 / 重置只打开对话框，不下发任何写操作', async ({ page }) => {
    const mock = await mockClientApi(page);
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    const osCell = page.locator('.os-cell');
    const reinstallButton = osCell.getByRole('button', { name: '重装系统' });
    const resetButton = osCell.getByRole('button', { name: '重置密码' });

    await expect(reinstallButton).toBeVisible();
    await expect(resetButton).toBeVisible();

    await reinstallButton.click();
    const reinstallDialog = page.locator('.t-dialog', { hasText: '重装系统' });
    await expect(reinstallDialog).toBeVisible();
    await reinstallDialog.getByRole('button', { name: '取消' }).click();
    await expect(reinstallDialog).toBeHidden();

    await resetButton.click();
    const resetDialog = page.locator('.t-dialog', { hasText: '重置实例密码' });
    await expect(resetDialog).toBeVisible();
    await resetDialog.getByRole('button', { name: '取消' }).click();
    await expect(resetDialog).toBeHidden();

    // 全程只有读请求：没有重装提交、没有密码重置、没有开关机
    expect(mock.writes).toEqual([]);
  });

  test('端口：connection.port 为 0 且有用户名时兜底显示 22，没有用户名时仍为 --', async ({ page }) => {
    const mock = await mockClientApi(page);
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    await expect(portCell(page)).toContainText('22');

    // 反例：上游既没端口也没有登录用户名 → 不猜，保持 --
    mock.setConnection({ username: '' });
    await page.reload({ waitUntil: 'domcontentloaded' });

    await expect(portCell(page)).toContainText('--');
  });
});
