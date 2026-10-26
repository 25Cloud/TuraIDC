import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 官网 AI 浮窗回归。
 *
 * 用户实测的几条投诉，逐条钉死：
 *  1. 浮球上写着「AI 导购」文字 → 只允许一个圆形机器人图标按钮 + i18n aria-label；
 *  2. 助手回复里的 markdown 没有样式（标题/列表/表格/引用/代码全平掉）→ 落定后必须
 *     走 markdown-it 渲染，且列表的 list-style 被全局重置后要能还原；
 *  3. 首个增量到达前面板像死住 → 必须有「思考中」三点占位；
 *  4. 思考过程不展示 → reasoning 有值时折叠展示，流式展开、结束后收成摘要。
 *
 * 站点接口一律 page.route 接管，跑用例不需要真实后端。
 */

const quota = {
  daily_messages: 50,
  daily_tokens: 0,
  used_messages: 3,
  used_tokens: 0,
  resets_at: null,
};

const bootstrap = {
  code: 0,
  data: {
    enabled: true,
    channels: { console: true, site: true, admin: true },
    permission_mode: "confirm",
    allowed_permission_modes: ["read_only", "confirm", "auto_approve"],
    auto_approve_beta: false,
    model_label: "deepseek-v4.1-flash",
    protocol: "sse",
    quota,
    tools: [],
  },
};

const reasoning = "用户说十来个人访问、预算 50 元/月，先按并发量筛机型，再看年付折扣。";

const answer = [
  "## 推荐：2 核 4G 轻量套餐",
  "",
  "按你的描述，这台就够跑：",
  "",
  "- 2 核 4G：日 PV 3000 以内稳",
  "- 4 核 8G：并发翻倍时再升级",
  "",
  "| 配置 | 月付 |",
  "| --- | --- |",
  "| 2C4G | 50 元 |",
  "",
  "> 建议先按月付跑一周。",
  "",
  "`docker compose up -d` 之后看面板即可，详见 https://turaidc.com/products 。",
].join("\n");

const donePayload = {
  conversation_id: 42,
  message_id: 501,
  content: answer,
  reasoning,
  tool_runs: [{ name: "product.search", risk: "read", status: "done", summary: "命中 3 个套餐" }],
  pending_actions: [
    {
      id: 9,
      tool: "order.create",
      risk: "write",
      title: "创建 2C4G 月付订单",
      description: "将按当前选中配置创建订单并跳转支付。",
      arguments_summary: '{"plan_id":12,"cycle":"monthly"}',
      impact: "立即扣款 50.00 元并开通实例",
      amount_cents: 5000,
      expires_at: new Date(Date.now() + 5 * 60000).toISOString(),
      confirm_token: "e2e-confirm-token",
    },
  ],
  permission_mode: "confirm",
  usage: { prompt_tokens: 10, completion_tokens: 20 },
  quota,
};

const sseBody = [
  ["meta", { conversation_id: 42, message_id: null }],
  ["reasoning", { text: reasoning }],
  ["delta", { text: answer.slice(0, 24) }],
  ["delta", { text: answer.slice(24) }],
  ["done", donePayload],
]
  .map(([event, data]) => `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`)
  .join("");

/** 让浮窗认为用户已登录，并接管 AI 三个接口；streamDelayMs 用来观察等待动画 */
async function mockAiGuide(page: Page, options: { streamDelayMs?: number } = {}) {
  await page.addInitScript(() => {
    window.localStorage.setItem("client_token", "e2e-token");
    window.localStorage.setItem("client_last_active_at", String(Date.now()));
  });

  // 站点会在启动时校验登录态，失败即清 token；这里直接给个假用户
  await page.route("**/v2/client/auth/info**", (route) =>
    route.fulfill({
      contentType: "application/json; charset=utf-8",
      body: JSON.stringify({ code: 0, data: { id: 1, email: "demo@example.com", nickname: "Demo" } }),
    }),
  );

  await page.route("**/v2/client/ai/bootstrap**", (route) =>
    route.fulfill({
      contentType: "application/json; charset=utf-8",
      body: JSON.stringify(bootstrap),
    }),
  );

  await page.route("**/v2/client/ai/messages/stream", async (route) => {
    if (options.streamDelayMs) {
      await new Promise((resolve) => setTimeout(resolve, options.streamDelayMs));
    }
    await route.fulfill({ contentType: "text/event-stream", body: sseBody });
  });
}

const launcher = (page: Page) => page.locator("[data-ai-launcher]");
const panel = (page: Page) => page.locator(".ai-panel");

async function openPanel(page: Page, options: { streamDelayMs?: number } = {}) {
  await mockAiGuide(page, options);
  await page.goto("/");
  await expect(launcher(page)).toBeVisible();
  await launcher(page).click();
  await expect(panel(page)).toBeVisible();
}

test.describe("AI 浮窗", () => {
  test("浮球只有机器人图标，没有文字，并带 i18n aria-label", async ({ page }) => {
    await mockAiGuide(page);
    await page.goto("/");
    await expect(launcher(page)).toBeVisible();

    // 图标是 svg，按钮内不该再有可见文字
    await expect(launcher(page)).toHaveText("");
    await expect(launcher(page)).toHaveAttribute("aria-label", "AI 导购");

    const box = await launcher(page).boundingBox();
    expect(box?.width).toBe(48);
    expect(box?.height).toBe(48);
  });

  test("助手回复按 markdown 渲染：标题/列表/表格/引用/代码/链接都有结构", async ({ page }) => {
    await openPanel(page);

    await page.fill(".ai-panel textarea", "十来人同时访问的小站，预算 50 元/月，选哪个？");
    await page.press(".ai-panel textarea", "Enter");

    const markdown = page.locator(".ai-msg__markdown");
    await expect(markdown).toBeVisible();

    await expect(markdown.locator("h2")).toHaveText("推荐：2 核 4G 轻量套餐");
    await expect(markdown.locator("ul > li")).toHaveCount(2);
    await expect(markdown.locator("table th").first()).toHaveText("配置");
    await expect(markdown.locator("blockquote")).toContainText("先按月付跑一周");
    await expect(markdown.locator("pre code, code").first()).toContainText("docker compose");
    await expect(markdown.locator("a")).toHaveAttribute("target", "_blank");

    // 全局把 ul/ol 的 list-style 清了，markdown 里必须还原，否则只剩缩进
    const listStyle = await markdown
      .locator("ul")
      .first()
      .evaluate((el) => getComputedStyle(el).listStyleType);
    expect(listStyle).toBe("disc");

    // 落定后不应该还留着流式光标
    await expect(page.locator(".ai-msg__caret")).toHaveCount(0);
  });

  test("首个增量到达前显示三点「思考中」占位", async ({ page }) => {
    await openPanel(page, { streamDelayMs: 1500 });

    await page.fill(".ai-panel textarea", "推荐一台 50 元/月的");
    await page.press(".ai-panel textarea", "Enter");

    const thinking = page.locator(".ai-thinking");
    await expect(thinking).toBeVisible();
    await expect(thinking.locator(".ai-thinking__dot")).toHaveCount(3);
    await expect(thinking).toContainText("思考中");

    // 增量到达后占位消失
    await expect(page.locator(".ai-msg__markdown")).toBeVisible();
    await expect(thinking).toHaveCount(0);
  });

  test("思考过程可折叠：结束后收起成「已深度思考」，点击可展开", async ({ page }) => {
    await openPanel(page);

    await page.fill(".ai-panel textarea", "预算 50 元/月怎么选");
    await page.press(".ai-panel textarea", "Enter");
    await expect(page.locator(".ai-msg__markdown")).toBeVisible();

    const reason = page.locator(".ai-reason");
    await expect(reason).toBeVisible();
    await expect(page.locator(".ai-reason__title")).toHaveText("已深度思考");

    const body = page.locator(".ai-reason__body");
    await expect(body).toBeHidden();

    await page.locator(".ai-reason__head").click();
    await expect(body).toBeVisible();
    await expect(body).toContainText("先按并发量筛机型");
    await expect(page.locator(".ai-reason__head")).toHaveAttribute("aria-expanded", "true");
  });

  test("工具调用是一行小胶囊，桌面面板 400px", async ({ page }) => {
    await openPanel(page);

    await page.fill(".ai-panel textarea", "帮我看看套餐");
    await page.press(".ai-panel textarea", "Enter");
    await expect(page.locator(".ai-msg__markdown")).toBeVisible();

    const pill = page.locator(".ai-msg__tool").first();
    await expect(pill).toContainText("product.search");
    await expect(pill).toContainText("完成");

    const box = await panel(page).boundingBox();
    // 入场动画还在缩放时会有零点几像素误差，取整后再比
    expect(Math.round(box?.width ?? 0)).toBe(400);
  });
});
