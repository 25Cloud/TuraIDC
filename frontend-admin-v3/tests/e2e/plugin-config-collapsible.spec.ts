import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 插件配置抽屉的「可折叠分组」。
 *
 * 覆盖四点：默认收起、点击展开、分组之间互不影响、以及详情异步加载完成后
 * 保留用户当前的展开状态（openConfig 会先用列表数据渲染、再用 detail 覆盖，
 * 两次都会重建折叠状态，容易把刚展开的分组又收起）。
 */

const PLUGIN_ID = 4101;

/** 带 collapsible 标记的 divider 会被渲染成可点击的折叠头 */
function configSchema() {
  return [
    {
      key: 'basic_notice',
      label: '配置说明',
      type: 'notice',
      theme: 'info',
      content: '不属于任何折叠分组，应始终可见',
    },
    { key: 'site_key', label: 'Site Key', type: 'text', required: true, default: '' },
    { key: 'appearance_divider', label: '组件外观', type: 'divider', collapsible: true },
    { key: 'widget_theme', label: '主题', type: 'text', default: 'auto' },
    { key: 'advanced_divider', label: '高级', type: 'divider', collapsible: true },
    { key: 'request_timeout', label: '校验请求超时（秒）', type: 'number', default: 10 },
    // 不带 collapsible：应保持普通分隔线，其下字段始终可见
    { key: 'scene_divider', label: '启用场景', type: 'divider' },
    { key: 'scene_admin_login', label: '管理员登录', type: 'switch', default: true },
  ];
}

/**
 * 按 label 定位配置项容器。
 *
 * 不能用 getByLabel：t-form-item 的标题不是 <label for>，与控件之间没有 a11y 关联。
 * 必须定位 .t-form__item 容器本身——v-show 的 display:none 加在容器上，
 * 断言控件自身的可见性拿不到折叠状态。
 */
function fieldItem(page: Page, label: string): Locator {
  return page
    .locator('.t-form__item')
    .filter({ has: page.locator('.t-form__label', { hasText: new RegExp(`^\\s*${label}\\s*$`) }) });
}

/**
 * 断言配置项存在且处于指定可见性。
 *
 * 必须先断言 count——toBeHidden() 对「不存在的元素」同样返回真，
 * 只用 toBeHidden 会把「字段压根没渲染出来」误判成「已正确折叠」。
 */
async function expectField(page: Page, label: string, visible: boolean) {
  const item = fieldItem(page, label);
  await expect(item, `配置项「${label}」应当被渲染`).toHaveCount(1);
  if (visible) {
    await expect(item).toBeVisible();
  } else {
    await expect(item).toBeHidden();
  }
}

function pluginRecord() {
  return {
    id: PLUGIN_ID,
    domain: 'captcha',
    slug: 'turnstile',
    name: 'Cloudflare Turnstile',
    version: '1.0.0',
    entry_class: 'TuraIDC\\Plugins\\Captcha\\Turnstile\\TurnstilePlugin',
    installed_at: '2026-08-22 00:00:00',
    // is_installed 决定卡片渲染「安装」还是「管理」按钮，缺失会一直停在未安装态
    is_installed: true,
    is_enabled: false,
    status: 0,
    config: {},
    config_schema: configSchema(),
    has_secret_values: {},
  };
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'plugin-collapsible-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({
        admin: { id: 1, username: 'collapsible-test', nickname: 'collapsible-test', permissions: ['*'] },
      });
    }

    if (url.pathname.endsWith(`/integration-plugins/${PLUGIN_ID}/schema`)) {
      return respond({ plugin_id: PLUGIN_ID, domain: 'captcha', slug: 'turnstile', schema: configSchema() });
    }

    if (url.pathname.endsWith(`/integration-plugins/${PLUGIN_ID}`)) {
      return respond(pluginRecord());
    }

    if (url.pathname.endsWith('/integration-plugins')) {
      return respond({ list: [pluginRecord()], total: 1, page: 1, page_size: 20 });
    }

    return respond({});
  });

  await page.goto('/admin/integration-plugins/captcha', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: '管理' }).first().click();
});

test('collapsible sections start collapsed and expand on click', async ({ page }) => {
  const appearance = page.locator('.plugin-config-section', { hasText: '组件外观' });
  const advanced = page.locator('.plugin-config-section', { hasText: '高级' });

  await expect(appearance).toBeVisible();
  await expect(advanced).toBeVisible();

  // 默认收起：折叠头提示「展开配置」，组内字段不可见
  await expect(appearance).toHaveAttribute('aria-expanded', 'false');
  await expect(appearance).toContainText('展开配置');
  await expectField(page, '主题', false);
  await expectField(page, '校验请求超时（秒）', false);

  // 不带 collapsible 的分组不受影响，字段始终可见
  await expect(page.getByText('不属于任何折叠分组，应始终可见')).toBeVisible();
  await expectField(page, 'Site Key', true);
  await expectField(page, '管理员登录', true);

  // 点击展开「组件外观」
  await appearance.click();
  await expect(appearance).toHaveAttribute('aria-expanded', 'true');
  await expect(appearance).toContainText('收起');
  await expectField(page, '主题', true);

  // 分组之间互不影响：「高级」仍保持收起
  await expect(advanced).toHaveAttribute('aria-expanded', 'false');
  await expectField(page, '校验请求超时（秒）', false);

  // 再点一次收起
  await appearance.click();
  await expect(appearance).toHaveAttribute('aria-expanded', 'false');
  await expectField(page, '主题', false);
});

/**
 * detail 覆盖不得把用户刚展开的分组重新收起。
 *
 * 时序必须显式控制：beforeEach 的路由是立即返回的，靠 waitForTimeout 等一等
 * 并不能保证「detail 落地」发生在「点击展开」之后——即使实现真的会重置折叠状态，
 * 那样写的测试也可能通过。这里把 detail 响应挂起，展开之后才放行，
 * 并用只出现在 detail schema 里的标记字段作为「detail 已应用」的可观测证据。
 */
test('expanded state survives the async detail load', async ({ page }) => {
  // beforeEach 已经用立即返回的路由开过一次抽屉，先关掉，改用受控路由重开
  await page.getByRole('button', { name: '关闭' }).click();

  let releaseDetail: (() => void) | undefined;
  const detailHeld = new Promise<void>((resolve) => {
    releaseDetail = resolve;
  });

  let markDetailRequested: (() => void) | undefined;
  const detailRequested = new Promise<void>((resolve) => {
    markDetailRequested = resolve;
  });

  // 后注册的路由在 Playwright 中优先匹配，因此会覆盖 beforeEach 的通配路由。
  // 模式末尾没有通配符，所以不会连 /schema 一起截走。
  await page.route(`**/api/v2/admin/integration-plugins/${PLUGIN_ID}`, async (route) => {
    markDetailRequested?.();
    await detailHeld;

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          ...pluginRecord(),
          // 只有 detail 才带这个字段：它出现即证明 detail 的 schema 已经应用
          config_schema: [...configSchema(), { key: 'detail_marker', label: '详情已加载', type: 'text', default: '' }],
        },
      }),
    });
  });

  await page.getByRole('button', { name: '管理' }).first().click();

  const appearance = page.locator('.plugin-config-section', { hasText: '组件外观' });

  // detail 仍处于挂起状态时展开分组
  await detailRequested;
  await expect(appearance).toHaveAttribute('aria-expanded', 'false');
  await appearance.click();
  await expect(appearance).toHaveAttribute('aria-expanded', 'true');

  // 此刻才放行 detail：initCollapsedSections 会用新 schema 再跑一次
  releaseDetail?.();

  // 先确认 detail 真的落地了（标记字段渲染出来），再断言展开状态未被重置
  await expectField(page, '详情已加载', true);
  await expect(appearance).toHaveAttribute('aria-expanded', 'true');
  await expectField(page, '主题', true);
});

test('switching plugins resets the collapsed state', async ({ page }) => {
  const appearance = page.locator('.plugin-config-section', { hasText: '组件外观' });

  await appearance.click();
  await expect(appearance).toHaveAttribute('aria-expanded', 'true');

  // 关闭抽屉后重新打开，等同于切换插件：折叠状态应回到默认（收起）
  await page.getByRole('button', { name: '关闭' }).click();
  await page.getByRole('button', { name: '管理' }).first().click();

  await expect(appearance).toHaveAttribute('aria-expanded', 'false');
  await expectField(page, '主题', false);
});
