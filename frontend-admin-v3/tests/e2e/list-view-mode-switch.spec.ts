import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 后台列表页「列表 / 卡片」视图切换回归。
 *
 * 背景与教训：
 *  1. 开关原先是一段「列表式 / 卡片式」文字单选，塞在表格上方一个没有任何样式的 div 里，
 *     既没有边距也不跟筛选栏对齐；现在改成筛选行右侧的两个图标按钮（title/aria-label 给出文字）。
 *  2. 卡片视图原先靠「表格列定义 → 字段: 原始值」硬转，状态、金额、嵌套字段全变成 `--`，
 *     分页还只跟着表格走，切过去像空白页。现在桌面卡片直接复用各页已打磨过的移动端卡片，
 *     只把容器换成自适应网格，分页仍然共用同一套。
 *  3. 工单页此前只有卡片、没有切换入口，本次补齐列表式表格。
 *
 * 这里锁定：切换后两种视图各自渲染、互不残留；卡片容器是网格（不是单列堆叠）。
 */

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'list-view-mode-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

const TICKETS = [
  {
    id: 1201,
    subject: '新加坡节点无法连接',
    status: 'staff_reply',
    priority: 3,
    department: 'support',
    user_id: 88,
    user: { id: 88, nickname: 'alice', email: 'alice@example.com' },
    assignee: { id: 2, nickname: 'ops-a' },
    updated_at: '2026-10-18 09:30:00',
    created_at: '2026-10-18 08:00:00',
  },
  {
    id: 1202,
    subject: '续费账单金额疑问',
    status: 'open',
    priority: 2,
    department: 'billing',
    user_id: 89,
    user: { id: 89, nickname: 'bob', email: 'bob@example.com' },
    assignee: { id: 3, nickname: 'ops-b' },
    updated_at: '2026-10-18 10:10:00',
    created_at: '2026-10-18 09:00:00',
  },
];

const ORDERS = [
  {
    id: 3001,
    order_no: 'ORD202610180001',
    type: 'new',
    type_label: '新购',
    amount: '88.00',
    status: 'paid',
    billing_cycle: 'monthly',
    quantity: 1,
    product_name: '香港精品 2核2G',
    product_full_path: '云服务器 / 香港 / 精品',
    user_id: 88,
    user: { id: 88, nickname: 'alice', email: 'alice@example.com' },
    created_at: '2026-10-18 10:00:00',
  },
  {
    id: 3002,
    order_no: 'ORD202610180002',
    type: 'renew',
    type_label: '续费',
    amount: '176.00',
    status: 'pending',
    billing_cycle: 'monthly',
    quantity: 1,
    product_name: '美国精品 4核4G',
    product_full_path: '云服务器 / 美国 / 精品',
    user_id: 89,
    user: { id: 89, nickname: 'bob', email: 'bob@example.com' },
    created_at: '2026-10-18 11:00:00',
  },
];

const USERS = [
  {
    id: 501,
    phone: '13800000001',
    email: 'user1@example.com',
    display_name: '用户一',
    nickname: 'user1',
    status: 1,
    cash_balance: '120.00',
    verification_status: 2,
    opened_product_count: 2,
    created_at: '2026-10-01 10:00:00',
    agent_group: { name: '金牌代理' },
  },
];

const SERVICES = [
  {
    id: 9001,
    service_id: 9001,
    status: 'active',
    amount: '88.00',
    billing_cycle: 'monthly',
    expires_at: '2026-11-18',
    created_at: '2026-10-18 10:00:00',
    domain: 'demo.example.com',
    product_display_name: '香港精品 2核2G',
    product_full_path: '云服务器 / 香港 / 精品',
    host_ips: ['1.1.1.1'],
    user: { id: 501, nickname: '用户一', email: 'user1@example.com' },
  },
];

async function mockAdminApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'view-mode-check', nickname: 'view-mode-check', permissions: ['*'] },
      });
    }

    if (pathname.endsWith('/tickets')) {
      return reply({ list: TICKETS, total: TICKETS.length, page: 1, page_size: 20 });
    }

    if (pathname.endsWith('/orders')) {
      return reply({ list: ORDERS, total: ORDERS.length, page: 1, page_size: 20 });
    }

    if (pathname.endsWith('/services')) {
      return reply({ list: SERVICES, total: SERVICES.length, page: 1, page_size: 20 });
    }

    if (pathname.endsWith('/users')) {
      return reply({ list: USERS, total: USERS.length, page: 1, page_size: 20 });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test.describe('后台列表页视图切换', () => {
  test('工单：列表式显示表格、卡片式显示卡片', async ({ page }, testInfo) => {
    // 列表页统一的移动断点是 max-width: 768px（含 768），平板竖屏也走卡片、不显示切换入口
    const showsSwitch = testInfo.project.name === 'desktop';

    await seedAdminSession(page);
    await mockAdminApi(page);
    await page.goto('/admin/tickets', { waitUntil: 'domcontentloaded' });

    // 工单默认是卡片式（既有形态）
    await expect(page.locator('.ticket-card').first()).toBeVisible({ timeout: 15000 });

    if (showsSwitch) {
      await page.getByRole('button', { name: '列表式' }).click();
      await expect(page.locator('.table-scroll .t-table')).toBeVisible();
      await expect(page.locator('.ticket-card')).toHaveCount(0);

      await page.getByRole('button', { name: '卡片式' }).click();
      await expect(page.locator('.ticket-card')).toHaveCount(TICKETS.length);
    }

    await page.screenshot({
      path: `${process.env.TEMP || '.'}\\turaidc-admin-tickets-${testInfo.project.name}.png`,
      fullPage: true,
    });
  });

  test('订单：切到卡片式后复用移动卡片并排成网格', async ({ page }, testInfo) => {
    const showsSwitch = testInfo.project.name === 'desktop';

    await seedAdminSession(page);
    await mockAdminApi(page);
    await page.goto('/admin/finance/orders', { waitUntil: 'domcontentloaded' });

    if (!showsSwitch) {
      await expect(page.locator('.mobile-record-card').first()).toBeVisible({ timeout: 15000 });
      return;
    }

    await page.getByRole('button', { name: '卡片式' }).click();

    const cards = page.locator('.order-mobile-stack .mobile-record-card');
    await expect(cards).toHaveCount(ORDERS.length);

    // 桌面卡片是网格布局（不是单列堆叠），且卡片之间有间距
    const stack = page.locator('.order-mobile-stack');
    const style = await stack.evaluate((element) => {
      const computed = window.getComputedStyle(element);
      return { display: computed.display, gap: computed.gap };
    });
    expect(style.display).toBe('grid');
    expect(parseFloat(style.gap)).toBeGreaterThanOrEqual(12);

    await page.screenshot({
      path: `${process.env.TEMP || '.'}\\turaidc-admin-orders-card-${testInfo.project.name}.png`,
      fullPage: true,
    });
  });

  test('服务与用户：切到卡片式后卡片有内容且无运行时错误', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', '桌面端才显示切换入口');

    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(String(error)));

    await seedAdminSession(page);
    await mockAdminApi(page);

    // 服务实例：卡片复用移动端卡片，必须带出服务、用户、到期时间等信息
    await page.goto('/admin/services', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: '卡片式' }).click();

    const serviceCards = page.locator('.service-mobile-stack .mobile-record-card');
    await expect(serviceCards).toHaveCount(SERVICES.length, { timeout: 15000 });
    await expect(serviceCards.first()).toBeVisible();
    await expect(serviceCards.first()).toContainText('云服务器 / 香港 / 精品');
    await expect(serviceCards.first()).toContainText('用户一');
    await expect(serviceCards.first()).toContainText('88.00');

    // 客户管理：卡片是页面自带的移动卡片，同样要有正文
    await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: '卡片式' }).click();

    const userCards = page.locator('.users-mobile-stack .users-mobile-card');
    await expect(userCards).toHaveCount(USERS.length, { timeout: 15000 });
    // 用户页的移动列表在桌面样式里是 display:none，卡片视图必须把它显式放出来
    await expect(userCards.first()).toBeVisible();
    await expect(userCards.first()).toContainText('用户一');
    await expect(userCards.first()).toContainText('120.00');

    expect(pageErrors, `切换卡片式不应抛运行时错误：${pageErrors.join(' | ')}`).toEqual([]);

    await page.screenshot({
      path: `${process.env.TEMP || '.'}\\turaidc-admin-users-card-${testInfo.project.name}.png`,
      fullPage: true,
    });
  });
});
