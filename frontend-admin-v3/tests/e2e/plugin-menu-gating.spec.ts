import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 插件提供的功能入口必须随插件状态收敛。
 *
 * 背景：插件规范禁止插件自行注册管理端菜单/路由（docs/develop/plugins/index.md 第 9 节），
 * 所以「AI 助手」这类页面只能托管在核心，菜单与路由写在核心。但核心此前不知道插件在不在：
 * 停用或卸载 `addons/ai_assistant` 之后，`/api/v2/admin/ai/*` 随插件 Provider 一起消失，
 * 侧栏「AI 助手」还挂着，点进去是一串 404。
 *
 * 现在登录/取用户信息接口下发 `features`（后端 App\Support\OptionalFeature），
 * 侧栏分组带 `requiresFeature`、路由 meta 也带，三处一起判定：
 *   1. 插件未启用 → 侧栏没有该分组；
 *   2. 插件已启用 → 侧栏照常出现（防止一刀切把入口藏死）；
 *   3. 插件未启用时直接输地址 → 退回首页，不渲染接口全 404 的页面。
 */

const AI_MENU_GROUP = 'li.t-submenu[name="/admin/menu/ai-assistant"]';

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'plugin-gate-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page, features: Record<string, boolean>) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'plugin-gate', nickname: 'plugin-gate', permissions: ['*'] },
        features,
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('插件未启用时侧栏不出现 AI 助手分组', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组');

  await seedAdminSession(page);
  await mockAdminApi(page, { ai_assistant: false });
  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });

  const sidebar = page.getByRole('navigation');
  await expect(sidebar).toBeVisible({ timeout: 15000 });

  await expect(sidebar.locator(AI_MENU_GROUP)).toHaveCount(0);
  await expect(sidebar.getByText('AI 助手', { exact: true })).toHaveCount(0);

  // 其它核心分组不受影响：功能开关不是「把侧栏清空」
  await expect(sidebar.getByText('实例管理', { exact: true })).toBeVisible();
});

test('插件启用时侧栏照常出现 AI 助手分组', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '桌面端侧边栏常驻，才适合断言菜单分组');

  await seedAdminSession(page);
  await mockAdminApi(page, { ai_assistant: true });
  await page.goto('/admin/dashboard', { waitUntil: 'domcontentloaded' });

  const sidebar = page.getByRole('navigation');
  await expect(sidebar.locator(AI_MENU_GROUP)).toHaveCount(1, { timeout: 15000 });
});

test('插件未启用时直接访问 AI 页面被退回首页', async ({ page }) => {
  await seedAdminSession(page);
  await mockAdminApi(page, { ai_assistant: false });

  await page.goto('/admin/ai/settings', { waitUntil: 'domcontentloaded' });

  // 守卫把它送回首页，而不是渲染一个所有接口都 404 的设置页
  await expect(page).toHaveURL(/\/admin\/dashboard/, { timeout: 15000 });
});
