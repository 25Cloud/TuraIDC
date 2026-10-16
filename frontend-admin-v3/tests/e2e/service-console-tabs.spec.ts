import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 服务实例控制台页签标题回归。
 *
 * 线上反馈：所有服务实例的「设置页」页签全部显示成「选项卡1 / 选项卡2 / 选项卡3」，
 * 看不到「控制台总览」「基本信息」等真实名称。
 *
 * 成因：ConsolePanel 渲染页签时把标题写在 `:tab="nav.label"` 上，而 TDesign Vue Next
 * 的 t-tab-panel 只认 `label`（`tab` 不是它的 prop），label 缺失时组件回落到内置的
 * 「选项卡N」占位文案。这里钉住两点：
 *  1) 动态自定义区域（面板型产品）页签必须显示区域中文名，且不得出现「选项卡N」；
 *  2) 无动态能力时的静态兜底页签同样显示真实名称。
 */

const USER_ID = 1;
const SERVICE_ID = 3885;
const DETAIL_PATH = `/v2/admin/users/${USER_ID}/services/${SERVICE_ID}`;

/** 预置 admin 会话，避免路由守卫跳转登录页。 */
async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'service-console-tabs-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

/**
 * 统一接管 /api/v2/admin：按 pathname 精确应答控制台所需的实例详情、连接、能力与票据，
 * 其余请求一律回空数据。
 *
 * 请求都带 `?_t=` 时间戳查询串，因此匹配必须基于 pathname，不能用整串 URL 做 `$` 锚定。
 */
async function mockAdminConsoleApi(page: Page, detail: Record<string, unknown>, capabilities: unknown) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname.replace(/^\/api/, '');
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'cerbo', nickname: 'cerbo', permissions: ['*'] } });
    }
    if (pathname === DETAIL_PATH) return respond(detail);
    if (pathname === `${DETAIL_PATH}/remote-status`) return respond({});
    if (pathname === `${DETAIL_PATH}/console/capabilities`) return respond(capabilities);
    if (pathname === `${DETAIL_PATH}/console/tickets`) return respond({ ticket: 'ticket-1' });

    return respond({});
  });
}

/** 面板型产品（虚拟主机）实例详情：截图里的「虚拟主机/香港/大带宽」。 */
function panelServiceDetail(): Record<string, unknown> {
  return {
    id: SERVICE_ID,
    name: '香港HK 大带宽主机-C',
    custom_service_name: '香港HK 大带宽主机-C',
    combined_display_name: '虚拟主机/香港/大带宽主机',
    product_display_name: '虚拟主机/香港/大带宽主机',
    product: {
      id: 66,
      name: '大带宽主机',
      type: 'web_hosting',
      type_label: '虚拟主机',
      display_name: '虚拟主机/香港/大带宽主机',
      catalog_type: 'web_hosting',
    },
    machine_category: { key: 'web_hosting', label: '虚拟主机' },
    console_template: 'default',
    status: 1,
    status_label: '运行中',
    billing_cycle: 'monthly',
    billing_cycle_label: '月付',
    amount: '58.00',
    expires_at: '2026-11-01 00:00:00',
    connection: { hostname: 'hk12543', dedicated_ip: 'hk12543', internal_ip: '', port: 0 },
    upstream: { provider_key: 'zjmf', host_id: 168, status: 'active' },
    actions: { refresh: true, module_status: true, power: false },
  };
}

/** 云主机实例详情（用于静态兜底页签场景）。 */
function cloudServiceDetail(): Record<string, unknown> {
  return {
    ...panelServiceDetail(),
    id: SERVICE_ID,
    name: '云服务器-01',
    machine_category: { key: 'cloud', label: '云服务器' },
    product: { id: 10, name: '标准云服务器', type: 'vps', type_label: '云服务器', display_name: '标准云服务器' },
    console_template: 'default',
  };
}

/** 读取控制台页签的可见标题文本。 */
async function consoleTabLabels(page: Page): Promise<string[]> {
  const tabs = page.locator('.console-tabs .t-tabs__nav-item');
  await expect(tabs.first()).toBeVisible();
  return (await tabs.allTextContents()).map((text) => text.trim());
}

/**
 * 从语言包读出页签文案，而不是把中文写死在断言里。
 *
 * 页面语言会随浏览器偏好自适应（首屏走 shared/locale 的探测逻辑），
 * 断言写死中文等于假设「测试环境一定是简体中文」——探测一旦生效就会整片变红，
 * 且与被测逻辑（页签取名）毫无关系。
 */
/**
 * 从语言包读页签文案，而不是把中文写死在断言里。
 *
 * 返回类型必须写成显式结构：写成 Record<string, string> 时 t.logsTab.title
 * 在类型上就是 string，运行时却取不到嵌套值，断言会拿 undefined 去比对。
 */
function consoleTabTexts() {
  // 用 fs 读而不是 import/require：Playwright 把 spec 当 ESM 处理，
  // require 与 __dirname 都不可用，而 JSON 静态 import 在 Node ESM 下还需要 import attribute。
  const here = path.dirname(fileURLToPath(import.meta.url));
  const file = path.resolve(here, '../../src/locales/lang/zh_CN.json');
  const dict = JSON.parse(fs.readFileSync(file, 'utf8')) as {
    services: {
      console: {
        overviewTab: string;
        monitorTitle: string;
        securityGroups: string;
        financeLogs: string;
        logsTab: { title: string };
        vncTab: { title: string };
        productConfigTab: string;
      };
    };
  };
  return dict.services.console;
}

test('动态自定义区域：页签显示区域中文名而非「选项卡N」', async ({ page }) => {
  await seedAdminSession(page);
  await mockAdminConsoleApi(page, panelServiceDetail(), {
    supported: true,
    fetchable: true,
    nat_supported: false,
    areas: [
      { key: 'basic', name: '基本信息' },
      { key: 'network', name: '网络信息' },
    ],
  });

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  const labels = await consoleTabLabels(page);
  const t = consoleTabTexts();
  // 面板型产品没有监控/安全组/VNC，只保留「总览 / 产品配置 / 账单」，
  // 上游自定义区域插在产品配置之前。
  // 「基本信息 / 网络信息」是上游区域名，由 mock 直接给出，不走语言包。
  //
  // 面板型的判定走 isPanelConsole()，它先看 console_template 再看
  // machine_category.key——本 mock 是靠 machine_category.key='web_hosting' 命中的。
  expect(labels).toEqual([
    t.overviewTab,
    '基本信息',
    '网络信息',
    t.productConfigTab,
    t.financeLogs,
  ]);
  expect(labels.join('')).not.toContain('选项卡');
});

test('静态兜底页签：显示内置中文名而非「选项卡N」', async ({ page }) => {
  await seedAdminSession(page);
  await mockAdminConsoleApi(page, cloudServiceDetail(), { supported: false, fetchable: false });

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  const labels = await consoleTabLabels(page);
  const t = consoleTabTexts();
  expect(labels).toEqual([
    t.overviewTab,
    t.monitorTitle,
    t.securityGroups,
    t.logsTab.title,
    t.financeLogs,
    t.vncTab.title,
  ]);
  expect(labels.join('')).not.toContain('选项卡');
});

test('页签 value 与区域 key 对齐：点击自定义区域后内容面板标题同步', async ({ page }) => {
  await seedAdminSession(page);
  await mockAdminConsoleApi(page, panelServiceDetail(), {
    supported: true,
    fetchable: true,
    nat_supported: false,
    areas: [{ key: 'basic', name: '基本信息' }],
  });

  await page.goto(`/admin/services/${SERVICE_ID}?user=${USER_ID}`, { waitUntil: 'domcontentloaded' });

  await page.locator('.console-tabs .t-tabs__nav-item', { hasText: '基本信息' }).click();
  await expect(page.locator('.area-panel .t-card__title')).toHaveText('基本信息');
});
