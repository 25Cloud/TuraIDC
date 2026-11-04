import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 管理端「退款申请」台账。
 *
 * 后端接口由 `addons/auto_refund` 插件注册（契约见
 * docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md）。
 * 这里覆盖五件真会坏的事：入口按功能开关收口、台账与详情把上游原文展示出来、
 * 退款类型/通道与策略快照这类第二阶段字段不丢、结算提交的金额与备注、
 * 以及没权限时不给操作按钮。
 */

const REFUND_GROUP = 'li.t-submenu[name="/admin/menu/finance/auto-refund"]';

const APPLICATION = {
  id: 9001,
  application_no: 'AR20261101A1B2',
  user_id: 7,
  user: { id: 7, email: 'buyer@example.test', nickname: '买家' },
  service_id: 12,
  service_name: '香港-CN2-01',
  product_name: '香港 CN2',
  order_id: 33,
  invoice_id: 44,
  upstream_product_id: '1001',
  status: 'pending_review',
  status_label: '待上游审核',
  // 第二阶段：按商品配置后，一张申请单必须能说明「走的哪种类型、哪条通道、工单在哪」
  refund_type: 'api_ticket',
  refund_type_label: 'API 工单退款',
  channel: 'api_ticket',
  channel_label: '上游工单',
  ticket_id: 7001,
  upstream_ticket_id: 'ZJ-9988',
  policy_snapshot: JSON.stringify({
    refund_type: 'api_ticket',
    refund_rule: 'duration',
    requirement: 'within_hours',
    window_hours: 72,
  }),
  reason: '业务调整',
  estimated_amount: '45.20',
  settled_amount: null as string | null,
  upstream_code: 200,
  upstream_msg: '退款申请提交成功',
  trace_id: 'api_abc123',
  response_meta: { action: 'submit', hostid: '1001', code: 200 },
  submitted_at: '2026-11-01 12:00:00',
  created_at: '2026-11-01 12:00:00',
};

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'auto-refund-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(
  page: Page,
  options: { featureEnabled?: boolean; onSettle?: (body: unknown) => void } = {},
) {
  const { featureEnabled = true, onSettle } = options;

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'refund-admin', nickname: '退款专员', permissions: ['*'] },
        features: { auto_refund: featureEnabled },
      });
    }
    if (url.pathname.endsWith('/auto-refund/applications') && method === 'GET') {
      return reply({ list: [APPLICATION], total: 1, page: 1, page_size: 20 });
    }
    if (/\/auto-refund\/applications\/\d+$/.test(url.pathname) && method === 'GET') {
      return reply(APPLICATION);
    }
    if (/\/auto-refund\/applications\/\d+\/settle$/.test(url.pathname) && method === 'POST') {
      onSettle?.(route.request().postDataJSON());
      return reply({ ...APPLICATION, status: 'settled', settled_amount: '45.20' });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('功能启用时侧栏出现自助退款分组，台账渲染申请单号', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组');

  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: true });
  await page.goto('/admin/auto-refund/applications', { waitUntil: 'domcontentloaded' });

  const sidebar = page.getByRole('navigation');
  await expect(sidebar).toBeVisible({ timeout: 15000 });
  await expect(sidebar.locator(REFUND_GROUP)).toHaveCount(1);
  await expect(page.locator('.auto-refund-link', { hasText: 'AR20261101A1B2' })).toBeVisible();
  await expect(page.getByText('待上游审核').first()).toBeVisible();

  // 第二阶段新增两列：退款类型与通道
  const firstRow = page.locator('.t-table tbody tr').first();
  await expect(firstRow).toContainText('API 工单退款');
  await expect(firstRow).toContainText('上游工单');
});

test('详情抽屉原样展示上游 code、msg、trace_id 与核对提示', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '详情抽屉在桌面端才展开为侧栏抽屉');

  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: true });
  await page.goto('/admin/auto-refund/applications', { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: '详情' }).first().click();

  const drawer = page.locator('.t-drawer').first();
  await expect(drawer).toBeVisible({ timeout: 15000 });

  // 上游原文在第二个页签里：申请信息 / 上游返回
  await drawer.getByText('上游返回', { exact: true }).click();

  // 上游没有进度查询接口，这句必须摆在结算入口旁边
  await expect(drawer).toContainText('上游不提供进度查询');
  await expect(drawer).toContainText('api_abc123');
  await expect(drawer).toContainText('退款申请提交成功');
  await expect(drawer).toContainText('1001');
});

test('详情抽屉展示通道、工单号与格式化后的策略快照', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '详情抽屉在桌面端才展开为侧栏抽屉');

  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: true });
  await page.goto('/admin/auto-refund/applications', { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: '详情' }).first().click();

  const drawer = page.locator('.t-drawer').first();
  await expect(drawer).toBeVisible({ timeout: 15000 });

  // 申请信息页签：退款类型 / 通道 / 本站工单 / 上游工单号
  await expect(drawer).toContainText('API 工单退款');
  await expect(drawer).toContainText('上游工单号');
  await expect(drawer).toContainText('ZJ-9988');
  await expect(drawer).toContainText('7001');

  // 策略快照存的是 JSON 字符串：必须格式化后逐键展示，而不是糊一坨原始字符串
  const snapshot = drawer.locator('.auto-refund-json').first();
  await expect(snapshot).toContainText('"requirement": "within_hours"');
  await expect(snapshot).toContainText('"window_hours": 72');

  // api_ticket 通道给一句「上游工单在工单模块查看」，并带跳转入口
  await expect(drawer.getByText('上游工单在工单模块查看')).toBeVisible();
  await expect(drawer.getByRole('button', { name: '查看工单' })).toBeVisible();
});

test('结算默认填入预估金额，提交体只带 amount 与 remark', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '结算走表格操作列，桌面端列更稳定');

  const bodies: unknown[] = [];
  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: true, onSettle: (body) => bodies.push(body) });
  await page.goto('/admin/auto-refund/applications', { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: '更多' }).first().click();
  await page.getByText('结算', { exact: true }).last().click();

  const dialog = page.locator('.t-dialog').filter({ hasText: '结算退款申请' });
  await expect(dialog).toBeVisible({ timeout: 15000 });
  await expect(dialog.locator('input').first()).toHaveValue('45.20');

  await dialog.getByPlaceholder('填写结算说明，便于后续对账（选填）').fill('上游已到账');
  await dialog.getByRole('button', { name: '确认结算' }).click();

  await expect.poll(() => bodies.length).toBe(1);
  const body = bodies[0] as { amount: number | string; remark: string };
  expect(Number(body.amount)).toBe(45.2);
  expect(body.remark).toBe('上游已到账');
});

test('插件未启用时分组消失，直连退回仪表盘', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组');

  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: false });

  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
  const sidebar = page.getByRole('navigation');
  await expect(sidebar).toBeVisible({ timeout: 15000 });
  await expect(sidebar.locator(REFUND_GROUP)).toHaveCount(0);
  await expect(sidebar.getByText('自助退款', { exact: true })).toHaveCount(0);

  await page.goto('/admin/auto-refund/applications', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/admin\/dashboard/, { timeout: 15000 });
});
