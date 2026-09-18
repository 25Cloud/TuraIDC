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

const UPSTREAM_STATUS = {
  enabled: true,
  username: 'zjmf_1_abc123',
  has_password: true,
  login_url: 'https://console.example.test/api/v2/zjmf/zjmf_api_login',
  ip_allowlist: ['203.0.113.0/24'],
  expires_at: '2026-12-31 00:00:00',
  is_expired: false,
  last_used_at: '2026-09-16 10:00:00',
};

/**
 * 装配「API 凭据」页的接口桩。
 *
 * onPolicySave 捕获 PUT /v2/client/upstream-api/policy 的请求体，
 * 用来断言安全策略表单确实把白名单与有效期提交到了魔方链路。
 */
async function mockClientApi(page: Page, options: { onPolicySave?: (body: unknown) => void } = {}) {
  const { onPolicySave } = options;

  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'api-credentials-test-token');
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
    if (url.pathname.endsWith('/upstream-api') && method === 'GET') {
      return respond(UPSTREAM_STATUS);
    }
    if (url.pathname.endsWith('/upstream-api/policy') && method === 'PUT') {
      onPolicySave?.(route.request().postDataJSON());
      return respond(UPSTREAM_STATUS);
    }
    if (url.pathname.endsWith('/api-keys') && method === 'GET') {
      return respond({ list: [] });
    }

    return respond({});
  });
}

// 侧边栏菜单项文案来自路由 meta.title；旧的「上游 API 对接」入口已合并到本页。
async function openApiCredentials(page: Page, path = '/client/api-keys') {
  await page.goto(path, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.api-key-hero__title')).toHaveText('API 凭据');
}

test.describe('API 凭据页（开放接口密钥 + 魔方财务对接合并）', () => {
  test('页内标签可切换到魔方财务对接并显示登录信息', async ({ page }) => {
    await mockClientApi(page);
    await openApiCredentials(page);

    // 默认落在开放接口密钥分区
    await expect(page.locator('.t-tabs__nav-item', { hasText: '开放接口密钥' })).toHaveClass(/t-is-active/);

    await page.locator('.t-tabs__nav-item', { hasText: '魔方财务对接' }).click();

    const panel = page.locator('.upstream-panel');
    await expect(panel).toBeVisible();
    // 登录地址、用户名与白名单/有效期策略都要在同一分区可见
    await expect(panel.locator('input[readonly]').first()).toHaveValue(UPSTREAM_STATUS.login_url);
    await expect(panel.locator('input[readonly]').nth(1)).toHaveValue(UPSTREAM_STATUS.username);
    await expect(panel.locator('.t-form__item', { hasText: 'IP 白名单' }).locator('input')).toHaveValue(
      '203.0.113.0/24',
    );
    await expect(panel.locator('.t-form__item', { hasText: '有效期' }).locator('input')).toHaveValue(
      '2026-12-31 00:00',
    );
  });

  test('切换标签同步到地址栏，刷新后停留在同一分区', async ({ page }) => {
    await mockClientApi(page);
    await openApiCredentials(page);

    await page.locator('.t-tabs__nav-item', { hasText: '魔方财务对接' }).click();
    await expect(page).toHaveURL(/\/client\/api-keys\?tab=upstream$/);

    // 地址栏带参刷新后必须仍在魔方财务对接分区（深链可用）
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('.t-tabs__nav-item', { hasText: '魔方财务对接' })).toHaveClass(/t-is-active/);
    await expect(page.locator('.upstream-panel')).toBeVisible();
  });

  test('旧路径 /client/upstream-api 跳转到合并页的对接分区', async ({ page }) => {
    await mockClientApi(page);
    await openApiCredentials(page, '/client/upstream-api');

    await expect(page).toHaveURL(/\/client\/api-keys\?tab=upstream$/);
    await expect(page.locator('.t-tabs__nav-item', { hasText: '魔方财务对接' })).toHaveClass(/t-is-active/);
    await expect(page.locator('.upstream-panel')).toBeVisible();
  });

  test('保存安全策略提交白名单与有效期到魔方链路', async ({ page }) => {
    let saved: unknown = null;
    await mockClientApi(page, { onPolicySave: (body) => (saved = body) });
    await openApiCredentials(page, '/client/api-keys?tab=upstream');

    const panel = page.locator('.upstream-panel');
    await panel
      .locator('.t-form__item', { hasText: 'IP 白名单' })
      .locator('input')
      .fill('198.51.100.9, 198.51.100.0/24');
    await panel.getByRole('button', { name: '保存安全策略' }).click();

    await expect.poll(() => saved).toBeTruthy();
    expect(saved).toMatchObject({
      ip_allowlist: ['198.51.100.9', '198.51.100.0/24'],
      expires_at: '2026-12-31 00:00',
    });
  });
});
