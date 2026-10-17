import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 服务实例详情「顶部信息卡片」结构与布局回归。
 *
 * 线上反馈三条：内容在宽屏贴左、右侧大片留白；窄屏左侧空、右侧溢出；
 * 卡片里有个默认展开、点了也不多出信息的折叠开关；「查看用户」在两个位置重复出现。
 *
 * 成因：卡片正文沿用旧版「左信息 + 右操作」的 flex + space-between 约定，
 * 而新版面板只有一个块级子元素，于是按内容宽度收缩；折叠与重复按钮是信息架构重排的残留。
 * 这里钉住四点，避免重排再次回退：
 *  1) 卡片内容铺满正文宽度（不是按内容收缩）；
 *  2) 卡片内不得残留折叠开关；
 *  3) 「查看用户」全页只出现一次；
 *  4) 卡片自身不产生横向溢出（窄屏网格不得撑破容器）。
 */

const USER_ID = 1;
const SERVICE_ID = 3885;
const DETAIL_PATH = `/v2/admin/users/${USER_ID}/services/${SERVICE_ID}`;

/**
 * 从语言包读按钮文案，而不是把中文写死在断言里：
 * 页面语言随浏览器偏好自适应，写死中文会在别的语言环境整片变红，
 * 且与「是否重复渲染」这个被测逻辑无关。
 */
function viewUserText(): string {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const file = path.resolve(here, '../../src/locales/lang/zh_CN.json');
  const dict = JSON.parse(fs.readFileSync(file, 'utf8')) as {
    services: { index: { viewUser: string } };
  };
  return dict.services.index.viewUser;
}

/** 预置 admin 会话，避免路由守卫跳转登录页。 */
async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'service-header-layout-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

/** 云主机实例详情：走通用控制台，顶部卡片即 ServiceHeaderPanel。 */
function cloudServiceDetail(): Record<string, unknown> {
  return {
    id: SERVICE_ID,
    name: '云服务器-01',
    custom_service_name: '云服务器-01',
    combined_display_name: '标准云服务器',
    product_display_name: '标准云服务器',
    product: { id: 10, name: '标准云服务器', type: 'vps', type_label: '云服务器', display_name: '标准云服务器' },
    machine_category: { key: 'cloud', label: '云服务器' },
    console_template: 'default',
    status: 1,
    status_label: '运行中',
    billing_cycle: 'monthly',
    billing_cycle_label: '月付',
    amount: '58.00',
    expires_at: '2026-11-01 00:00:00',
    connection: { hostname: 'hk12543', dedicated_ip: '1.2.3.4', internal_ip: '', port: 0 },
    upstream: { provider_key: 'zjmf', host_id: 168, status: 'active', admin_url: '' },
    actions: { refresh: true, module_status: true, power: true },
    user: { id: USER_ID, username: 'demo-user', email: 'demo@example.com' },
  };
}

/** 接管控制台所需的实例详情、连接、能力与票据接口。 */
async function mockDetailApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname.replace(/^\/api/, '');
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'cerbo', nickname: 'cerbo', permissions: ['*'] } });
    }
    if (pathname === DETAIL_PATH) return respond(cloudServiceDetail());
    if (pathname === `${DETAIL_PATH}/remote-status`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/capabilities`) {
      return respond({ supported: false, fetchable: false });
    }
    if (pathname === `${DETAIL_PATH}/console/tickets`) return respond({ ticket: 'ticket-1' });

    return respond({});
  });
}

test('顶部信息卡片铺满正文、无折叠、查看用户不重复、不横向溢出', async ({ page }) => {
  await seedAdminSession(page);
  await mockDetailApi(page);
  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  const cardBody = page.locator('.console-header-card .t-card__body');
  const cardInner = page.locator('.console-header-card .console-header-body');
  await expect(cardInner, '顶部卡片内容应当渲染').toBeVisible();

  // 比的是「正文内容宽度」而不是 border-box 宽度：窄屏内边距在 390px 下占比很大，
  // 用含内边距的宽度做分母会把正常铺满误判成收缩。
  const widthCheck = await cardBody.evaluate((element) => {
    const style = getComputedStyle(element);
    const contentWidth =
      element.clientWidth - Number.parseFloat(style.paddingLeft) - Number.parseFloat(style.paddingRight);
    const inner = element.firstElementChild as HTMLElement | null;
    return { contentWidth, innerWidth: inner ? inner.getBoundingClientRect().width : 0 };
  });
  expect(widthCheck.contentWidth, '卡片正文应有可用宽度').toBeGreaterThan(0);
  expect(
    widthCheck.innerWidth,
    `卡片内容必须铺满正文宽度（inner=${widthCheck.innerWidth} content=${widthCheck.contentWidth}）：` +
      '明显窄于正文说明又退回了按内容收缩（宽屏右侧留白）',
  ).toBeGreaterThan(widthCheck.contentWidth * 0.98);

  await expect(
    page.locator('.console-header-card .t-collapse'),
    '次级信息已改为平铺，卡片内不应再有折叠开关',
  ).toHaveCount(0);

  await expect(page.getByRole('button', { name: viewUserText() }), '「查看用户」全页只应出现一次').toHaveCount(1);

  const cardOverflow = await page.locator('.console-header-card').evaluate((element) => ({
    scroll: element.scrollWidth,
    client: element.clientWidth,
  }));
  expect(
    cardOverflow.scroll,
    `卡片不应横向溢出（scrollWidth=${cardOverflow.scroll} > clientWidth=${cardOverflow.client}）`,
  ).toBeLessThanOrEqual(cardOverflow.client + 1);
});
