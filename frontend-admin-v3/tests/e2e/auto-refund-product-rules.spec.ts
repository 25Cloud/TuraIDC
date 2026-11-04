import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 管理端「退款商品策略」（按商品复刻上游 AutoRefund 的 type / rules / request）。
 *
 * 契约：docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md 第二阶段。
 * 后端接口由 `addons/auto_refund` 插件并行实现，这里只按契约桩接口，不打真实网络。
 *
 * 覆盖四件真会坏的事：
 * 1. 列表把类型/规则/要求翻译成人话（不是裸枚举），配置状态三态都对；
 * 2. 窗口小时只在 `within_hours` 时可填，切到 `product_first` 立刻禁用；
 * 3. 保存请求体的键名与取值和契约一字不差（非 `within_hours` 时窗口写 0）；
 * 4. 插件未启用时整组消失，直接输地址退回仪表盘。
 */

const REFUND_GROUP = 'li.t-submenu[name="/admin/menu/finance/auto-refund"]';

const PRODUCT_RULES = [
  {
    product_id: 11,
    product_name: '香港 CN2',
    group_name: '香港机房',
    refund_type: 'api_ticket',
    refund_rule: 'duration',
    requirement: 'within_hours',
    window_hours: 72,
    enabled: true,
    configured: true,
    updated_at: '2026-11-02 10:00:00',
  },
  {
    product_id: 12,
    product_name: '美国 CN2',
    group_name: '美国机房',
    refund_type: 'bridge',
    refund_rule: 'month',
    requirement: 'category_first',
    window_hours: 0,
    enabled: true,
    configured: true,
    updated_at: '2026-11-02 11:00:00',
  },
  {
    // 有策略但停用：列表要能和「未配置」区分开
    product_id: 13,
    product_name: '日本 CN2',
    group_name: '日本机房',
    refund_type: 'manual',
    refund_rule: 'full',
    requirement: 'product_first',
    window_hours: null,
    enabled: false,
    configured: true,
    updated_at: '2026-11-02 12:00:00',
  },
  {
    // 从没配过策略的商品也要出现在列表里，才能就地配置
    product_id: 14,
    product_name: '新加坡 CN2',
    group_name: '新加坡机房',
    refund_type: '',
    refund_rule: '',
    requirement: '',
    window_hours: null,
    enabled: true,
    configured: false,
    updated_at: null,
  },
];

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'auto-refund-product-rules-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page, options: { featureEnabled?: boolean; onSave?: (body: unknown) => void } = {}) {
  const { featureEnabled = true, onSave } = options;

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'refund-policy-admin', nickname: '退款专员', permissions: ['*'] },
        features: { auto_refund: featureEnabled },
      });
    }
    if (url.pathname.endsWith('/auto-refund/product-rules') && method === 'GET') {
      return reply({ list: PRODUCT_RULES, total: PRODUCT_RULES.length, page: 1, page_size: 20 });
    }
    if (/\/auto-refund\/product-rules\/\d+$/.test(url.pathname) && method === 'PUT') {
      onSave?.(route.request().postDataJSON());
      return reply({ ok: true });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('策略列表渲染类型/规则/要求的中文文案与配置状态', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端才渲染完整表格');

  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: true });
  await page.goto('/admin/auto-refund/product-rules', { waitUntil: 'domcontentloaded' });

  // 入口挂在同一个「自助退款」分组下
  const sidebar = page.getByRole('navigation');
  await expect(sidebar).toBeVisible({ timeout: 15000 });
  await expect(sidebar.locator(REFUND_GROUP)).toHaveCount(1);
  await expect(sidebar.getByText('退款商品策略', { exact: true })).toHaveCount(1);

  const rows = page.locator('.t-table tbody tr');
  await expect(rows).toHaveCount(4);

  // 第一行：工单通道 + 按时长 + 指定时间内 + 72 小时
  await expect(rows.nth(0)).toContainText('香港 CN2');
  await expect(rows.nth(0)).toContainText('香港机房');
  await expect(rows.nth(0)).toContainText('API 工单退款');
  await expect(rows.nth(0)).toContainText('按时长折算');
  await expect(rows.nth(0)).toContainText('指定时间内');
  await expect(rows.nth(0)).toContainText('72');
  await expect(rows.nth(0)).toContainText('已配置');

  // 第二行：插件间对接 + 按月 + 同类产品首次（窗口列不参与显示）
  await expect(rows.nth(1)).toContainText('插件间对接');
  await expect(rows.nth(1)).toContainText('按月折算');
  await expect(rows.nth(1)).toContainText('同类产品首次');

  // 第三行有策略但停用，第四行从没配过
  await expect(rows.nth(2)).toContainText('已停用');
  await expect(rows.nth(3)).toContainText('未配置');
});

test('窗口小时只在「指定时间内」可填，切到「产品首次」后禁用', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '策略抽屉在桌面端列更稳定');

  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: true });
  await page.goto('/admin/auto-refund/product-rules', { waitUntil: 'domcontentloaded' });

  await page.getByRole('button', { name: '编辑' }).first().click();

  const drawer = page.locator('.t-drawer').first();
  await expect(drawer).toBeVisible({ timeout: 15000 });

  // 每个取值旁边都有一句人话说明，而不是只给枚举值
  await expect(drawer).toContainText('向上游提交工单申请退款');
  await expect(drawer).toContainText('同一级商品分组仅限首次');

  // 当前是 within_hours：窗口可填
  const windowInput = drawer.locator('.t-input-number input');
  await expect(windowInput).toBeEnabled();
  await expect(windowInput).toHaveValue('72');

  // 切到产品首次：窗口小时禁用（联动由 requirement 决定，不自造一套开关）
  // 点文字而不是 input：TDesign 的 radio 本体是视觉隐藏的 t-radio__former，会被样式层拦截
  await drawer.getByText('产品首次', { exact: true }).click();
  await expect(windowInput).toBeDisabled();

  // 切回指定时间内：重新可填
  await drawer.getByText('指定时间内', { exact: true }).click();
  await expect(windowInput).toBeEnabled();
});

test('保存请求体带 refund_type/refund_rule/requirement/window_hours/enabled', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '表格与抽屉在桌面端列更稳定');

  const bodies: Array<Record<string, unknown>> = [];
  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: true, onSave: (body) => bodies.push(body as Record<string, unknown>) });
  await page.goto('/admin/auto-refund/product-rules', { waitUntil: 'domcontentloaded' });

  const drawer = page.locator('.t-drawer').first();
  // 抽屉关闭后根节点仍留在 DOM 里，只能靠内容里的按钮判断它是否真的展开
  const saveButton = drawer.getByRole('button', { name: '保存' });

  // 第一笔：工单通道改成自动退款，规则/要求/窗口沿用原值
  await page.getByRole('button', { name: '编辑' }).first().click();
  await expect(saveButton).toBeVisible({ timeout: 15000 });
  await drawer.getByText('自动退款', { exact: true }).click();
  await saveButton.click();

  await expect.poll(() => bodies.length).toBe(1);
  expect(bodies[0]).toEqual({
    refund_type: 'auto',
    refund_rule: 'duration',
    requirement: 'within_hours',
    window_hours: 72,
    enabled: true,
  });

  // 保存成功后抽屉收起；下一笔重新打开同一个商品
  await expect(page.getByText('策略已保存')).toBeVisible();

  // 第二笔：要求改成「产品首次」，窗口小时回落成 0（只在「指定时间内」生效）
  await page.getByRole('button', { name: '编辑' }).first().click();
  await expect(saveButton).toBeVisible({ timeout: 15000 });
  await drawer.getByText('产品首次', { exact: true }).click();
  await saveButton.click();

  await expect.poll(() => bodies.length).toBe(2);
  expect(bodies[1]).toMatchObject({
    refund_type: 'api_ticket',
    refund_rule: 'duration',
    requirement: 'product_first',
    window_hours: 0,
    enabled: true,
  });
});

test('插件未启用时分组消失，直连退回仪表盘', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组');

  await seedAdminSession(page);
  await mockAdminApi(page, { featureEnabled: false });

  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });
  const sidebar = page.getByRole('navigation');
  await expect(sidebar).toBeVisible({ timeout: 15000 });
  await expect(sidebar.locator(REFUND_GROUP)).toHaveCount(0);
  await expect(sidebar.getByText('退款商品策略', { exact: true })).toHaveCount(0);

  await page.goto('/admin/auto-refund/product-rules', { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/\/admin\/dashboard/, { timeout: 15000 });
});
