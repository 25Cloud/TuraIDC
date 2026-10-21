import { readFileSync } from 'node:fs';

import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

const officialUrl = 'https://fixture-vnc.raincs.cn/login?node=demo&data=abc%2Bdef%3D';

async function mockConsole(page: Page, external: boolean) {
  await page.addInitScript(() => {
    localStorage.setItem('client_token', 'fixture-console-token');
    localStorage.setItem('client_last_active_at', String(Date.now()));
  });
  await page.route('**/api/v2/client/**', async (route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) => route.fulfill({ json: { code: 0, data } });
    if (url.pathname.endsWith('/auth/info'))
      return respond({ id: 1, name: '测试用户', is_verified: 1, cash_balance: '0.00' });
    if (url.pathname.endsWith('/services/42')) {
      return respond({
        service: {
          id: 42,
          name: '测试云服务器',
          status: 1,
          console_template: 'default',
          machine_category: { key: 'cloud', label: '云服务器' },
          product: { id: 10, name: '测试云服务器', type: 'vps' },
          upstream: { provider_key: external ? 'rainyun_rcs' : 'zjmf', host_id: 99 },
          connection: {},
          actions: { vnc: true, reinstall: true },
        },
      });
    }
    if (url.pathname.endsWith('/console/capabilities')) return respond({ supported: true, areas: [] });
    if (url.pathname.endsWith('/vnc')) {
      return respond(external ? { url: officialUrl, launch_mode: 'external' } : { url: '/vnc/vnc.html?token=fixture' });
    }
    return respond({});
  });
  await page
    .context()
    .route('https://fixture-vnc.raincs.cn/**', (route) =>
      route.fulfill({ body: '<p>官方控制台测试页</p>', contentType: 'text/html' }),
    );
  await page.goto('/client/services/42');
  await page.locator('.console-sidebar-item').filter({ hasText: 'VNC' }).click();
}

test('雨云 VNC 保留完整链接，打开官方页且不建立内嵌中转', async ({ page, context }) => {
  await mockConsole(page, true);
  await page.locator('.vnc-toolbar button').first().click();
  const link = page.locator('a.vnc-empty');
  await expect(link).toHaveAttribute('href', officialUrl);
  await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
  await expect(page.locator('iframe.vnc-frame')).toHaveCount(0);
  const popupPromise = context.waitForEvent('page');
  await link.click();
  const popup = await popupPromise;
  await expect(popup).toHaveURL(officialUrl);
  expect(await popup.evaluate(() => window.opener)).toBeNull();
  await popup.close();
});

test('其他插件仍使用原有内嵌 VNC', async ({ page }) => {
  await mockConsole(page, false);
  await page.route('**/vnc/vnc.html?**', (route) => route.fulfill({ body: '本地 VNC 测试页' }));
  await page.locator('.vnc-toolbar button').first().click();
  await expect(page.locator('iframe.vnc-frame')).toHaveAttribute(
    'src',
    /\/vnc\/vnc.html\?token=fixture&service_id=42$/,
  );
  await expect(page.locator('a.vnc-empty')).toHaveCount(0);
});

test('声明前置维护时限的重装可等待超过普通请求时限', async ({ page }) => {
  test.setTimeout(45000);
  await mockConsole(page, true);
  let submitted = 0;
  await page.route('**/services/42/reinstallations/options', (route) =>
    route.fulfill({
      json: {
        code: 0,
        data: { os: [{ os_id: '146', name: 'Debian 11', group_name: 'Linux' }], request_timeout_ms: 90000 },
      },
    }),
  );
  await page.route('**/services/42/reinstallations', async (route) => {
    submitted++;
    expect(route.request().postDataJSON()).toEqual({ os_id: '146' });
    // 模拟自动关机等待：普通 POST 的 18 秒超时会让该用例失败。
    await new Promise((resolve) => setTimeout(resolve, 19500));
    await route.fulfill({ json: { code: 0, data: { message: '已自动关机并提交重装' } } });
  });
  await page.getByRole('button', { name: '更多', exact: true }).click();
  await page
    .locator('.t-dropdown__item-text')
    .filter({ hasText: /^重装系统$/ })
    .click();
  await page.getByRole('button', { name: '提交重装', exact: true }).click();
  await page.getByRole('button', { name: '确认重装', exact: true }).click();
  await expect(page.getByText('已自动关机并提交重装', { exact: true })).toBeVisible({ timeout: 25000 });
  expect(submitted).toBe(1);
});

test('xtermjs 面板通过明确点击打开官方入口，失败时不保留过期链接', async ({ page, context }) => {
  const source = readFileSync(
    new URL('../../../backend/plugins/servers/rainyun_rcs/resources/panel.html', import.meta.url),
    'utf8',
  );
  let fail = false;
  await page.route('http://127.0.0.1:5177/rainyun-panel-fixture', (route) =>
    route.fulfill({
      contentType: 'text/html',
      body: source.replace(
        '__RAINYUN_DATA__',
        JSON.stringify({ key: 'terminal', endpoint: '/rainyun-action-fixture' }),
      ),
    }),
  );
  await page.route('**/rainyun-action-fixture', (route) =>
    route.fulfill({
      json: fail ? { status: 400, msg: '获取入口失败' } : { status: 200, data: { url: officialUrl } },
    }),
  );
  await context.route('https://fixture-vnc.raincs.cn/**', (route) => route.fulfill({ body: '终端测试页' }));
  await page.goto('/rainyun-panel-fixture');
  await page.getByRole('button', { name: '获取终端入口' }).click();
  const link = page.getByRole('link', { name: '打开雨云终端' });
  await expect(link).toHaveAttribute('href', officialUrl);
  const popupPromise = context.waitForEvent('page');
  await link.click();
  const popup = await popupPromise;
  await expect(popup).toHaveURL(officialUrl);
  await popup.close();
  fail = true;
  await page.getByRole('button', { name: '获取终端入口' }).click();
  await expect(page.getByRole('status')).toHaveText('获取入口失败');
  await expect(link).toBeHidden();
});

test('云盘面板按操作切换字段并使用已确认报价建单', async ({ page }) => {
  const source = readFileSync(
    new URL('../../../backend/plugins/servers/rainyun_rcs/resources/panel.html', import.meta.url),
    'utf8',
  );
  await page.route('http://127.0.0.1:5177/rainyun-disk-fixture', (route) =>
    route.fulfill({
      contentType: 'text/html',
      body: source.replace(
        '__RAINYUN_DATA__',
        JSON.stringify({
          key: 'disks',
          endpoint: '/rainyun-disk-action',
          disks: [{ ID: 8, Slot: 1, Size: 50, DiskType: 'ssd', Backup: true }],
          disk_types: ['ssd'],
        }),
      ),
    }),
  );
  const requests: Record<string, unknown>[] = [];
  await page.route('**/rainyun-disk-action', (route) => {
    const body = route.request().postDataJSON();
    requests.push(body);
    return route.fulfill({
      json: {
        status: 200,
        msg: '调整已完成',
        data:
          body.func === 'quote'
            ? { amount: '0.00', refund_amount: '10.00', monthly_change: '-10.00', quote_token: 'fixture-quote' }
            : { invoice_id: 88 },
      },
    });
  });
  await page.goto('/rainyun-disk-fixture');
  await expect(page.getByLabel('已有云盘')).toBeHidden();
  await page.getByRole('combobox', { name: '操作', exact: true }).selectOption('expand');
  await expect(page.getByLabel('已有云盘')).toBeVisible();
  await expect(page.locator('select[name="disk_type"]')).toBeHidden();
  await expect(page.getByLabel('目标容量 GB')).toHaveValue('50');
  await expect(page.getByLabel('启用备份')).toBeChecked();
  await page.getByRole('combobox', { name: '操作', exact: true }).selectOption('delete');
  await expect(page.getByLabel('目标容量 GB')).toBeHidden();
  await expect(page.getByLabel('启用备份')).toBeHidden();
  await page.getByRole('button', { name: '获取报价' }).click();
  await expect(page.getByText(/删除云盘将永久丢失盘内数据/)).toBeVisible();
  await page.getByRole('button', { name: '确认', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('调整已完成');
  expect(requests).toHaveLength(2);
  expect(requests[1]).toMatchObject({
    func: 'checkout',
    kind: 'disk',
    operation: 'delete',
    disk_id: '8',
    quote_token: 'fixture-quote',
  });
  expect(requests[1]).not.toHaveProperty('amount');
});
