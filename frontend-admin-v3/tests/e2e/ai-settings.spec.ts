import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 管理端「AI 设置」页的版式、生效值与提交回归。
 *
 * 背景：这一页此前用手写 flex 拼字段，标签与输入框错位、间距不统一，
 * 而且默认值是一堆 0 / 空串，站长必须逐项调才能用。现在改成
 * t-form（label-width 180px）+ 七张分组卡片，初值直接取接口回读的生效值。
 *
 * 这里锚住三件事：
 *  1) 七个分区都在，且接口给的生效值真的落到控件上（不是空白或 0）；
 *  2) 保存时启用工具按数组提交，密钥留空就不提交 api_key；
 *  3) 连通性测试把后端给的中文结论、模型名与延迟显示在底部操作区。
 */

const SECTION_TITLES = ['开关与协议', '网关与模型', '入口开关', '额度与熔断', '工具与轮次', '会话与留存', '权限模式'];

const EFFECTIVE_SETTINGS = {
  enabled: 1,
  protocol: 'chat_completions',
  base_url: 'https://api.deepseek.com',
  model: 'deepseek-chat',
  temperature: 0.3,
  max_tokens: 1200,
  console_enabled: 1,
  site_enabled: 1,
  admin_enabled: 1,
  allow_balance_pay: 0,
  user_daily_messages: 60,
  user_daily_tokens: 120000,
  global_daily_tokens: 3000000,
  global_daily_cost_cents: 5000,
  cost_per_1k_tokens_cents: 1,
  max_tool_rounds: 6,
  request_timeout_seconds: 60,
  history_message_limit: 12,
  redact_pii: 1,
  retention_days: 30,
  default_permission_mode: 'confirm',
  max_permission_mode: 'auto_approve',
  api_key: { has_value: true, masked_value: '******' },
  protocols: [
    { key: 'chat_completions', label: 'OpenAI 对话补全（/v1/chat/completions）' },
    { key: 'responses', label: 'OpenAI Responses（/v1/responses）' },
  ],
  permission_modes: [
    { key: 'read_only', label: '仅可查看' },
    { key: 'confirm', label: '完全权限（写操作需确认）' },
    { key: 'auto_approve', label: '自动审批（beta）' },
  ],
  tools: [
    { name: 'users.search', risk: 'read', description: '按邮箱或手机号查用户' },
    { name: 'orders.refund', risk: 'dangerous', description: '给订单退款' },
  ],
  enabled_tools: ['users.search'],
  protocol_label: 'OpenAI 对话补全（/v1/chat/completions）',
};

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'ai-settings-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page, state: { saved: unknown[]; tested: number }) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const reply = (data: unknown, message = '操作成功') =>
      route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ code: 0, message, data }),
      });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'ai-check', nickname: 'ai-check', permissions: ['*'] },
        // AI 页面依赖 addons/ai_assistant 插件：插件未启用时守卫会把 /admin/ai/* 退回首页
        features: { ai_assistant: true },
      });
    }

    if (pathname.endsWith('/ai/settings/test')) {
      state.tested += 1;
      return reply(
        {
          ok: true,
          latency_ms: 320,
          protocol: 'chat_completions',
          model: 'deepseek-chat',
          reply: '连接正常',
        },
        '连通正常',
      );
    }

    if (pathname.endsWith('/ai/settings') && request.method() === 'PUT') {
      state.saved.push(request.postDataJSON());
      return reply({ saved: true }, 'AI 设置已保存');
    }

    if (pathname.endsWith('/ai/settings')) {
      return reply(EFFECTIVE_SETTINGS);
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('AI 设置页按七个分区渲染生效值，并按数组提交工具白名单', async ({ page }) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  const state = { saved: [] as unknown[], tested: 0 };
  await seedAdminSession(page);
  await mockAdminApi(page, state);

  await page.goto('/admin/ai/settings', { waitUntil: 'domcontentloaded' });

  for (const title of SECTION_TITLES) {
    await expect(page.getByText(title, { exact: true }).first()).toBeVisible({ timeout: 15000 });
  }

  // 生效值要落到控件上：温度 0.3、单次最大 Token 1200、模型名与网关地址
  // 字段排版已统一成 admin-field（标签/说明/控件三行），不再是 t-form-item
  const fieldItem = (label: string) =>
    page
      .locator('.admin-field')
      .filter({ has: page.locator('.admin-field__label', { hasText: label }) })
      .first();

  await expect(fieldItem('温度').locator('input').first()).toHaveValue('0.3');
  await expect(fieldItem('单次最大 Token').locator('input').first()).toHaveValue('1200');
  await expect(fieldItem('模型名称').locator('input').first()).toHaveValue('deepseek-chat');
  await expect(fieldItem('接口地址').locator('input').first()).toHaveValue('https://api.deepseek.com');

  // 保存：工具白名单按数组提交，密钥留空则不带 api_key
  await page.getByRole('button', { name: '保存' }).click();
  await expect.poll(() => state.saved.length, { timeout: 10000 }).toBe(1);

  expect(state.saved[0]).toMatchObject({
    enabled: true,
    protocol: 'chat_completions',
    base_url: 'https://api.deepseek.com',
    model: 'deepseek-chat',
    temperature: 0.3,
    max_tokens: 1200,
    enabled_tools: ['users.search'],
    max_permission_mode: 'auto_approve',
  });
  expect(state.saved[0]).not.toHaveProperty('api_key');

  // 连通性测试：底部操作区显示后端中文结论 + 模型名 + 延迟
  await page.getByRole('button', { name: '连通性测试' }).click();
  await expect.poll(() => state.tested, { timeout: 30000 }).toBe(1);

  const actionBar = page.locator('.ai-settings-actions');
  await expect(actionBar.getByText(/连通正常/)).toBeVisible();
  await expect(actionBar.getByText(/模型 deepseek-chat/)).toBeVisible();
  await expect(actionBar.getByText(/延迟 320ms/)).toBeVisible();

  expect(pageErrors, `渲染期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);

  // 视觉快照落临时目录，便于人工比对三档视口下的分组排版
  await page.screenshot({
    path: `${process.env.TEMP || '.'}\\turaidc-admin-ai-settings-${test.info().project.name}.png`,
    fullPage: true,
  });
});
