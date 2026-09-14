import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 开放接口配置页的数字开关回归。
 *
 * 线上反馈：开启开放接口提示保存成功、功能也确实生效，但页面三个开关
 * （启用开放接口 / 必须绑定手机号 / 必须实名认证）始终显示为关闭。
 *
 * 成因：t-switch 的开启判定是 `innerValue === activeValue`，而 activeValue
 * 在未声明 custom-value 时默认为布尔 true。后端 `/open-api/config` 返回的是
 * 数字 1/0（OpenApiConfig::toArray），`1 !== true` 故永远渲染为关闭；点击时
 * 组件又把值改写成布尔 true，保存后响应回写数字 1，开关再次被拽回关闭。
 *
 * 这里钉住两点：
 *  1) 接口返回 1 必须渲染为开启、返回 0 渲染为关闭；
 *  2) 切换后保存的请求体仍是数字 0/1，且响应回写不会把开关拽回关闭。
 */

function configPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    enabled: 0,
    require_phone: 0,
    require_verified: 0,
    max_keys_per_user: 10,
    rate_limit: 60,
    write_rate_limit: 30,
    ...overrides,
  };
}

/**
 * 按配置卡标题定位开关。
 *
 * 不能用 getByRole('switch')：t-switch 渲染成 div 而非 input[type=checkbox]，
 * 没有 a11y 角色可依赖，只能按卡片容器 + 标题文本收敛。
 */
function configSwitch(page: Page, title: string): Locator {
  return page.locator('.config-card').filter({ hasText: title }).locator('.t-switch');
}

/** 预置 admin 会话并 mock 登录信息，避免路由守卫跳转登录页。 */
async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'open-api-switch-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

test.beforeEach(async ({ page }) => {
  await seedAdminSession(page);
});

test('开关按接口返回的 1/0 渲染实际状态', async ({ page }) => {
  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'open-api-switch', nickname: 'open-api-switch', permissions: ['*'] },
      });
    }

    if (url.pathname.endsWith('/open-api/config')) {
      return reply(configPayload({ enabled: 1, require_phone: 0, require_verified: 1 }));
    }

    if (url.pathname.endsWith('/open-api/keys')) {
      return reply({ list: [], total: 0, page: 1, page_size: 20 });
    }

    return reply({});
  });

  await page.goto('/admin/open-api', { waitUntil: 'domcontentloaded' });

  await expect(configSwitch(page, '启用开放接口'), 'enabled=1 必须显示为开启').toHaveClass(/t-is-checked/);
  await expect(configSwitch(page, '必须绑定手机号'), 'require_phone=0 必须显示为关闭').not.toHaveClass(/t-is-checked/);
  await expect(configSwitch(page, '必须实名认证'), 'require_verified=1 必须显示为开启').toHaveClass(/t-is-checked/);
});

test('切换后保存提交数字且不被响应拽回关闭', async ({ page }) => {
  const captured: { body: Record<string, unknown> | null } = { body: null };

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'open-api-switch', nickname: 'open-api-switch', permissions: ['*'] },
      });
    }

    if (url.pathname.endsWith('/open-api/config')) {
      if (route.request().method() === 'PUT') {
        captured.body = route.request().postDataJSON() as Record<string, unknown>;
        const enabled = Number(captured.body?.enabled) === 1 ? 1 : 0;
        const requirePhone = Number(captured.body?.require_phone) === 1 ? 1 : 0;

        // 后端保存后回读配置：数字 1/0 原样返回，模拟真实响应
        return reply(configPayload({ enabled, require_phone: requirePhone }));
      }

      return reply(configPayload());
    }

    if (url.pathname.endsWith('/open-api/keys')) {
      return reply({ list: [], total: 0, page: 1, page_size: 20 });
    }

    return reply({});
  });

  await page.goto('/admin/open-api', { waitUntil: 'domcontentloaded' });

  const enabledSwitch = configSwitch(page, '启用开放接口');
  await expect(enabledSwitch).not.toHaveClass(/t-is-checked/);

  // 点击开启：修复前这里会把值写成布尔 true
  await enabledSwitch.click();
  await expect(enabledSwitch).toHaveClass(/t-is-checked/);

  await page.getByRole('button', { name: '保存配置' }).click();
  await expect(page.getByText('开放接口配置已保存')).toBeVisible();

  expect(captured.body, '保存请求应当已发出').not.toBeNull();
  expect(captured.body?.enabled, '开关值必须是数字 1 而不是布尔 true').toBe(1);
  expect(typeof captured.body?.enabled, '开关值类型必须是 number').toBe('number');
  expect(captured.body?.require_phone, '未改动的数字开关也按数字提交').toBe(0);

  // 关键回归：响应回写数字 1 后，开关不得被拽回关闭
  await expect(enabledSwitch, '保存后开关必须保持开启').toHaveClass(/t-is-checked/);
});
