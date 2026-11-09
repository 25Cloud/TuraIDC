import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 管理端「实例改配」入口与对话框回归。
 *
 * 背景：改配会改变上游资源占用，但本平台没有「按配置项补差价」的订单模型，
 * 所以入口只在管理端服务详情页，且必须让运营看懂两件事：
 *  1) 每个输入框留空 = 不改这一项（不是清零）；
 *  2) 这是不经订单与计费的手工操作，差价要自己处理。
 *
 * 这里锚住 4 点，不真提交（host-config 一旦成功就真的改上游规格）：
 *  1) `actions.reinstall`（= 后端 canExecuteConsoleActions）为真时入口出现，为假时不出现；
 *  2) 对话框打开即带留空口径与计费口径两句提示；
 *  3) 超出后端范围的值被前端拦下并给出中文提示，且请求根本没发出去；
 *  4) 一项都不填时同样拦下并提示「至少填写一项」。
 */

const USER_ID = 1;
const SERVICE_ID = 3885;
const DETAIL_PATH = `/v2/admin/users/${USER_ID}/services/${SERVICE_ID}`;

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'host-config-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

/**
 * 接管 /api/v2/admin：详情、连接、能力与票据按需应答，其余回空。
 * 请求带 `?_t=` 时间戳，匹配一律基于 pathname。
 */
async function mockAdminConsoleApi(page: Page, detail: Record<string, unknown>) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname.replace(/^\/api/, '');
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'host-config', nickname: 'host-config', permissions: ['*'] } });
    }
    if (pathname === DETAIL_PATH) return respond(detail);
    if (pathname === `${DETAIL_PATH}/remote-status`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/capabilities`) return respond({ supported: false, fetchable: false });
    if (pathname === `${DETAIL_PATH}/console/tickets`) return respond({ ticket: 'ticket-1' });

    return respond({});
  });
}

/** 云主机详情：actions.reinstall 为真 → 与服务「可控上游」同档，改配入口应出现 */
function cloudServiceDetail(actions: Record<string, unknown>): Record<string, unknown> {
  return {
    id: SERVICE_ID,
    name: '云服务器-01',
    custom_service_name: '云服务器-01',
    product_display_name: '标准云服务器',
    product: { id: 10, name: '标准云服务器', type: 'vps', type_label: '云服务器', display_name: '标准云服务器' },
    machine_category: { key: 'cloud', label: '云服务器' },
    console_template: 'default',
    status: 1,
    status_label: '运行中',
    billing_cycle: 'monthly',
    billing_cycle_label: '月付',
    amount: '58.00',
    expires_at: '2026-11-01 00:00:00',
    connection: { hostname: 'hk12543', dedicated_ip: '192.0.2.10', internal_ip: '', port: 22 },
    upstream: { provider_key: 'zjmf', host_id: 168, status: 'active' },
    actions,
  };
}

/** 打开服务详情页，等改配按钮可用（或确认它不存在） */
async function openServiceDetail(page: Page, actions: Record<string, unknown>) {
  await seedAdminSession(page);
  await mockAdminConsoleApi(page, cloudServiceDetail(actions));
  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.drawer-actions')).toBeVisible({ timeout: 15000 });
}

function entryButton(page: Page) {
  // 只认操作区里的「改配」：对话框标题、确认按钮也含这两个字，必须按容器收敛
  return page.locator('.drawer-actions').getByRole('button', { name: '改配', exact: true });
}

function hostConfigDialog(page: Page) {
  return page.locator('.t-dialog').filter({ hasText: '实例改配' }).first();
}

/** 计数发往 host-config 的写请求：断言「拦在前端」时用它证明没打后端 */
function trackHostConfigRequests(page: Page): string[] {
  const hits: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url().includes('/host-config')) hits.push(request.url());
  });
  return hits;
}

/** t-input-number 受控：整段 fill 不触发 change，只能逐字符敲 */
async function typeNumber(input: Locator, value: string) {
  await input.click();
  await input.press('ControlOrMeta+a');
  await input.pressSequentially(value);
  await input.press('Enter');
}

test('可控上游的实例显示「改配」入口', async ({ page }) => {
  await openServiceDetail(page, { refresh: true, reinstall: true, rescue: true, password_reset: true });
  await expect(entryButton(page)).toBeVisible();
});

test('与重装/救援同一档判定：actions.reinstall=false 时不显示入口', async ({ page }) => {
  await openServiceDetail(page, { refresh: true, reinstall: false, rescue: false });
  await expect(entryButton(page)).toHaveCount(0);
});

test('对话框带留空口径与计费口径提示，分组齐全', async ({ page }) => {
  await openServiceDetail(page, { refresh: true, reinstall: true });
  await entryButton(page).click();

  const dialog = hostConfigDialog(page);
  await expect(dialog).toBeVisible({ timeout: 10000 });

  // 两句口径：留空≠清零、不经订单计费
  await expect(dialog).toContainText('留空表示不修改该项');
  await expect(dialog).toContainText('不生成订单、不走本平台计费');

  // 四个分组标题
  for (const section of ['计算', '网络', '存储', '配额']) {
    await expect(dialog.getByText(section, { exact: true }).first()).toBeVisible();
  }

  // 关键字段的占位符统一是「留空不修改」，避免运营以为要逐项填满
  await expect(dialog.locator('input[placeholder="留空不修改"]')).toHaveCount(11);
});

test('超出后端范围的规格被前端拦下，且不打后端', async ({ page }) => {
  const hits = trackHostConfigRequests(page);
  await openServiceDetail(page, { refresh: true, reinstall: true });
  await entryButton(page).click();

  const dialog = hostConfigDialog(page);
  await expect(dialog).toBeVisible({ timeout: 10000 });

  // 计算组第一个输入是 CPU（后端上限 256）
  await typeNumber(dialog.locator('.host-config-section').first().locator('input').first(), '999');
  await dialog.getByRole('button', { name: '确认改配' }).click();

  await expect(page.locator('.t-message').filter({ hasText: 'CPU 核数需要在 1–256 之间' })).toBeVisible();
  // 对话框不关闭，运营可以就地改
  await expect(dialog).toBeVisible();
  expect(hits, `前端校验失败时不应发请求：${hits.join(' | ')}`).toEqual([]);
});

test('一项都不填时拦下并提示至少填写一项', async ({ page }) => {
  const hits = trackHostConfigRequests(page);
  await openServiceDetail(page, { refresh: true, reinstall: true });
  await entryButton(page).click();

  const dialog = hostConfigDialog(page);
  await expect(dialog).toBeVisible({ timeout: 10000 });

  await dialog.getByRole('button', { name: '确认改配' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '请至少填写一项要变更的规格' })).toBeVisible();
  expect(hits).toEqual([]);
});
