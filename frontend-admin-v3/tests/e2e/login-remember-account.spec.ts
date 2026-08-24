import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

const REMEMBER_KEY = 'turaidc-admin-remembered-account';
const ACCOUNT = 'cerbo';
const PASSWORD = 'Temp@123456';

async function mockLoginApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/login')) {
      return respond({ token: 'remember-account-test-token' });
    }
    if (url.pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: ACCOUNT, nickname: ACCOUNT, permissions: ['*'] } });
    }
    return respond({});
  });
}

async function signIn(page: Page, options: { remember: boolean }) {
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await page.getByPlaceholder('请输入管理员账号').fill(ACCOUNT);
  await page.getByPlaceholder('请输入密码').fill(PASSWORD);
  if (options.remember) {
    await page.getByText('记住账号', { exact: true }).click();
  }
  await page.getByRole('button', { name: '登录' }).click();
  await expect(page).not.toHaveURL(/\/admin\/login/);
}

const storedAccount = (page: Page, key: string) => page.evaluate((k) => window.localStorage.getItem(k), key);

test('勾选记住账号后回到登录页会自动回填账号', async ({ page }) => {
  await mockLoginApi(page);
  await signIn(page, { remember: true });

  expect(await storedAccount(page, REMEMBER_KEY)).toBe(ACCOUNT);

  // 清掉登录态重新进登录页，账号应已回填、勾选框保持选中。
  await page.evaluate(() => {
    window.localStorage.removeItem('admin_token');
    window.localStorage.removeItem('admin_last_active_at');
  });
  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });

  await expect(page.getByPlaceholder('请输入管理员账号')).toHaveValue(ACCOUNT);
  await expect(page.locator('.login-remember input[type="checkbox"]')).toBeChecked();
});

test('密码不落 localStorage', async ({ page }) => {
  await mockLoginApi(page);
  await signIn(page, { remember: true });

  // 这是财务系统的管理端，明文密码进 localStorage 等于把后台交给任意同源脚本。
  const dump = await page.evaluate(() => JSON.stringify(window.localStorage));
  expect(dump).not.toContain(PASSWORD);

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await expect(page.getByPlaceholder('请输入密码')).toHaveValue('');
});

test('不勾选时不留存账号，且会清掉旧的记录', async ({ page }) => {
  await mockLoginApi(page);
  await page.addInitScript(
    ([key, account]) => window.localStorage.setItem(key as string, account as string),
    [REMEMBER_KEY, 'stale-account'],
  );

  await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
  await expect(page.getByPlaceholder('请输入管理员账号')).toHaveValue('stale-account');

  // 取消勾选后登录，旧账号必须被清掉，否则换人登录还会看到上一个人的账号。
  await page.getByText('记住账号', { exact: true }).click();
  await expect(page.locator('.login-remember input[type="checkbox"]')).not.toBeChecked();
  await page.getByPlaceholder('请输入管理员账号').fill(ACCOUNT);
  await page.getByPlaceholder('请输入密码').fill(PASSWORD);
  await page.getByRole('button', { name: '登录' }).click();
  await expect(page).not.toHaveURL(/\/admin\/login/);

  expect(await storedAccount(page, REMEMBER_KEY)).toBeNull();
});
