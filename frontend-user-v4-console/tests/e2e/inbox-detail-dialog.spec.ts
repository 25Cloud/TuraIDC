import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

import type { InboxItem } from '@/domains/content/useInbox';

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

const MESSAGE_ITEM: InboxItem = {
  id: 'msg-3',
  raw_id: 3,
  source: 'message',
  type: 'order_paid',
  type_label: '订购成功',
  title: '订单支付成功',
  summary: '您的订单 ORD20261007001 已支付成功，服务正在开通中。\n如有疑问请联系客服。',
  link: null,
  read: false,
  created_at: '2026-10-07 09:00:00',
};

const NOTICE_ITEM: InboxItem = {
  id: 'notice-7',
  source: 'notice',
  type: 'notice',
  type_label: '系统公告',
  title: '机房维护公告',
  summary: '维护摘要',
  link: '/client/notices/7',
  read: false,
  created_at: '2026-10-06 18:00:00',
};

const NOTICE_DETAIL = {
  id: 7,
  title: '机房维护公告',
  content: '## 维护窗口\n\n**10 月 8 日 02:00-04:00** 对部分宿主机进行固件升级，期间网络抖动。',
  summary: '维护摘要',
};

async function mockInboxApi(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'inbox-dialog-test-token');
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
    if (url.pathname.endsWith('/notifications/unread-count')) {
      return respond({ count: 2 });
    }
    if (url.pathname.endsWith('/notifications/feed')) {
      return respond({ list: [MESSAGE_ITEM, NOTICE_ITEM], unread_count: 2 });
    }
    if (url.pathname.endsWith('/notifications/3/read-state')) {
      return respond({});
    }
    if (url.pathname.endsWith('/notices/7/read-state')) {
      return respond({});
    }
    if (url.pathname.endsWith('/notices/7') && method === 'GET') {
      return respond(NOTICE_DETAIL);
    }

    return respond({});
  });

  // 站点品牌配置不在 client 前缀下，单独拦截避免测试请求真实接口
  const respondSite = (route: Route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
  await page.route('**/api/v2/site/**', respondSite);
}

async function openInboxPanel(page: Page) {
  await page.goto('/client/dashboard', { waitUntil: 'domcontentloaded' });
  await page.locator('button[title="站内信"]').click();
  await expect(page.locator('.inbox-panel')).toBeVisible();
  await expect(page.locator('.inbox-item')).toHaveCount(2);
}

test.describe('站内信点击弹窗展示全文', () => {
  test('个性化消息弹窗展示纯文本全文，无跳转链接时不出现查看详情', async ({ page }) => {
    await mockInboxApi(page);
    await openInboxPanel(page);

    await page.locator('.inbox-item', { hasText: '订单支付成功' }).click();

    const dialog = page.locator('.t-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.t-dialog__header')).toHaveText('订单支付成功');
    await expect(dialog.locator('.inbox-detail__content--text')).toContainText('ORD20261007001');
    // 纯文本换行应保留
    const content = dialog.locator('.inbox-detail__content--text');
    await expect(content).toContainText('如有疑问请联系客服');
    await expect(dialog.getByRole('button', { name: '查看详情' })).toHaveCount(0);
  });

  test('公告弹窗拉取详情并渲染 markdown 全文，出现查看详情按钮', async ({ page }) => {
    await mockInboxApi(page);
    await openInboxPanel(page);

    await page.locator('.inbox-item', { hasText: '机房维护公告' }).click();

    const dialog = page.locator('.t-dialog');
    await expect(dialog).toBeVisible();
    // 全文来自详情接口，而非列表摘要
    await expect(dialog.locator('.inbox-detail__content')).toContainText('维护窗口');
    await expect(dialog.locator('.inbox-detail__content')).toContainText('10 月 8 日 02:00-04:00');
    await expect(dialog.getByRole('button', { name: '查看详情' })).toBeVisible();
  });
});
