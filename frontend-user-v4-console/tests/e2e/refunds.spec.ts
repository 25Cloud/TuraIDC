import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 控制台「退款中心」。
 *
 * 后端接口由 `addons/auto_refund` 插件提供（契约见
 * docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md），
 * 这里全部走桩，覆盖真会坏的事：入口按功能开关收口、候选按商品标注退款规则并提交、
 * 上游不可用时不许提交、商品没配策略时按钮禁用、提交成功后的通道话术、
 * 「我的申请」的退款方式与上游工单两列。
 */

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

/**
 * `/bootstrap` 的 `policy` 只剩「默认值 + 说明」：
 * 这里故意保留 `days: 7`，页面必须忽略它，不许再写「开通后 7 天内」。
 */
const BOOTSTRAP = {
  enabled: true,
  configured: true,
  open: true,
  table_ready: true,
  console_notice: '退款金额统一退回到账户余额，提交后按每台机器标注的规则处理。',
  policy: {
    mode: 'duration',
    mode_label: '按时长折算',
    days: 7,
    require_first_only: false,
    min_amount: '0.00',
    text: '退款金额以上游核算为准，本站显示的预估金额仅供参考。',
  },
  request_timeout: 15,
  status_options: [] as string[],
  blocked_reason_labels: [] as string[],
};

/** 每个候选商品自带自己的退款策略：插件间对接 · 按时长折算 · 产品首次 */
const CANDIDATE = {
  service_id: 12,
  service_name: '香港-CN2-01',
  product_name: '香港 CN2',
  domain: 'hk01.example.com',
  dedicatedip: '192.0.2.10',
  assignedips: '',
  regdate: '2026-10-20 10:00:00',
  nextduedate: '2026-11-20 10:00:00',
  upstream_product_id: '1001',
  used_days: 12,
  estimated_amount: '45.20',
  estimate_hint: '按已用 12 天 / 30 天折算',
  refundable: true,
  blocked_reasons: [] as string[],
  refund_type: 'bridge',
  refund_type_label: '插件间对接',
  refund_rule: 'duration',
  refund_rule_label: '按时长折算',
  requirement: 'product_first',
  requirement_label: '产品首次',
  window_hours: 0,
  policy_configured: true,
  application: null as Record<string, unknown> | null,
};

const APPLICATION = {
  id: 9001,
  application_no: 'AR20261101A1B2',
  service_id: 12,
  service_name: '香港-CN2-01',
  product_name: '香港 CN2',
  reason: '业务调整',
  estimated_amount: '45.20',
  settled_amount: null as string | null,
  status: 'pending_review',
  status_label: '待审核',
  refund_type: 'bridge',
  refund_type_label: '插件间对接',
  channel: 'bridge',
  channel_label: '上游接口',
  ticket_id: null as number | null,
  upstream_ticket_id: null as string | null,
  upstream_code: 200,
  upstream_msg: '退款申请提交成功',
  trace_id: 'api_abc',
  submitted_at: '2026-11-01 12:00:00',
  created_at: '2026-11-01 12:00:00',
};

type CandidateOverride = Partial<typeof CANDIDATE>;
type ApplicationOverride = Partial<typeof APPLICATION>;

interface MockOptions {
  featureEnabled?: boolean;
  upstreamError?: string;
  candidate?: CandidateOverride;
  applications?: ApplicationOverride[];
  submitResponse?: ApplicationOverride;
  onSubmit?: (body: unknown) => void;
}

async function mockClientApi(page: Page, options: MockOptions = {}) {
  const {
    featureEnabled = true,
    upstreamError = '',
    candidate = {},
    applications = [],
    submitResponse = {},
    onSubmit,
  } = options;

  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'refunds-test-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({
        ...USER_INFO,
        features: { ai_assistant: false, auto_refund: featureEnabled },
      });
    }
    if (url.pathname.endsWith('/auto-refund/bootstrap')) {
      return respond(BOOTSTRAP);
    }
    if (url.pathname.endsWith('/auto-refund/candidates')) {
      return respond({
        items: [{ ...CANDIDATE, ...candidate }],
        upstream_available: upstreamError === '',
        upstream_error: upstreamError === '' ? null : upstreamError,
      });
    }
    if (url.pathname.endsWith('/auto-refund/applications') && method === 'GET') {
      const list = applications.map((item) => ({ ...APPLICATION, ...item }));
      return respond({ list, total: list.length, page: 1, page_size: 15 });
    }
    if (url.pathname.endsWith('/auto-refund/applications') && method === 'POST') {
      onSubmit?.(route.request().postDataJSON());
      return respond({ ...APPLICATION, ...submitResponse });
    }

    return respond({});
  });
}

test('退款中心渲染候选、按商品标注退款规则并在提交时只带 service_id 与 reason', async ({ page }) => {
  const bodies: unknown[] = [];
  await mockClientApi(page, { onSubmit: (body) => bodies.push(body) });

  await page.goto('/client/refunds', { waitUntil: 'domcontentloaded' });

  // 说明卡：标题 + 通用说明 + 「每台机器不同」的口径
  await expect(page.locator('.refund-intro__title')).toHaveText('退款中心');
  await expect(page.locator('.refund-intro__notice')).toContainText('按每台机器标注的规则处理');
  await expect(page.locator('.refund-intro__hint')).toHaveText('每台机器的可退规则不同，以列表标注为准。');
  // 「预估金额以上游为准」的免责声明必须在首屏可见
  await expect(page.locator('.refund-intro')).toContainText('退款金额以上游核算为准');
  // bootstrap 仍下发的 policy.days 必须被忽略，不许出现统一的「开通后 X 天内」
  await expect(page.locator('.refund-intro')).not.toContainText('开通后');

  // 候选行渲染该机器自己的策略与预估金额
  const candidateCard = page.locator('.record-table tr', { hasText: '香港-CN2-01' }).first();
  await expect(candidateCard).toContainText('插件间对接 · 按时长折算 · 产品首次');
  await expect(candidateCard).toContainText('45.20');
  await expect(candidateCard).toContainText('按已用 12 天 / 30 天折算');

  // 提交：弹窗 → 填原因 → 提交申请 → 二次确认（提交后会重复申请，必须再确认一次）
  await candidateCard.getByRole('button', { name: '申请退款' }).click();
  await expect(page.getByText('提交退款申请')).toBeVisible();
  await page.getByPlaceholder('请说明退款原因，例如业务调整、机器长期不可用等').fill('业务调整');
  await page.getByRole('button', { name: '提交申请' }).click();

  const confirmDialog = page.locator('.t-dialog', { hasText: '确认提交退款申请' });
  await expect(confirmDialog).toBeVisible();
  await confirmDialog.getByRole('button', { name: '提交申请' }).click();

  // bridge 通道：上游审核，不是「已退款」
  await expect(page.locator('.t-message')).toContainText('已提交上游审核');
  expect(bodies).toEqual([{ service_id: 12, reason: '业务调整' }]);
});

test('上游不可用时给出提示并禁用申请按钮', async ({ page }) => {
  await mockClientApi(page, { upstreamError: '上游接口超时' });

  await page.goto('/client/refunds', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.refund-block-alert').first()).toContainText('上游暂不可用');
  const applyButton = page
    .locator('.record-table tr', { hasText: '香港-CN2-01' })
    .getByRole('button', { name: '申请退款' });
  await expect(applyButton).toBeDisabled();
});

test('商品未配退款策略时候选行禁用申请并说明原因', async ({ page }) => {
  // policy_configured=false 且后端没给 blocked_reasons：前端必须自己补出「该商品未开放自助退款」
  await mockClientApi(page, {
    candidate: { policy_configured: false, refundable: false, blocked_reasons: [] },
  });

  await page.goto('/client/refunds', { waitUntil: 'domcontentloaded' });

  const candidateCard = page.locator('.record-table tr', { hasText: '香港-CN2-01' }).first();
  await expect(candidateCard).toBeVisible();
  await expect(candidateCard.getByRole('button', { name: '申请退款' })).toBeDisabled();
  await expect(candidateCard).toContainText('该商品未开放自助退款');
});

test('提交成功且类型为自动退款时提示已退款到余额', async ({ page }) => {
  // 自动退款：refund_type=auto、channel=local（local 只表示「仅本站」，两种类型共用），status 直接 settled
  await mockClientApi(page, {
    submitResponse: {
      status: 'settled',
      refund_type: 'auto',
      refund_type_label: '自动退款',
      channel: 'local',
      channel_label: '仅本站',
      settled_amount: '45.20',
    },
  });

  await page.goto('/client/refunds', { waitUntil: 'domcontentloaded' });

  const candidateCard = page.locator('.record-table tr', { hasText: '香港-CN2-01' }).first();
  await candidateCard.getByRole('button', { name: '申请退款' }).click();
  await page.getByPlaceholder('请说明退款原因，例如业务调整、机器长期不可用等').fill('业务调整');
  await page.getByRole('button', { name: '提交申请' }).click();

  const confirmDialog = page.locator('.t-dialog', { hasText: '确认提交退款申请' });
  await confirmDialog.getByRole('button', { name: '提交申请' }).click();

  await expect(page.locator('.t-message')).toContainText('已退款到余额');
});

test('类型是插件间对接但状态已结算时，话术优先说已退款到余额', async ({ page }) => {
  await mockClientApi(page, {
    submitResponse: { status: 'settled', refund_type: 'bridge', channel: 'bridge', settled_amount: '45.20' },
  });

  await page.goto('/client/refunds', { waitUntil: 'domcontentloaded' });

  const candidateCard = page.locator('.record-table tr', { hasText: '香港-CN2-01' }).first();
  await candidateCard.getByRole('button', { name: '申请退款' }).click();
  await page.getByPlaceholder('请说明退款原因，例如业务调整、机器长期不可用等').fill('业务调整');
  await page.getByRole('button', { name: '提交申请' }).click();

  const confirmDialog = page.locator('.t-dialog', { hasText: '确认提交退款申请' });
  await confirmDialog.getByRole('button', { name: '提交申请' }).click();

  await expect(page.locator('.t-message')).toContainText('已退款到余额');
});

test('我的申请显示退款方式（类型 + 实际通道）与上游工单入口', async ({ page }) => {
  await mockClientApi(page, {
    applications: [
      {
        refund_type: 'api_ticket',
        refund_type_label: 'API 工单退款',
        channel: 'api_ticket',
        channel_label: '上游工单',
        ticket_id: 321,
        upstream_ticket_id: null,
      },
    ],
  });

  await page.goto('/client/refunds', { waitUntil: 'domcontentloaded' });

  const applicationRow = page.locator('.record-table tr', { hasText: 'AR20261101A1B2' }).first();
  // 退款方式 = 退款类型（主）+ 实际通道（副）
  await expect(applicationRow.locator('.refund-channel-cell__type')).toHaveText('API 工单退款');
  await expect(applicationRow.locator('.refund-channel-cell__route')).toHaveText('上游工单');
  // 上游工单号拿不到就是 `-`（zjfm 协议只给工单列表页）
  await expect(applicationRow.locator('.refund-ticket-cell__no')).toHaveText('-');

  // 有本站工单时给一个入口，跳到该端既有的工单详情路径
  await applicationRow.getByRole('button', { name: '查看工单' }).click();
  await expect(page).toHaveURL(/\/client\/ticket-conversations\/321/);
});

test('插件未启用时侧栏没有退款中心，直连也会被挡回控制台首页', async ({ page }) => {
  await mockClientApi(page, { featureEnabled: false });

  await page.goto('/client/dashboard', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.t-menu').first()).not.toContainText('退款中心');

  await page.goto('/client/refunds', { waitUntil: 'domcontentloaded' });
  // 守卫把没有该功能的页面退回控制台首页（该端既有习惯）
  await expect(page).toHaveURL(/\/client\/dashboard/);
  await expect(page.locator('.refund-intro__title')).toHaveCount(0);
});
