import type { Locator, Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 插件配置抽屉的「可折叠分组」与字段排版。
 *
 * 覆盖五点：默认收起、点击展开、分组之间互不影响、详情异步加载完成后保留用户当前的展开状态，
 * 以及每个字段都是「标签一行 → 说明一行 → 控件一行」（schema 的 description 必须真的渲染出来）。
 */

const PLUGIN_ID = 4101;
const APPEARANCE_NOTICE = '组件外观分节提示：这几个参数只影响前端展示，不影响核验结果。';

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
    {
      key: 'site_key',
      label: 'Site Key',
      type: 'text',
      required: true,
      default: '',
      description: '公开参数，用于前端初始化验证组件；会随页面下发到浏览器。',
    },
    { key: 'appearance_divider', label: '组件外观', type: 'divider', collapsible: true },
    { key: 'widget_theme', label: '主题', type: 'text', default: 'auto' },
    // 写在字段后面，渲染时应被提到分节顶部
    { key: 'appearance_notice', label: '外观提示', type: 'notice', theme: 'info', content: APPEARANCE_NOTICE },
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
 * 字段结构是 .admin-field（标签 / 说明 / 控件三段），v-show 的 display:none 加在容器上，
 * 断言控件自身的可见性拿不到折叠状态，必须断言容器。
 */
function fieldItem(page: Page, label: string): Locator {
  return page
    .locator('.plugin-config-form .admin-field')
    .filter({ has: page.locator('.admin-field__label', { hasText: new RegExp(`^\\s*${label}\\s*$`) }) });
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

/** 折叠分组里的 notice：按文案定位到 t-alert 根节点，避免命中它的子元素 */
function appearanceNotice(page: Page): Locator {
  return page.locator('.plugin-config-notice', { hasText: APPEARANCE_NOTICE });
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
  // 组内的 notice 也跟着收起，不会漏在抽屉里孤零零提示
  await expect(appearanceNotice(page)).toBeHidden();

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

test('field description renders as its own row between label and control', async ({ page }) => {
  // schema 里的 description 现在整行渲染，不再被丢掉
  const siteKey = fieldItem(page, 'Site Key');
  const desc = siteKey.locator('.admin-field__desc');
  await expect(desc).toHaveCount(1);
  await expect(desc).toHaveText('公开参数，用于前端初始化验证组件；会随页面下发到浏览器。');

  // 顺序必须是 标签 → 说明 → 控件，说明不与控件同行
  const layout = await siteKey.evaluate((element) => {
    const label = element.querySelector('.admin-field__label')!.getBoundingClientRect();
    const description = element.querySelector('.admin-field__desc')!.getBoundingClientRect();
    const control = element.querySelector('.admin-field__control')!.getBoundingClientRect();

    return {
      labelBottom: label.bottom,
      descTop: description.top,
      descBottom: description.bottom,
      controlTop: control.top,
    };
  });
  expect(layout.descTop).toBeGreaterThanOrEqual(layout.labelBottom - 1);
  expect(layout.controlTop).toBeGreaterThanOrEqual(layout.descBottom - 1);

  // 没有 description 的字段不占说明行，避免出现一行空白
  await expect(fieldItem(page, '管理员登录').locator('.admin-field__desc')).toHaveCount(0);
});

test('notice is hoisted to the top of its section', async ({ page }) => {
  const appearance = page.locator('.plugin-config-section', { hasText: '组件外观' });
  await appearance.click();

  const notice = appearanceNotice(page);
  await expect(notice).toBeVisible();

  // notice 在 schema 里排在「主题」之后，渲染时必须落在该分节字段之前
  const noticeBox = await notice.boundingBox();
  const themeBox = await fieldItem(page, '主题').boundingBox();
  expect(noticeBox).not.toBeNull();
  expect(themeBox).not.toBeNull();
  expect(noticeBox!.y).toBeLessThan(themeBox!.y);
});

/**
 * 抽屉必须先等 detail 落地再渲染配置分区，落地后展开状态不会被后续渲染重置。
 *
 * 旧实现会先用列表接口里的 schema 把表单渲染出来，detail 到达后再用 detail schema
 * 覆盖一次，`initCollapsedSections` 于是重跑、把用户刚展开的分组收回去。现在抽屉在
 * `configLoading` 期间只渲染加载态，表单只在 detail 到位后出现一次，这个竞态从结构上
 * 消失了——所以这里钉的是新契约：加载中不渲染分区，落地后分组可展开且保持展开。
 *
 * detail 响应仍然显式挂起：靠 waitForTimeout 无法保证时序，只有把响应扣住再放行，
 * 「加载态 → 渲染分区」这个先后关系才是可证的。
 */
test('waits for detail before rendering sections, then keeps the expanded state', async ({ page }) => {
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
  // 用「以 ID 结尾、可带查询串」的正则：既不会把 /schema 一起截走，
  // 也不会因为请求带了 ?_t= 这类时间戳而漏匹配（漏匹配会退回立即响应的通配路由，
  // 让下面的 detailRequested 永远等不到，表现为整体 30s 超时）。
  await page.route(new RegExp(`/api/v2/admin/integration-plugins/${PLUGIN_ID}(?:\\?.*)?$`), async (route) => {
    markDetailRequested?.();
    await detailHeld;

    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        data: {
          ...pluginRecord(),
          // 只有 detail 返回这个名字：抽屉标题出现它就证明 detail 已应用
          name: 'Cloudflare Turnstile 详情',
        },
      }),
    });
  });

  await page.getByRole('button', { name: '管理' }).first().click();
  await detailRequested;

  // detail 挂起期间：只有加载态，一个配置分区都不该出现
  await expect(page.locator('.plugin-config-loading')).toBeVisible();
  await expect(page.locator('.plugin-config-section')).toHaveCount(0);

  releaseDetail?.();

  const appearance = page.locator('.plugin-config-section', { hasText: '组件外观' });

  // detail 落地后加载态消失、分区才出现（分区只在 configLoading=false 后渲染，
  // 所以「0 个分区 → 有分区」本身就证明 detail 已经到位）
  await expect(page.locator('.plugin-config-loading')).toHaveCount(0, { timeout: 10000 });
  await expect(appearance).toHaveAttribute('aria-expanded', 'false');

  await appearance.click();
  await expect(appearance).toHaveAttribute('aria-expanded', 'true');
  await expectField(page, '主题', true);

  // 再等一拍，确认没有任何后续渲染把它收起
  await page.waitForTimeout(300);
  await expect(appearance).toHaveAttribute('aria-expanded', 'true');
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
