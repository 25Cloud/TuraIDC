import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

const USER_ROW = {
  id: 1,
  email: 'existing@example.com',
  phone: '13800000000',
  nickname: '既有用户',
  display_name: '既有用户',
  status: 1,
  cash_balance: '0.00',
  opened_product_count: 0,
  verification_status: 0,
  is_verified: 0,
  created_at: '2026-08-20 10:00:00',
};

// 装配用户列表页的接口桩；onCreate 捕获创建请求体，用来断言「拦下时不发请求」。
async function mockAdminApi(page: Page, options: { onCreate?: (body: unknown) => void } = {}) {
  const { onCreate } = options;

  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'users-create-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/admin/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'users-test', nickname: 'users-test', permissions: ['*'] } });
    }
    if (url.pathname.endsWith('/users') && method === 'GET') {
      return respond({ list: [USER_ROW], total: 1, page: 1, page_size: 20 });
    }
    if (url.pathname.endsWith('/users') && method === 'POST') {
      onCreate?.(route.request().postDataJSON());
      return respond({ id: 2 });
    }

    return respond({});
  });
}

async function openCreateDialog(page: Page) {
  await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: '新增用户' }).click();
  const dialog = page.locator('.t-dialog').filter({ hasText: '新增用户' });
  await expect(dialog).toBeVisible();
  return dialog;
}

function fieldInput(dialog: ReturnType<Page['locator']>, label: string) {
  return dialog.locator('.t-form__item', { hasText: label }).locator('input');
}

// 后端 StoreUserRequest 是 ['required','string','min:8']；此前前端只判必填，
// 7 位密码要等 422 才被发现。这两条锁住「前端拦截口径 == 后端口径」。
test('新增用户：7 位密码被前端拦下且不调用创建接口', async ({ page }) => {
  let createCalls = 0;
  await mockAdminApi(page, { onCreate: () => (createCalls += 1) });
  const dialog = await openCreateDialog(page);

  await fieldInput(dialog, '邮箱').fill('new-user@example.com');
  await fieldInput(dialog, '手机号').fill('13900000000');
  await fieldInput(dialog, '密码').fill('1234567');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(dialog.locator('.t-form__item', { hasText: '密码' })).toContainText('密码至少需要 8 位');
  await expect(dialog).toBeVisible();
  expect(createCalls).toBe(0);
});

test('新增用户：8 位密码放行并原样提交', async ({ page }) => {
  const payloads: unknown[] = [];
  await mockAdminApi(page, { onCreate: (body) => payloads.push(body) });
  const dialog = await openCreateDialog(page);

  await fieldInput(dialog, '邮箱').fill('new-user@example.com');
  await fieldInput(dialog, '手机号').fill('13900000000');
  await fieldInput(dialog, '密码').fill('12345678');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '创建成功' })).toBeVisible();
  expect(payloads).toHaveLength(1);
  expect(payloads[0]).toMatchObject({ email: 'new-user@example.com', password: '12345678' });
});
