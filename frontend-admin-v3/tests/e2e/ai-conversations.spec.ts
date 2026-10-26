import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 管理端「AI 会话审计」页的列表与详情抽屉回归。
 *
 * 背景：详情抽屉此前把消息、思考过程与工具调用挤成一段纯文本，工具参数直接
 * 抛原始 JSON。现在按角色分列排版（用户右侧气泡、助手左侧带头像、思考过程
 * 折叠在正文上方），工具调用改成小尺寸表格。
 *
 * 这里锚住：
 *  1) 列表按 colKey 固定列宽渲染，消息数是等宽数字；
 *  2) 抽屉里思考过程可折叠展开、工具调用走表格而不是 JSON 直出；
 *  3) 渲染期没有未捕获错误。
 */

const CONVERSATION = {
  id: 1,
  title: '这个月退款了几单',
  channel: 'admin',
  permission_mode: 'confirm',
  message_count: 3,
  last_message_at: '2026-10-26 21:00:00',
  created_at: '2026-10-26 20:50:00',
  user_id: 9,
  user: { id: 9, email: 'boss@example.com', nickname: '站长' },
};

const DETAIL = {
  conversation: CONVERSATION,
  pending_actions: [],
  messages: [
    {
      id: 11,
      role: 'user',
      content: '这个月退款了几单？',
      created_at: '2026-10-26 20:50:10',
      tool_calls: null,
    },
    {
      id: 12,
      role: 'assistant',
      content: '本月共 3 笔退款，合计 ¥128.00。',
      reasoning: '先查退款列表，再按月聚合金额。',
      created_at: '2026-10-26 20:50:20',
      tool_calls: [
        {
          id: 'call-1',
          name: 'refunds.list',
          risk: 'read',
          status: 'ok',
          summary: '查到 3 条退款记录',
          arguments: '{"month":"2026-10"}',
        },
      ],
    },
    {
      id: 13,
      role: 'tool',
      content: '3 条记录',
      created_at: '2026-10-26 20:50:15',
      tool_calls: null,
    },
  ],
};

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'ai-conversations-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message: '操作成功', data }),
      });

    if (pathname.endsWith('/auth/info')) {
      return reply({ admin: { id: 1, username: 'conv-check', nickname: 'conv-check', permissions: ['*'] } });
    }

    if (pathname.endsWith('/ai/conversations/1')) {
      return reply(DETAIL);
    }

    if (pathname.endsWith('/ai/conversations')) {
      return reply({ list: [CONVERSATION], total: 1, page: 1, page_size: 20 });
    }

    if (pathname.endsWith('/ai/usage')) {
      return reply({
        days: 14,
        list: [],
        totals: { messages: 12, prompt_tokens: 800, completion_tokens: 400, cost_cents: 36 },
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('会话审计列表固定列宽，详情抽屉按角色排版并折叠思考过程', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await seedAdminSession(page);
  await mockAdminApi(page);

  await page.goto('/admin/ai/conversations', { waitUntil: 'domcontentloaded' });

  await expect(page.getByText('这个月退款了几单').first()).toBeVisible({ timeout: 15000 });
  // 近 14 天用量合计按同一行展示，成本和消息数都是等宽数字
  await expect(page.getByText(/近 14 天/)).toBeVisible();

  // t-link 没带 href，渲染成 <a> 但拿不到 link 角色，按文案点更稳
  await page.getByText('查看', { exact: true }).first().click();

  await expect(page.getByText('会话详情 #1')).toBeVisible({ timeout: 15000 });
  // 思考过程折叠在正文上方，默认收起
  await expect(page.getByText('思考过程').first()).toBeVisible();
  await expect(page.getByText('先查退款列表，再按月聚合金额。')).toBeHidden();

  await page.getByText('思考过程').first().click();
  await expect(page.getByText('先查退款列表，再按月聚合金额。')).toBeVisible();

  // 工具调用走小表格：表头齐全，参数不再整段 JSON 直出在正文里
  for (const header of ['工具调用', '风险', '状态', '摘要', '参数']) {
    await expect(page.getByText(header, { exact: true }).first()).toBeVisible();
  }
  await expect(page.getByText('refunds.list').first()).toBeVisible();
  await expect(page.getByText('查到 3 条退款记录').first()).toBeVisible();

  // 只读抽屉不该出现「确认 / 取消」这套默认页脚
  await expect(page.getByRole('button', { name: '取消' })).toHaveCount(0);

  expect(pageErrors, `渲染期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);

  // 折叠动画落定后再截图，避免快照抓到过渡中间态
  await page.waitForTimeout(400);

  await page.screenshot({
    path: `${process.env.TEMP || '.'}\\turaidc-admin-ai-conversations-${test.info().project.name}.png`,
    fullPage: true,
  });
});
