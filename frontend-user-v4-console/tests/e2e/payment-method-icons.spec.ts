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

const GATEWAYS = [
  { key: 'alipay', option_key: 'epay:alipay', name: '支付宝', label: '扫码支付', payment_type: 'alipay' },
  { key: 'wechat', option_key: 'epay:wxpay', name: '微信支付', label: '扫码支付', payment_type: 'wxpay' },
  { key: 'demo_pay', option_key: 'demo_pay', name: '测试网关', label: '通用渠道' },
];

async function mockRechargeApi(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'pay-icons-test-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond(USER_INFO);
    }
    if (url.pathname.endsWith('/notifications/unread-count')) {
      return respond({ count: 0 });
    }
    if (url.pathname.endsWith('/notifications/feed')) {
      return respond({ list: [], unread_count: 0 });
    }
    if (url.pathname.endsWith('/recharge/gateways')) {
      return respond({ list: GATEWAYS });
    }
    if (url.pathname.endsWith('/services')) {
      return respond({ list: [], total: 0, page: 1, page_size: 50 });
    }

    return respond({});
  });

  // 站点品牌配置不在 client 前缀下，单独拦截避免测试请求真实接口
  const respondSite = (route: Route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
  await page.route('**/api/v2/site/**', respondSite);
}

test.describe('支付方式渠道图标', () => {
  test('桌面端支付宝/微信图标渲染官方品牌色，选中主色按钮内回退继承色', async ({ page }) => {
    await mockRechargeApi(page);
    await page.goto('/client/recharge', { waitUntil: 'domcontentloaded' });

    const alipayButton = page.locator('.pay-method', { hasText: '支付宝' });
    const wechatButton = page.locator('.pay-method', { hasText: '微信支付' });
    await expect(alipayButton).toBeVisible();
    await expect(wechatButton).toBeVisible();

    // 首个渠道默认选中：主色按钮内图标回退继承色（白色）
    await expect(alipayButton).toHaveClass(/t-button--theme-primary/);
    await expect(alipayButton.locator('.pay-channel-tone--alipay')).toHaveCSS('color', 'rgb(255, 255, 255)');
    // 未选中渠道图标保持官方品牌色
    await expect(wechatButton.locator('.pay-channel-tone--wechat')).toHaveCSS('color', 'rgb(7, 193, 96)');
    // 无 payment_type 的通用渠道不染品牌色（无 tone class）
    expect(await page.locator('.pay-method .pay-channel-tone--alipay').count()).toBe(1);
    expect(await page.locator('.pay-method .pay-channel-tone--wechat').count()).toBe(1);
  });

  test('移动端支付方式卡片图标渲染官方品牌色', async ({ page }) => {
    await mockRechargeApi(page);
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto('/client/recharge', { waitUntil: 'domcontentloaded' });

    const alipayCard = page.locator('.pay-method-card--mobile', { hasText: '支付宝' });
    const wechatCard = page.locator('.pay-method-card--mobile', { hasText: '微信支付' });
    await expect(alipayCard).toBeVisible();
    await expect(wechatCard).toBeVisible();

    await expect(alipayCard.locator('.pay-channel-tone--alipay')).toHaveCSS('color', 'rgb(22, 119, 255)');
    await expect(wechatCard.locator('.pay-channel-tone--wechat')).toHaveCSS('color', 'rgb(7, 193, 96)');
  });
});
