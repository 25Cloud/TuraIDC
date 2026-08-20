import { expect, test } from '@playwright/test';

// =====================================================================
// 状态主题归一化回归测试（回应 PR #15 CodeRabbit 评审）
//
// 覆盖点：
//  1) record-detail-page.normalizeThemeType()：外部未知 statusTheme
//     （info/空值等）一律回退 t-tag 的 default 主题；受支持主题
//     （primary/success/warning/danger）原样直通。
//  2) InvoiceDetailDrawer.paymentStatusTheme()：支付状态只输出允许主题。
//  3) 订单详情页订单 / 账单 / 支付状态主题。
//  4) 充值管理页账单状态主题（recharges 列表 StatusTag 与抽屉）。
//  5) 通知中心邮件 / 短信模板「测试发送」收件人输入随渠道切换并可提交。
//  6) 推广返利奖励（purple→primary 映射）与提现状态主题。
//
// 全部通过 page.route Mock 后端，不依赖真实 API。
// =====================================================================

// TDesign t-tag 的 theme class：default/primary/success/warning/danger，
// 无 info；light 变体为 t-tag--light。断言均以 theme class + 文案为准。

async function mockAdminInfo(page: import('@playwright/test').Page) {
  await page.route(/\/api\/v2\/admin\/auth\/info(?:\?.*)?$/, async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          admin: {
            id: 1,
            username: 'cerbo',
            nickname: 'cerbo',
            email: 'admin@example.com',
            permissions: ['*'],
          },
        },
      }),
    });
  });
}

/** 预置 admin 会话，避免路由守卫跳转登录页。 */
async function seedAdminSession(page: import('@playwright/test').Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'status-theme-regression-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

/** 未被具体 mock 覆盖的 /api/v2/admin 请求统一回空数据，避免污染控制台。 */
async function mockRemainingAdminApi(page: import('@playwright/test').Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data: {} }) });
  });
}

// ─────────────────────────── 订单详情 ───────────────────────────

interface OrderStatusCase {
  id: number;
  status: number;
  label: string;
  themeClass: string;
}

// ORDER_STATUS_MAP：0 待付款 warning；1 已付款 ''；2 开通中 ''；
// 3 已完成 success；4 已取消 info；5 已退款 danger。
const ORDER_STATUS_CASES: OrderStatusCase[] = [
  { id: 800, status: 0, label: '待付款', themeClass: 't-tag--warning' },
  { id: 801, status: 1, label: '已付款', themeClass: 't-tag--default' },
  { id: 802, status: 2, label: '开通中', themeClass: 't-tag--default' },
  { id: 803, status: 3, label: '已完成', themeClass: 't-tag--success' },
  { id: 804, status: 4, label: '已取消', themeClass: 't-tag--default' },
  { id: 805, status: 5, label: '已退款', themeClass: 't-tag--danger' },
];

const PAYMENT_SAMPLES: Record<string, unknown>[] = [
  // 状态 3 已退款 → tagType info → 归一化为 default
  {
    id: 1,
    payment_no: 'PAY-ORD-001',
    status: 3,
    gateway: 'alipay',
    trade_no: 'T-1',
    amount: '10.00',
    paid_at: '2026-06-06 10:02:00',
  },
  // 状态 0 待支付 → warning
  { id: 2, payment_no: 'PAY-ORD-002', status: 0, gateway: 'alipay', trade_no: 'T-2', amount: '88.00' },
  // 状态 2 失败 → danger
  { id: 3, payment_no: 'PAY-ORD-003', status: 2, gateway: 'wechat', trade_no: 'T-3', amount: '30.50' },
];

/** 按 URL 中的订单 id 返回对应状态的订单详情（老版 detail 结构，由前端 normalize 展平）。 */
async function mockOrderDetailStatuses(page: import('@playwright/test').Page) {
  await page.route(/\/api\/v2\/admin\/orders\/\d+(?:\?.*)?$/, async (route) => {
    const id = Number(new URL(route.request().url()).pathname.split('/').pop());
    const statusCase = ORDER_STATUS_CASES.find((item) => item.id === id) || ORDER_STATUS_CASES[3];
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          order: {
            id,
            basic: {
              order_no: `ORD-DETAIL-${id}`,
              type: 'new',
              type_label: '新购',
              status: statusCase.status,
              status_label: statusCase.label,
              billing_cycle: 'monthly',
              quantity: 1,
              remark: '',
            },
            financial: { amount: '128.50', discount: '0.00', paid_amount: '128.50', paid_at: '2026-06-06 10:02:00' },
            user: { id: 1, email: '2908990438@qq.com', nickname: '测试用户', phone: '' },
            invoice: { id: 900, invoice_no: 'INV-DETAIL-001', status: 1, amount: '128.50', paid_amount: '128.50' },
            product: { id: 10, name: '标准云服务器', full_path: '云产品 / 标准云服务器', type: 'vps' },
            service: { id: 11, name: 'vm-001', status: 1, expires_at: '2026-07-06 10:00:00' },
            coupon: null,
            configuration: { config_snapshot: {}, config_pricing_snapshot: {}, service_snapshot: {} },
            payment_chain: { payments: PAYMENT_SAMPLES },
            audit: { trace_id: `trace-order-${id}` },
            timestamps: { created_at: '2026-06-06 10:00:00', updated_at: '2026-06-06 10:02:00' },
          },
        },
      }),
    });
  });
}

/** 账单详情（INVOICE_STATUS_MAP：1 已支付 success；支付记录状态 3/0/2）。 */
async function mockInvoiceDetail(page: import('@playwright/test').Page) {
  await page.route(/\/api\/v2\/admin\/invoices\/\d+(?:\?.*)?$/, async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          invoice: {
            id: 900,
            basic: {
              invoice_no: 'INV-DETAIL-001',
              type: 'new',
              type_label: '新购',
              status: 1,
              status_label: '已支付',
              billing_cycle: 'monthly',
              quantity: 1,
            },
            financial: {
              amount: '128.50',
              paid_amount: '128.50',
              payable_amount: '128.50',
              paid_at: '2026-06-06 10:02:00',
            },
            display: { product_spec_display: '标准云服务器 2C4G', combined_display_name: '标准云服务器 2C4G' },
            order: { id: 800, order_no: 'ORD-DETAIL-800' },
            user: { id: 1, nickname: '测试用户', email: '2908990438@qq.com' },
            scene: {},
            configuration: {},
            payment_chain: { payments: PAYMENT_SAMPLES, payment_summary: {} },
            audit: { trace_id: 'trace-inv-001', refund_trace_id: null },
            actions: { can_cancel: false },
            timestamps: { created_at: '2026-06-06 10:00:00', updated_at: '2026-06-06 10:02:00' },
            items: [],
            logs: [],
          },
        },
      }),
    });
  });
}

// ─────────────────────────── 充值管理 ───────────────────────────

async function mockRecharges(page: import('@playwright/test').Page) {
  const rechargeRows: Record<string, unknown>[] = [
    {
      id: 910,
      payment_no: 'PAY-RECHARGE-001',
      invoice_no: 'RC-20260606-001',
      invoice_id: 910,
      gateway: 'alipay',
      trade_no: 'TRADE-001',
      amount: 200,
      paid_amount: 200,
      status: 1,
      created_at: '2026-06-06 10:00:00',
      paid_at: '2026-06-06 10:02:00',
      user: { id: 1, nickname: '测试用户', email: '2908990438@qq.com' },
      payment: { id: 1, payment_no: 'PAY-RECHARGE-001', gateway: 'alipay', trade_no: 'TRADE-001' },
    },
    {
      id: 911,
      payment_no: 'PAY-RECHARGE-REFUND',
      invoice_no: 'RC-20260606-002',
      invoice_id: 911,
      gateway: 'alipay',
      trade_no: 'TRADE-002',
      amount: 300,
      paid_amount: 300,
      status: 3,
      created_at: '2026-06-06 11:00:00',
      paid_at: '2026-06-06 11:02:00',
      user: { id: 1, nickname: '测试用户', email: '2908990438@qq.com' },
      payment: { id: 2, payment_no: 'PAY-RECHARGE-REFUND', gateway: 'alipay', trade_no: 'TRADE-002' },
    },
    {
      id: 912,
      payment_no: 'PAY-RECHARGE-FAIL',
      invoice_no: 'RC-20260606-003',
      invoice_id: 912,
      gateway: 'wechat',
      trade_no: 'TRADE-003',
      amount: 88,
      paid_amount: 0,
      status: 2,
      created_at: '2026-06-06 12:00:00',
      paid_at: null,
      user: { id: 1, nickname: '测试用户', email: '2908990438@qq.com' },
      payment: { id: 3, payment_no: 'PAY-RECHARGE-FAIL', gateway: 'wechat', trade_no: 'TRADE-003' },
    },
  ];

  await page.route('**/api/v2/admin/finance/recharges**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: { list: rechargeRows, total: rechargeRows.length, page: 1, page_size: 20 },
      }),
    });
  });

  // 充值账单详情：账单状态 3（已退款 → default），支付记录 0/2/1。
  await page.route(/\/api\/v2\/admin\/invoices\/\d+(?:\?.*)?$/, async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          invoice: {
            id: 911,
            basic: {
              invoice_no: 'RC-20260606-002',
              type: 'recharge',
              type_label: '充值',
              status: 3,
              status_label: '已退款',
              billing_cycle: 'monthly',
              quantity: 1,
            },
            financial: {
              amount: '300.00',
              paid_amount: '300.00',
              payable_amount: '0.00',
              paid_at: '2026-06-06 11:02:00',
            },
            display: { product_spec_display: '账户充值', combined_display_name: '账户充值' },
            user: { id: 1, nickname: '测试用户', email: '2908990438@qq.com' },
            scene: {},
            configuration: {},
            payment_chain: {
              payments: [
                {
                  id: 11,
                  payment_no: 'PAY-RC-001',
                  status: 0,
                  gateway: 'alipay',
                  trade_no: 'RC-T-1',
                  amount: '300.00',
                },
                {
                  id: 12,
                  payment_no: 'PAY-RC-002',
                  status: 2,
                  gateway: 'alipay',
                  trade_no: 'RC-T-2',
                  amount: '300.00',
                },
                {
                  id: 13,
                  payment_no: 'PAY-RC-003',
                  status: 1,
                  gateway: 'alipay',
                  trade_no: 'RC-T-3',
                  amount: '300.00',
                  paid_at: '2026-06-06 11:02:00',
                },
              ],
              payment_summary: {},
            },
            audit: { trace_id: 'trace-rc-911', refund_trace_id: null },
            actions: { can_cancel: false },
            timestamps: { created_at: '2026-06-06 11:00:00', updated_at: '2026-06-06 11:02:00' },
            items: [],
            logs: [],
          },
        },
      }),
    });
  });
}

// ─────────────────────────── 推广返利 ───────────────────────────

async function mockReferralStatuses(page: import('@playwright/test').Page) {
  const rewards: Record<string, unknown>[] = [
    {
      id: 701,
      referrer: { id: 21, display_name: '推广用户', email: 'referrer@example.test' },
      referred_user: { id: 22, display_name: '新客户', email: 'new@example.test' },
      order: { order_no: 'ORD-REF-001', product_spec_display: '标准云服务器 2C4G' },
      product: { display_name: '标准云服务器' },
      order_amount: 200,
      reward_rate: 8,
      reward_amount: 16,
      status: 0, // 冻结中 → tagType purple → 归一化为 primary
      rewarded_at: '2026-06-06 10:00:00',
      available_at: '2026-06-13 10:00:00',
      released_at: null,
      remark: '冻结中奖励',
    },
    {
      id: 702,
      referrer: { id: 21, display_name: '推广用户', email: 'referrer@example.test' },
      referred_user: { id: 22, display_name: '新客户', email: 'new@example.test' },
      order: { order_no: 'ORD-REF-002', product_spec_display: '标准云服务器 4C8G' },
      product: { display_name: '标准云服务器' },
      order_amount: 400,
      reward_rate: 8,
      reward_amount: 32,
      status: 1, // 已释放 → success
      rewarded_at: '2026-06-06 10:00:00',
      available_at: '2026-06-13 10:00:00',
      released_at: '2026-06-14 10:00:00',
      remark: '已释放奖励',
    },
    {
      id: 703,
      referrer: { id: 21, display_name: '推广用户', email: 'referrer@example.test' },
      referred_user: { id: 22, display_name: '新客户', email: 'new@example.test' },
      order: { order_no: 'ORD-REF-003', product_spec_display: '标准云服务器 2C4G' },
      product: { display_name: '标准云服务器' },
      order_amount: 200,
      reward_rate: 8,
      reward_amount: 16,
      status: 2, // 已回退 → tagType info → 归一化为 default
      rewarded_at: '2026-06-06 10:00:00',
      available_at: null,
      released_at: null,
      remark: '已回退奖励',
    },
  ];

  const withdrawals: Record<string, unknown>[] = [
    {
      id: 801,
      user: { id: 22, display_name: '提现用户', email: 'withdraw@example.test' },
      amount: 88,
      method: 'alipay',
      account_name: '张三',
      account_no: 'alipay@example.test',
      status: 0, // 待审核 → warning
      operator: null,
      remark: null,
      created_at: '2026-06-06 11:00:00',
      processed_at: null,
    },
    {
      id: 802,
      user: { id: 22, display_name: '提现用户', email: 'withdraw@example.test' },
      amount: 66,
      method: 'alipay',
      account_name: '李四',
      account_no: 'alipay@example.test',
      status: 1, // 已通过 → success
      operator: 'cerbo',
      remark: '审核通过',
      created_at: '2026-06-06 12:00:00',
      processed_at: '2026-06-07 11:00:00',
    },
    {
      id: 803,
      user: { id: 22, display_name: '提现用户', email: 'withdraw@example.test' },
      amount: 50,
      method: 'balance',
      account_name: '王五',
      account_no: '余额转回',
      status: 2, // 已拒绝 → danger
      operator: 'cerbo',
      remark: '资料不完整',
      created_at: '2026-06-06 13:00:00',
      processed_at: '2026-06-07 12:00:00',
    },
  ];

  await page.route('**/api/v2/admin/referral**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname.endsWith('/referral/overview')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: {
            summary: { total_sales_amount: 12880, frozen_amount: 320, available_amount: 680, withdrawn_amount: 1200 },
            top_referrers: [],
          },
        }),
      });
      return;
    }
    if (pathname.endsWith('/referral/rewards')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, data: { list: rewards, total: rewards.length, page: 1, page_size: 20 } }),
      });
      return;
    }
    if (pathname.endsWith('/referral-withdrawals')) {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          code: 0,
          data: { list: withdrawals, total: withdrawals.length, page: 1, page_size: 20 },
        }),
      });
      return;
    }
    await route.fallback();
  });
}

// ─────────────────────────── 通知中心 ───────────────────────────

async function mockNotifications(page: import('@playwright/test').Page) {
  const emailTemplates: Record<string, unknown>[] = [
    {
      channel: 'email',
      code: '100001',
      name: '验证码邮件',
      description: '发送邮箱验证码时使用。',
      audience: 'user',
      subject: '验证码邮件',
      content: '<p>验证码 {{code}}</p>',
      provider_template_id: '',
      variables: ['code'],
      provider_variables: [],
      setting_keys: { subject: 'email_template_subject_100001', content: 'email_template_content_100001' },
    },
  ];
  const smsTemplates: Record<string, unknown>[] = [
    {
      channel: 'sms',
      code: '100001',
      name: '发送验证码',
      description: '验证码短信模板。',
      audience: 'user',
      subject: null,
      content: '验证码 {code}',
      provider_template_id: 'SMS_001',
      variables: ['code'],
      provider_variables: ['code'],
      setting_keys: {
        content: 'sms_template_content_100001',
        provider_template_id: 'sms_template_provider_template_id_100001',
      },
    },
  ];

  await page.route(/\/api\/v2\/admin\/notification-templates(?:\?.*)?$/, async (route) => {
    const channel = new URL(route.request().url()).searchParams.get('channel') || 'email';
    const list = channel === 'sms' ? smsTemplates : emailTemplates;
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, data: { list, total: list.length } }),
    });
  });

  await page.route(/\/api\/v2\/admin\/settings(?:\?.*)?$/, async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '保存成功', data: {} }),
      });
      return;
    }
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: { list: [{ key: 'email_template_subject_100001', value: '验证码邮件' }] },
      }),
    });
  });

  // Mock 测试发送提交接口，返回成功结果。
  await page.route('**/api/v2/admin/notification-templates/test-send', async (route) => {
    const payload = (route.request().postDataJSON() || {}) as Record<string, unknown>;
    const channel = String(payload.channel || 'email');
    const recipient = String(payload.recipient || '');
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          channel,
          code: payload.code,
          template_name: channel === 'sms' ? '发送验证码' : '验证码邮件',
          status: 'success',
          total: 1,
          success_count: 1,
          failed_count: 0,
          results: [{ recipient, status: 'success' }],
        },
      }),
    });
  });
}

// =====================================================================
// 测试用例
// =====================================================================

test('订单详情：订单状态主题归一化（info/空值回退 default，支持主题直通）', async ({ page }) => {
  await mockAdminInfo(page);
  await mockOrderDetailStatuses(page);
  await mockRemainingAdminApi(page);
  await seedAdminSession(page);

  for (const statusCase of ORDER_STATUS_CASES) {
    await page.goto(`/admin/finance/orders/${statusCase.id}`, { waitUntil: 'domcontentloaded' });

    // 详情摘要区的状态标签（record-detail-page normalizeThemeType 归一化后渲染）
    const summaryTag = page.locator('.record-detail-summary__status .t-tag');
    await expect(summaryTag).toContainText(statusCase.label);
    await expect(summaryTag).toHaveClass(new RegExp(statusCase.themeClass));

    // 基本信息 tab 内「状态」行标签（orderStatusTheme 归一化后渲染）
    const basicStatusTag = page.locator('.detail-kv-item', { hasText: '状态' }).locator('.t-tag');
    await expect(basicStatusTag).toContainText(statusCase.label);
    await expect(basicStatusTag).toHaveClass(new RegExp(statusCase.themeClass));
  }
});

test('订单详情：账单详情抽屉与支付记录状态主题', async ({ page }) => {
  await mockAdminInfo(page);
  await mockOrderDetailStatuses(page);
  await mockInvoiceDetail(page);
  await mockRemainingAdminApi(page);
  await seedAdminSession(page);

  await page.goto('/admin/finance/orders/800', { waitUntil: 'domcontentloaded' });

  // 订单状态（status 0 待付款 → warning）
  await expect(page.locator('.record-detail-summary__status .t-tag')).toContainText('待付款');
  await expect(page.locator('.record-detail-summary__status .t-tag')).toHaveClass(/t-tag--warning/);

  // 订单自身支付记录 tab（paymentStatusTheme：3 已退款→default / 0→warning / 2→danger）
  await page.locator('.t-tabs__nav-item', { hasText: '支付记录' }).click();
  await expect(page.locator('.payment-item', { hasText: 'PAY-ORD-001' }).locator('.t-tag')).toHaveClass(
    /t-tag--default/,
  );
  await expect(page.locator('.payment-item', { hasText: 'PAY-ORD-002' }).locator('.t-tag')).toHaveClass(
    /t-tag--warning/,
  );
  await expect(page.locator('.payment-item', { hasText: 'PAY-ORD-003' }).locator('.t-tag')).toHaveClass(
    /t-tag--danger/,
  );

  // 打开账单详情抽屉（InvoiceDetailDrawer）
  await page.getByRole('button', { name: '查看账单详情' }).click();
  const drawer = page.locator('.t-drawer:visible');
  await expect(drawer.getByText('账单详情').first()).toBeVisible();

  // 账单状态（INVOICE_STATUS_MAP：1 已支付 → success）
  await expect(drawer.locator('.record-detail-summary__status .t-tag')).toContainText('已支付');
  await expect(drawer.locator('.record-detail-summary__status .t-tag')).toHaveClass(/t-tag--success/);

  // 抽屉支付记录 tab（InvoiceDetailDrawer.paymentStatusTheme）
  await drawer.locator('.t-tabs__nav-item', { hasText: '支付记录' }).click();
  await expect(drawer.locator('.finance-line-item', { hasText: 'PAY-ORD-001' }).locator('.t-tag')).toHaveClass(
    /t-tag--default/,
  );
  await expect(drawer.locator('.finance-line-item', { hasText: 'PAY-ORD-002' }).locator('.t-tag')).toHaveClass(
    /t-tag--warning/,
  );
  await expect(drawer.locator('.finance-line-item', { hasText: 'PAY-ORD-003' }).locator('.t-tag')).toHaveClass(
    /t-tag--danger/,
  );
});

test('充值管理：列表与账单抽屉状态主题', async ({ page }) => {
  await mockAdminInfo(page);
  await mockRecharges(page);
  await mockRemainingAdminApi(page);
  await seedAdminSession(page);

  await page.goto('/admin/finance/recharges', { waitUntil: 'domcontentloaded' });

  // 列表 StatusTag：1 成功 → success；2 失败 → danger；3 已退款 → info 回退 default
  await expect(page.locator('.t-tag--success', { hasText: '成功' })).toBeVisible();
  await expect(page.locator('.t-tag--danger', { hasText: '失败' })).toBeVisible();
  await expect(page.locator('.t-tag--default', { hasText: '已退款' })).toBeVisible();

  // 打开「已退款」行的账单详情
  const isMobileViewport = () => (page.viewportSize()?.width || 1440) <= 768;
  if (isMobileViewport()) {
    await page
      .locator('.mobile-record-card')
      .filter({ hasText: 'PAY-RECHARGE-REFUND' })
      .locator('.mobile-record-card__more')
      .click();
    await page.locator('.t-dropdown__item', { hasText: '详情' }).click();
  } else {
    await page
      .locator('.t-table__body tr')
      .filter({ hasText: 'PAY-RECHARGE-REFUND' })
      .getByRole('button', { name: '详情' })
      .click();
  }

  const drawer = page.locator('.t-drawer:visible');
  await expect(drawer.getByText('账单详情').first()).toBeVisible();

  // 账单状态（recharges 页 invoiceStatusTheme 基于 PAYMENT_STATUS_MAP：3 → default）
  await expect(drawer.locator('.record-detail-summary__status .t-tag')).toContainText('已退款');
  await expect(drawer.locator('.record-detail-summary__status .t-tag')).toHaveClass(/t-tag--default/);

  // 抽屉支付记录（InvoiceDetailDrawer.paymentStatusTheme：0→warning / 2→danger / 1→success）
  await drawer.locator('.t-tabs__nav-item', { hasText: '支付记录' }).click();
  await expect(drawer.locator('.finance-line-item', { hasText: 'PAY-RC-001' }).locator('.t-tag')).toHaveClass(
    /t-tag--warning/,
  );
  await expect(drawer.locator('.finance-line-item', { hasText: 'PAY-RC-002' }).locator('.t-tag')).toHaveClass(
    /t-tag--danger/,
  );
  await expect(drawer.locator('.finance-line-item', { hasText: 'PAY-RC-003' }).locator('.t-tag')).toHaveClass(
    /t-tag--success/,
  );
});

test('推广返利：奖励与提现状态主题', async ({ page }) => {
  await mockAdminInfo(page);
  await mockReferralStatuses(page);
  await mockRemainingAdminApi(page);
  await seedAdminSession(page);

  // 奖励记录：purple→primary 映射 / success 直通 / info 回退 default
  await page.goto('/admin/referral/rewards', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.t-tag--primary', { hasText: '冻结中' })).toBeVisible();
  await expect(page.locator('.t-tag--success', { hasText: '已释放' })).toBeVisible();
  await expect(page.locator('.t-tag--default', { hasText: '已回退' })).toBeVisible();

  // 提现记录：待审核→warning / 已通过→success / 已拒绝→danger
  await page.goto('/admin/referral/withdrawals', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.t-tag--warning', { hasText: '待审核' })).toBeVisible();
  await expect(page.locator('.t-tag--success', { hasText: '已通过' })).toBeVisible();
  await expect(page.locator('.t-tag--danger', { hasText: '已拒绝' })).toBeVisible();
});

test('通知中心：邮件/短信模板测试发送收件人输入随渠道切换并可提交', async ({ page }) => {
  await mockAdminInfo(page);
  await mockNotifications(page);
  await mockRemainingAdminApi(page);
  await seedAdminSession(page);

  // ── 邮件模板 → 收件人为邮箱输入 ──
  await page.goto('/admin/notifications', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: '测试发送' }).first().click();

  const emailDialog = page.locator('.t-dialog:visible');
  await expect(emailDialog.getByText('测试发送邮件')).toBeVisible();
  await expect(emailDialog.getByText('接收邮箱地址')).toBeVisible();
  const emailRecipientInput = emailDialog.getByPlaceholder('请输入接收邮箱，例如：tester@example.com');
  await expect(emailRecipientInput).toHaveAttribute('type', 'text');
  await emailRecipientInput.fill('tester@example.com');

  const emailSendRequest = page.waitForRequest('**/api/v2/admin/notification-templates/test-send');
  await emailDialog.getByRole('button', { name: '确认发送' }).click();
  await expect((await emailSendRequest).postDataJSON()).toMatchObject({
    channel: 'email',
    code: '100001',
    recipient: 'tester@example.com',
  });
  await expect(emailDialog.locator('.template-test-feedback--success')).toContainText('测试发送成功');

  // 关闭弹窗后再切换短信模板
  await page.keyboard.press('Escape');
  await expect(page.locator('.t-dialog:visible')).toHaveCount(0);

  // ── 短信模板 → 收件人为手机号输入（tel） ──
  await page.goto('/admin/notifications/sms-templates', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: '测试发送' }).first().click();

  const smsDialog = page.locator('.t-dialog:visible');
  await expect(smsDialog.getByText('测试发送短信')).toBeVisible();
  await expect(smsDialog.getByText('接收手机号')).toBeVisible();
  const smsRecipientInput = smsDialog.getByPlaceholder('请输入接收手机号，例如：13900001234');
  await expect(smsRecipientInput).toHaveAttribute('type', 'tel');
  // 输入含空格/连字符，提交时应被 normalizeRecipient 清洗
  await smsRecipientInput.fill('139 0000-1234');

  const smsSendRequest = page.waitForRequest('**/api/v2/admin/notification-templates/test-send');
  await smsDialog.getByRole('button', { name: '确认发送' }).click();
  await expect((await smsSendRequest).postDataJSON()).toMatchObject({
    channel: 'sms',
    code: '100001',
    recipient: '13900001234',
  });
  await expect(smsDialog.locator('.template-test-feedback--success')).toContainText('测试发送成功');
});
