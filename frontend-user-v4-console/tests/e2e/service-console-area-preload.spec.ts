import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 实例控制台「面板区域（魔方兼容 tab）」预热策略回归。
 *
 * 背景：面板内容接口是 no-store，浏览器缓存复用不了，所以面板只能靠「iframe 不销毁」
 * 来预热。此前控制台只常驻**一个** AreaTab 实例、且预热的是第一个自定义区域，
 * 于是切到第 2 个自定义区域要重新回源上游，来回切换还会反复重来一次。
 *
 * 现在后台开关 system.console_area_preload_all_enabled（缺省开启）打开时，
 * 每个自定义区域各挂一个实例并在首屏全部预热。这里钉住：
 *  1) 缺省（字段缺失/为 true）渲染全部自定义区域实例；
 *  2) 显式 false 只渲染一个 —— 这是带宽吃紧时的回退路径，不能被改坏；
 *  3) 切换 tab 不改变实例数量，iframe 不重建。
 */

const SERVICE_ID = 3915;

/** NAT 云主机实例详情：console_template=default → 走通用控制台，不是面板型。 */
function serviceDetail(): Record<string, unknown> {
  return {
    id: SERVICE_ID,
    name: 'NAT云主机-01',
    custom_service_name: 'NAT云主机-01',
    status: 1,
    status_label: '运行中',
    console_template: 'default',
    machine_category: { key: 'nat', label: 'NAT主机' },
    product: { id: 10, name: 'NAT云主机', type: 'vps', type_label: '云服务器' },
    billing_cycle: 'monthly',
    billing_cycle_label: '月付',
    amount: '58.00',
    expires_at: '2026-11-01 00:00:00',
    connection: { hostname: 'nat3915', dedicated_ip: '1.2.3.4', internal_ip: '', port: 0 },
    upstream: { provider_key: 'zjmf', host_id: 8877, status: 'active' },
    actions: { refresh: true, module_status: true, power: true },
  };
}

/**
 * @param preloadAll 缺省传 undefined，模拟后端不下发该字段（缺省按开启处理）。
 */
async function mockConsoleApi(page: Page, preloadAll?: boolean) {
  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'area-preload-test-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  const capabilities: Record<string, unknown> = {
    supported: true,
    fetchable: true,
    nat_supported: true,
    monitor_supported: true,
    areas: [
      { key: 'setting', name: '设置' },
      { key: 'panel_manage', name: '管理面板' },
    ],
  };
  if (preloadAll !== undefined) capabilities.area_preload_all = preloadAll;

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({
        id: 1,
        name: 'e2e用户',
        nickname: 'e2e用户',
        email: 'e2e@example.com',
        phone: '13800000000',
        cash_balance: '0.00',
        is_verified: 1,
      });
    }

    if (url.pathname === `/v2/client/services/${SERVICE_ID}`) return respond(serviceDetail());
    if (url.pathname.endsWith(`/services/${SERVICE_ID}/console/capabilities`)) {
      return respond(capabilities);
    }
    if (url.pathname.endsWith(`/services/${SERVICE_ID}/console/tickets`)) {
      return respond({ ticket: 'area-ticket-1', expires_in: 600 });
    }
    if (url.pathname.endsWith(`/services/${SERVICE_ID}/console-area/content`)) {
      const module = url.searchParams.get('module') || '';
      return respond({ html: `<!DOCTYPE html><html><body><p>面板 ${module}</p></body></html>` });
    }

    return respond({});
  });
}

/** 面板 iframe 实例（隐藏的也计入：靠 is-offstage 显隐，不是条件渲染）。 */
function areaFrames(page: Page) {
  return page.locator('iframe.area-frame');
}

/** 侧边栏项：ConsoleSidebar 渲染的是 .console-sidebar-item button，不是 t-tabs。 */
function sidebarItem(page: Page, label: string) {
  return page.locator('.console-sidebar-item').filter({ hasText: label });
}

test('缺省预热：所有自定义区域各挂一个常驻实例', async ({ page }) => {
  await mockConsoleApi(page);
  await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

  await expect(areaFrames(page), '两个自定义区域应各有一个 iframe 实例').toHaveCount(2);
});

test('显式关闭预热：只渲染第一个自定义区域实例', async ({ page }) => {
  await mockConsoleApi(page, false);
  await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

  await expect(areaFrames(page), '关闭预热后只保留一个实例（带宽回退路径）').toHaveCount(1);
});

test('切换 tab 不重建 iframe 实例', async ({ page }) => {
  await mockConsoleApi(page);
  await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

  await expect(areaFrames(page)).toHaveCount(2);
  const before = await areaFrames(page).evaluateAll((nodes) =>
    nodes.map((node) => (node as HTMLIFrameElement).src),
  );

  await sidebarItem(page, '管理面板').click();
  await expect(areaFrames(page), '切 tab 后实例数量不变').toHaveCount(2);

  const after = await areaFrames(page).evaluateAll((nodes) =>
    nodes.map((node) => (node as HTMLIFrameElement).src),
  );
  expect(after, '切 tab 后各实例的 src 不应变化，否则等于重新回源上游').toEqual(before);
});