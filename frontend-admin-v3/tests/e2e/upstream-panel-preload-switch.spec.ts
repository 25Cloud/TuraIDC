import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「集成插件 → 上游」域面板区域预热开关回归。
 *
 * 该开关对应设置项 system.console_area_preload_all_enabled，控制用户控制台是否在首屏
 * 把所有自定义面板（魔方兼容 tab）全部预加载。缺省开启是产品决定：切换 tab 零等待。
 *
 * 这里钉住三点：
 *  1) 设置返回 1 必须渲染为开启、返回 0 渲染为关闭；
 *  2) 设置项缺失或为空时按开启渲染（与后端 interpretPreloadSetting 对空值的口径一致，
 *     避免设置被清空后管理员看到「关闭」而实际行为是「开启」）；
 *  3) 切换后提交的是数字 0/1，且保存失败会把开关弹回原状态。
 *
 * 定位方式与 open-api-config-switch.spec.ts 同源：t-switch 渲染成 div 而非
 * input[type=checkbox]，没有 a11y 角色可依赖，只能按卡片容器 + 标题文本收敛。
 */

/** 设置项在 system 分组下的 key。 */
const ORIGIN_CHECK_KEY = 'console_module_origin_check_enabled';
const PRELOAD_ALL_KEY = 'console_area_preload_all_enabled';

function settingsList(overrides: Record<string, string | null> = {}) {
  const defaults: Record<string, string | null> = {
    [ORIGIN_CHECK_KEY]: '1',
    [PRELOAD_ALL_KEY]: '1',
    ...overrides,
  };

  return Object.entries(defaults)
    .filter(([, value]) => value !== null)
    .map(([key, value]) => ({ key, value }));
}

/** 按卡片标题定位开关。 */
function panelSwitch(page: Page, title: string): Locator {
  return page.locator('.upstream-origin-check-card').filter({ hasText: title }).locator('.t-switch');
}

/** 预置 admin 会话并统一接管 /api/v2/admin，避免路由守卫跳转登录页。 */
async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'panel-preload-switch-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

type SaveCapture = { body: Record<string, unknown> | null; failNextSave: boolean };

/**
 * @param capture 保存请求的捕获器；把 failNextSave 置 true 可让下一次保存返回 500，
 *                用于验证失败时开关会弹回。
 */
async function mockAdminApi(page: Page, list: unknown, capture?: SaveCapture) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'panel-preload', nickname: 'panel-preload', permissions: ['*'] },
      });
    }

    if (pathname.endsWith('/settings')) {
      if (route.request().method() === 'POST') {
        if (capture) {
          capture.body = route.request().postDataJSON() as Record<string, unknown>;
          if (capture.failNextSave) {
            return route.fulfill({
              status: 500,
              contentType: 'application/json',
              body: JSON.stringify({ code: 50000, message: '保存失败' }),
            });
          }
        }
        return reply({});
      }
      return reply({ list, total: (list as unknown[]).length, page: 1, page_size: 100 });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test.beforeEach(async ({ page }) => {
  await seedAdminSession(page);
});

test('预热开关按设置返回的 1/0 渲染实际状态', async ({ page }) => {
  await mockAdminApi(page, settingsList({ [PRELOAD_ALL_KEY]: '1', [ORIGIN_CHECK_KEY]: '0' }));

  await page.goto('/admin/integration-plugins/upstream', { waitUntil: 'domcontentloaded' });

  await expect(panelSwitch(page, '面板区域首屏预热'), 'preload=1 必须显示为开启').toHaveClass(
    /t-is-checked/,
  );
  await expect(panelSwitch(page, '上游面板同源检测'), 'origin_check=0 必须显示为关闭').not.toHaveClass(
    /t-is-checked/,
  );
});

test('设置项缺失时按开启渲染', async ({ page }) => {
  await mockAdminApi(page, settingsList({ [PRELOAD_ALL_KEY]: null, [ORIGIN_CHECK_KEY]: null }));

  await page.goto('/admin/integration-plugins/upstream', { waitUntil: 'domcontentloaded' });

  await expect(panelSwitch(page, '面板区域首屏预热'), '缺省必须显示为开启').toHaveClass(/t-is-checked/);
  await expect(panelSwitch(page, '上游面板同源检测'), '缺省必须显示为开启').toHaveClass(/t-is-checked/);
});

test('关闭预热后提交数字 0，保存失败时开关弹回', async ({ page }) => {
  const capture: SaveCapture = { body: null, failNextSave: true };
  await mockAdminApi(page, settingsList(), capture);

  await page.goto('/admin/integration-plugins/upstream', { waitUntil: 'domcontentloaded' });

  const preloadSwitch = panelSwitch(page, '面板区域首屏预热');
  await expect(preloadSwitch).toHaveClass(/t-is-checked/);

  // 关掉预热：保存会失败，开关必须弹回开启而不是停在关闭
  await preloadSwitch.click();

  expect(capture.body, '保存请求应当已发出').not.toBeNull();
  expect(capture.body?.group).toBe('system');
  expect((capture.body?.settings as Record<string, unknown> | undefined)?.[PRELOAD_ALL_KEY]).toBe(0);
  expect(
    typeof (capture.body?.settings as Record<string, unknown> | undefined)?.[PRELOAD_ALL_KEY],
    '开关值必须是数字 0 而不是布尔 false',
  ).toBe('number');

  await expect(preloadSwitch, '保存失败后开关必须弹回开启').toHaveClass(/t-is-checked/);
});