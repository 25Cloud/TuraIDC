import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

const AGENT_GROUPS = [
  { id: 1, name: '金牌代理', code: 'gold', status: 1, sort_order: 0, remark: '', updated_at: '2026-08-20 10:00:00' },
  { id: 2, name: '银牌代理', code: 'silver', status: 1, sort_order: 1, remark: '', updated_at: '2026-08-20 10:00:00' },
];

const PRODUCT_GROUPS = [
  {
    id: 11,
    name: '云服务器',
    code: 'cloud',
    min_discount_rate: '90.00',
    cost_rate: '80.00',
    status: 1,
    sort_order: 0,
    remark: '',
  },
];

// 金牌列已有 95 折扣、银牌列为空，用来覆盖「空格子不提交」这条。
const MATRIX_ROWS = [
  {
    id: 11,
    name: '云服务器',
    code: 'cloud',
    min_discount_rate: '90.00',
    discounts: [
      { agent_group_id: 1, discount_rate: '95.00' },
      { agent_group_id: 2, discount_rate: null },
    ],
  },
];

// 装配代理折扣页的接口桩。permissions 只给 agent_discount.list 即可覆盖只读场景；
// onSave 用来捕获矩阵保存的请求体。
async function mockAdminApi(page: Page, options: { permissions?: string[]; onSave?: (body: unknown) => void } = {}) {
  const { permissions = ['*'], onSave } = options;

  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'agent-discounts-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/admin/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'discount-test', nickname: 'discount-test', permissions } });
    }
    if (url.pathname.endsWith('/agent-groups') && method === 'GET') {
      return respond({ list: AGENT_GROUPS });
    }
    if (url.pathname.endsWith('/product-discount-groups') && method === 'GET') {
      return respond({ list: PRODUCT_GROUPS });
    }
    if (url.pathname.endsWith('/agent-group-discounts') && method === 'GET') {
      return respond({ rows: MATRIX_ROWS });
    }
    if (url.pathname.endsWith('/agent-group-discounts') && method === 'PUT') {
      onSave?.(route.request().postDataJSON());
      return respond([]);
    }

    return respond({});
  });
}

async function openMatrixTab(page: Page) {
  await page.goto('/admin/agent-discounts', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.agent-discounts-page')).toContainText('金牌代理');
  await page.locator('.t-tabs__nav-item', { hasText: '折扣矩阵' }).click();
  await expect(page.locator('.agent-matrix table')).toBeVisible();
}

/** 矩阵第一行（云服务器）第 index 个代理组的折扣输入框；行首是 <th>，故 td 从金牌开始计数 */
function matrixCell(page: Page, index: number) {
  return page.locator('.agent-matrix tbody tr').first().locator('td').nth(index).locator('input');
}

/** t-input-number 是受控组件，fill() 的整段替换不会触发它的 change，只能逐字符敲 */
async function setMatrixCell(page: Page, index: number, value: string) {
  const cell = matrixCell(page, index);
  await cell.click();
  await cell.press('ControlOrMeta+a');
  await cell.pressSequentially(value);
  await cell.press('Enter');
  await expect(cell).toHaveValue(new RegExp(`^${value}`));
}

test('矩阵保存只提交填写过的格子', async ({ page }) => {
  const payloads: unknown[] = [];
  await mockAdminApi(page, { onSave: (body) => payloads.push(body) });
  await openMatrixTab(page);

  // 银牌那格是空的，不能带 null 提交——后端 items.*.discount_rate 是 required，
  // 一旦带上整批 422，填好的金牌折扣也会跟着保存不进去。
  await page.getByRole('button', { name: '保存矩阵' }).click();
  await expect(page.locator('.t-message').filter({ hasText: '折扣矩阵已保存' })).toBeVisible();

  expect(payloads).toHaveLength(1);
  const { items } = payloads[0] as { items: Array<Record<string, unknown>> };
  expect(items).toEqual([{ agent_group_id: 1, product_discount_group_id: 11, discount_rate: 95 }]);
});

test('低于最低折扣率时拦在前端并指出具体组合', async ({ page }) => {
  let saveCalls = 0;
  await mockAdminApi(page, { onSave: () => (saveCalls += 1) });
  await openMatrixTab(page);

  // 云服务器折扣组最低 90，金牌那格填 50 必须拦下且不发请求。
  await setMatrixCell(page, 0, '50');
  await page.getByRole('button', { name: '保存矩阵' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '云服务器 × 金牌代理' })).toBeVisible();
  expect(saveCalls).toBe(0);
});

test('保存成功后重新拉取矩阵，丢弃本地未保存的改动', async ({ page }) => {
  await mockAdminApi(page);
  await openMatrixTab(page);

  const reloaded = page.waitForRequest(
    (request) => request.url().includes('/agent-group-discounts') && request.method() === 'GET',
  );
  await setMatrixCell(page, 0, '96');
  await page.getByRole('button', { name: '保存矩阵' }).click();
  await reloaded;

  // 桩固定返回 95，重新加载后必须回到服务端的值而不是页面上的 96。
  await expect(matrixCell(page, 0)).toHaveValue('95.00');
});

test('代理组与商品折扣组可新增', async ({ page }) => {
  const created: Array<{ path: string; body: unknown }> = [];
  await mockAdminApi(page);
  await page.route('**/api/v2/admin/{agent-groups,product-discount-groups}', async (route: Route) => {
    if (route.request().method() !== 'POST') return route.fallback();
    created.push({ path: new URL(route.request().url()).pathname, body: route.request().postDataJSON() });
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
  });

  await page.goto('/admin/agent-discounts', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.agent-discounts-page')).toContainText('金牌代理');

  await page.getByRole('button', { name: '新增代理组' }).click();
  const agentDialog = page.locator('.t-dialog').filter({ hasText: '新增代理组' });
  await agentDialog.getByPlaceholder('如：金牌代理').fill('铂金代理');
  await agentDialog.getByPlaceholder('唯一标识，如 gold').fill('platinum');
  await agentDialog.getByRole('button', { name: '保存' }).click();
  await expect(page.locator('.t-message').filter({ hasText: '代理组已创建' })).toBeVisible();

  await page.locator('.t-tabs__nav-item', { hasText: '商品折扣组' }).click();
  await page.getByRole('button', { name: '新增折扣组' }).click();
  const productDialog = page.locator('.t-dialog').filter({ hasText: '新增商品折扣组' });
  await productDialog.getByPlaceholder('如：云服务器').fill('对象存储');
  await productDialog.getByPlaceholder('唯一标识，如 cloud').fill('oss');
  await productDialog.getByRole('button', { name: '保存' }).click();
  await expect(page.locator('.t-message').filter({ hasText: '商品折扣组已创建' })).toBeVisible();

  expect(created.map((item) => item.path.split('/').pop())).toEqual(['agent-groups', 'product-discount-groups']);
  expect(created[0].body).toMatchObject({ name: '铂金代理', code: 'platinum' });
  expect(created[1].body).toMatchObject({ name: '对象存储', code: 'oss' });
});

test('无 agent_discount.manage 权限时矩阵只读', async ({ page }) => {
  await mockAdminApi(page, { permissions: ['agent_discount.list'] });
  await openMatrixTab(page);

  await expect(page.getByRole('button', { name: '保存矩阵' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: '新增代理组' })).toHaveCount(0);
  await expect(matrixCell(page, 0)).toBeDisabled();
});
