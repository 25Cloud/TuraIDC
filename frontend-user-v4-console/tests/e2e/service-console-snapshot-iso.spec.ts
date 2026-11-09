import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 服务控制台「快照/备份」与「ISO 挂载」两个面板。
 *
 * 覆盖点：
 * 1. tab 是否出现由自建能力接口的 `snapshot_supported` / `iso_supported` 决定
 *    （样例故意用 `supported:true, fetchable:false`，走静态集合 + 能力过滤那条分支）；
 * 2. 三态：正常列表 / 驱动不支持（supported=false）/ 空态；
 * 3. 交互只到「打开对话框」为止，**不点提交**，并用请求记录断言没有发出任何写请求。
 */

const SERVICE_ID = 3993;

const BASE_DETAIL = {
  id: SERVICE_ID,
  name: '香港PCCW 4核8G300Mbps',
  status: 1,
  billing_cycle: 'monthly',
  billing_cycle_label: '月付',
  specs: [
    { key: 'os', label: '操作系统', value: 'Debian-12.1-x64' },
    { key: 'cpu', label: 'CPU', value: '4核' },
  ],
  traffic: { usage_label: '10G', limit_label: '1000G', limited: true, usage_percent: 1 },
  actions: { refresh: true, power: true },
  upstream: { os: '97' },
};

const SNAPSHOTS_OK = {
  supported: true,
  message: '',
  error: '',
  can_create: true,
  snapshots: [
    {
      id: 11,
      name: 'before-upgrade',
      type: 'snap',
      disk_name: '系统盘',
      size_gb: 30,
      created_at: '2026-11-08 10:00:00',
      status: 'ok',
      can_delete: true,
      can_restore: true,
    },
    {
      id: 12,
      name: 'daily-1108',
      type: 'snap',
      disk_name: '系统盘',
      size_gb: 30,
      created_at: '2026-11-08 03:00:00',
      status: 'ok',
      can_delete: true,
      can_restore: true,
    },
  ],
  backups: [
    {
      id: 21,
      name: 'weekly-1101',
      type: 'backup',
      disk_name: '系统盘',
      size_gb: 30,
      created_at: '2026-11-01 03:00:00',
      status: 'ok',
      can_delete: true,
      can_restore: true,
    },
  ],
};

const ISO_OK = {
  supported: true,
  message: '',
  error: '',
  can_mount: true,
  can_unmount: true,
  can_set_bootorder: true,
  current_iso: '9001',
  current_iso_name: 'debian-12.1-amd64.iso',
  bootorder: 'disk',
  bootorder_label: '硬盘优先',
  bootorder_options: [
    { value: 'disk', label: '硬盘优先' },
    { value: 'cdrom', label: '光驱优先' },
  ],
  images: [
    { id: '9001', name: 'debian-12.1-amd64.iso' },
    { id: '9002', name: 'ubuntu-24.04.iso' },
  ],
};

interface MockOptions {
  /** 是否下发 snapshot_supported / iso_supported */
  capabilities?: { snapshot: boolean; iso: boolean };
  snapshots?: Record<string, unknown>;
  iso?: Record<string, unknown>;
}

interface MockHandle {
  /** 记录所有非 GET 的 /services/** 请求，用来断言没有触发真实操作 */
  writes: string[];
}

async function mockConsole(page: Page, options: MockOptions = {}): Promise<MockHandle> {
  const writes: string[] = [];
  const snapshotFlag = options.capabilities?.snapshot ?? true;
  const isoFlag = options.capabilities?.iso ?? true;

  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'snapshot-iso-e2e-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  page.on('request', (request) => {
    if (request.method() === 'GET') return;

    const { pathname } = new URL(request.url());
    if (pathname.startsWith(`/api/v2/client/services/${SERVICE_ID}`)) {
      writes.push(`${request.method()} ${pathname}`);
    }
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const ok = (data: unknown) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return ok({ id: 1000, name: 'e2e用户', nickname: 'e2e用户', email: 'e2e@example.com', cash_balance: '0.00' });
    }

    // 能力接口：supported=true 但 fetchable=false，走静态 tab 集合 + 能力标志位过滤那条分支
    if (url.pathname.endsWith(`/services/${SERVICE_ID}/console/capabilities`)) {
      return ok({
        supported: true,
        fetchable: false,
        nat_supported: false,
        monitor_supported: true,
        snapshot_supported: snapshotFlag,
        iso_supported: isoFlag,
        areas: [],
      });
    }

    if (url.pathname.endsWith(`/services/${SERVICE_ID}/snapshots`)) {
      return ok(options.snapshots ?? SNAPSHOTS_OK);
    }
    if (url.pathname.endsWith(`/services/${SERVICE_ID}/iso`)) {
      return ok(options.iso ?? ISO_OK);
    }
    if (url.pathname.endsWith(`/services/${SERVICE_ID}`)) {
      return ok({ service: BASE_DETAIL });
    }

    return ok({});
  });

  return { writes };
}

const sidebarItem = (page: Page, label: string) => page.locator('.console-sidebar-item', { hasText: label });

test.describe('服务控制台 · 快照/备份 与 ISO 挂载', () => {
  test('能力开关为 true 时出现两个 tab，快照面板渲染两段列表，新建只打开对话框', async ({ page }) => {
    const mock = await mockConsole(page);
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    const snapshotTab = sidebarItem(page, '快照/备份');
    const isoTab = sidebarItem(page, 'ISO 挂载');
    await expect(snapshotTab).toBeVisible();
    await expect(isoTab).toBeVisible();

    // 快照 tab 在「安全组」之后、「NAT/日志」之前
    const keys = await page.locator('.console-sidebar-item').allInnerTexts();
    const labels = keys.map((item) => item.trim());
    expect(labels.indexOf('快照/备份')).toBeGreaterThan(labels.indexOf('安全组'));
    expect(labels.indexOf('ISO 挂载')).toBe(labels.indexOf('快照/备份') + 1);

    await snapshotTab.click();

    // 两段列表都渲染
    await expect(page.getByText('before-upgrade')).toBeVisible();
    await expect(page.getByText('daily-1108')).toBeVisible();
    await expect(page.getByText('weekly-1101')).toBeVisible();
    await expect(page.getByText('120 GB')).toHaveCount(0);

    // 新建：只到对话框，不提交
    await page.getByRole('button', { name: '新建', exact: true }).click();
    const dialog = page.locator('.t-dialog', { hasText: '新建快照 / 备份' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByPlaceholder('便于识别的名称，如 web-20261109')).toBeVisible();
    await expect(dialog).toContainText('类型');
    await dialog.getByRole('button', { name: '取消' }).click();
    await expect(dialog).toBeHidden();

    expect(mock.writes).toEqual([]);
  });

  test('ISO tab 显示当前镜像、卸载与启动项，未选镜像时挂载按钮禁用', async ({ page }) => {
    const mock = await mockConsole(page);
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    await sidebarItem(page, 'ISO 挂载').click();

    await expect(page.getByText('debian-12.1-amd64.iso')).toBeVisible();
    await expect(page.getByRole('button', { name: '卸载' })).toBeVisible();

    // 启动项两项来自 bootorder_options（只看 ISO 面板里的单选框，页面别处还有别的 radio）
    const bootOptions = page.locator('.iso-form .t-radio-button');
    await expect(bootOptions).toHaveCount(2);
    await expect(bootOptions.nth(0)).toContainText('硬盘优先');
    await expect(bootOptions.nth(1)).toContainText('光驱优先');

    // 没选镜像前「挂载」不可点（避免误发写请求）
    await expect(page.getByRole('button', { name: '挂载', exact: true })).toBeDisabled();

    expect(mock.writes).toEqual([]);
  });

  test('能力开关为 false 时不出现这两个 tab', async ({ page }) => {
    await mockConsole(page, { capabilities: { snapshot: false, iso: false } });
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    await expect(page.locator('.console-sidebar-item').first()).toBeVisible();
    await expect(sidebarItem(page, '快照/备份')).toHaveCount(0);
    await expect(sidebarItem(page, 'ISO 挂载')).toHaveCount(0);
  });

  test('快照面板 supported=false 时给不支持说明且不出现新建按钮', async ({ page }) => {
    await mockConsole(page, {
      snapshots: {
        supported: false,
        message: '当前驱动不支持快照 / 备份',
        error: '',
        can_create: false,
        snapshots: [],
        backups: [],
      },
    });
    await page.goto(`/client/services/${SERVICE_ID}`, { waitUntil: 'domcontentloaded' });

    await sidebarItem(page, '快照/备份').click();

    await expect(page.getByText('当前驱动不支持快照 / 备份')).toBeVisible();
    await expect(page.getByRole('button', { name: '新建', exact: true })).toHaveCount(0);
  });
});
