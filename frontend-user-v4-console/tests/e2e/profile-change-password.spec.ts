import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

const USER_INFO = {
  id: 1,
  name: 'e2e用户',
  nickname: 'e2e用户',
  email: 'e2e@example.com',
  phone: '13800000000',
  cash_balance: '0.00',
  is_verified: 0,
  created_at: '2026-08-20 10:00:00',
};

// 装配个人资料页的接口桩；onChangePassword 捕获 PUT /v2/client/password 的请求体，
// 用来断言「本地校验拦下时不发请求」。token 走 shared session driver 的
// localStorage 迁移路径（读到后回写 Cookie），与 admin 端 e2e 同一惯例。
async function mockClientApi(page: Page, options: { onChangePassword?: (body: unknown) => void } = {}) {
  const { onChangePassword } = options;

  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'profile-password-test-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond(USER_INFO);
    }
    if (url.pathname.endsWith('/auth/notification-preferences')) {
      return respond({});
    }
    if (url.pathname.endsWith('/password') && method === 'PUT') {
      onChangePassword?.(route.request().postDataJSON());
      return respond({});
    }

    return respond({});
  });
}

async function openChangePasswordDialog(page: Page) {
  await page.goto('/client/profile', { waitUntil: 'domcontentloaded' });
  await page.locator('.t-menu__item', { hasText: '账户安全' }).click();
  await page.locator('.security-item', { hasText: '登录密码' }).getByRole('button', { name: '修改密码' }).click();
  const dialog = page.locator('.t-dialog').filter({ hasText: '修改登录密码' });
  await expect(dialog).toBeVisible();
  return dialog;
}

function fieldInput(dialog: ReturnType<Page['locator']>, label: string) {
  return dialog.locator('.t-form__item', { hasText: label }).locator('input');
}

// 后端 UpdatePasswordRequest 的 newPassword 是 min:8；changePassword 分支此前只判空，
// 6-7 位要等 422 才被发现。这两条锁住「修改登录密码」入口的前后端口径一致。
test('修改登录密码：7 位新密码被本地拦下且不发送请求', async ({ page }) => {
  let putCalls = 0;
  await mockClientApi(page, { onChangePassword: () => (putCalls += 1) });
  const dialog = await openChangePasswordDialog(page);

  await fieldInput(dialog, '原密码').fill('old-password');
  await fieldInput(dialog, '新密码').fill('1234567');
  await fieldInput(dialog, '确认密码').fill('1234567');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '新密码长度不能少于 8 位' })).toBeVisible();
  await expect(dialog).toBeVisible();
  expect(putCalls).toBe(0);
});

test('修改登录密码：8 位新密码放行并携带输入字段', async ({ page }) => {
  const payloads: unknown[] = [];
  await mockClientApi(page, { onChangePassword: (body) => payloads.push(body) });
  const dialog = await openChangePasswordDialog(page);

  await fieldInput(dialog, '原密码').fill('old-password');
  await fieldInput(dialog, '新密码').fill('12345678');
  await fieldInput(dialog, '确认密码').fill('12345678');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '密码修改成功' })).toBeVisible();
  expect(payloads).toHaveLength(1);
  expect(payloads[0]).toMatchObject({
    oldPassword: 'old-password',
    newPassword: '12345678',
    confirmPassword: '12345678',
  });
});
