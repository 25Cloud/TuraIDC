import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

import type { QueueJobsPage } from '../../src/api/admin/settings';

/**
 * 队列任务抽屉回归：定时任务页环境卡片的「待执行/已失败」计数是抽屉入口。
 *
 * 锁三件事：
 * 1. 点失败任务卡片打开抽屉，能看到失败任务与删除入口；
 * 2. 待执行页签只读——它是尚未运行的业务任务，删除即静默丢弃，界面必须明示；
 * 3. 队列运行时连接与本页检查的连接不一致时，必须给出「看不见真实积压」的警示，
 *    这是 database 连接覆盖缺口的显式化，不能让管理员把空列表误读成没有积压。
 */

const OVERVIEW_ENV = {
  pending_jobs: 3,
  failed_jobs: 2,
  queue_runtime_mode: 'database',
  schedule_mutex: { enabled: true, reason: '', cache_store: 'redis', os_family: 'win' },
  automation_config: { status: 'loaded' },
};

function failedJobsPage(connection = { runtime: 'database', inspected: 'database' }): QueueJobsPage {
  return {
    list: [
      {
        id: 41,
        kind: 'failed',
        queue: 'referral',
        display_name: 'App\\Jobs\\SyncSupplierBalanceJob',
        attempts: 3,
        max_tries: 3,
        state: 'failed',
        uuid: 'ab12cd34-5678-90ab-cdef-1234567890ab',
        created_at: null,
        available_at: null,
        reserved_at: null,
        failed_at: '2026-10-25 10:00:00',
        exception: 'InvalidArgumentException: 上游余额同步失败',
      },
    ],
    total: 1,
    page: 1,
    page_size: 20,
    connection,
  };
}

function pendingJobsPage(connection = { runtime: 'database', inspected: 'database' }): QueueJobsPage {
  return {
    list: [
      {
        id: 7,
        kind: 'pending',
        queue: 'referral',
        display_name: 'App\\Jobs\\SendUserNotificationJob',
        attempts: 0,
        max_tries: 3,
        state: 'pending',
        uuid: 'ff11ee22-3333-4444-5555-666677778888',
        created_at: '2026-10-25 10:05:00',
        available_at: '2026-10-25 10:05:00',
        reserved_at: null,
        failed_at: null,
        exception: null,
      },
    ],
    total: 1,
    page: 1,
    page_size: 20,
    connection,
  };
}

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'queue-jobs-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page, options: { connection?: { runtime: string; inspected: string } } = {}) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'queue-check', nickname: 'queue-check', permissions: ['*'] },
      });
    }

    if (url.pathname.endsWith('/schedules/overview')) {
      return reply({ tasks: [], recent_logs: [], environment: OVERVIEW_ENV });
    }

    if (url.pathname.endsWith('/schedule-queue-jobs')) {
      const queue = url.searchParams.get('queue');
      const page = Number(url.searchParams.get('page') || '1');
      const source = queue === 'pending' ? pendingJobsPage(options.connection) : failedJobsPage(options.connection);
      return reply({ ...source, page });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('失败任务卡片打开队列抽屉并可删除', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '抽屉交互按桌面视口断言');

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/logs?tab=schedules', { waitUntil: 'domcontentloaded' });

  // 环境卡片计数来自概览接口
  await expect(page.getByText('失败队列任务', { exact: true })).toBeVisible({ timeout: 15000 });

  // 点击失败任务计数卡片打开抽屉
  await page.locator('.schedule-env-item', { hasText: '失败队列任务' }).click();

  // 页面上常驻多个隐藏抽屉（日志详情/设置），必须限定打开态，否则 strict mode 冲突
  const drawer = page.locator('.t-drawer.t-drawer--open');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('App\\Jobs\\SyncSupplierBalanceJob')).toBeVisible();
  await expect(drawer.getByText('已失败', { exact: true }).first()).toBeVisible();
  await expect(drawer.getByText('删除', { exact: true })).toBeVisible();

  // 连接一致时不出现覆盖缺口警示
  await expect(drawer.getByText('本页仅能查看')).toHaveCount(0);
});

test('待执行页签只读且无删除入口', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '抽屉交互按桌面视口断言');

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/logs?tab=schedules', { waitUntil: 'domcontentloaded' });

  await expect(page.getByText('待处理任务', { exact: true })).toBeVisible({ timeout: 15000 });
  await page.locator('.schedule-env-item', { hasText: '待处理任务' }).click();

  // 页面上常驻多个隐藏抽屉（日志详情/设置），必须限定打开态，否则 strict mode 冲突
  const drawer = page.locator('.t-drawer.t-drawer--open');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('App\\Jobs\\SendUserNotificationJob')).toBeVisible();
  await expect(drawer.getByText('待执行队列只读')).toBeVisible();
  await expect(drawer.getByText('只读', { exact: true })).toBeVisible();
  await expect(drawer.getByText('删除', { exact: true })).toHaveCount(0);
  await expect(drawer.getByText('清空已失败', { exact: true })).toHaveCount(0);
});

test('队列连接与运行时不一致时给出覆盖缺口警示', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '抽屉交互按桌面视口断言');

  await seedAdminSession(page);
  await mockAdminApi(page, { connection: { runtime: 'redis', inspected: 'database' } });
  await page.goto('/admin/logs?tab=schedules', { waitUntil: 'domcontentloaded' });

  await expect(page.getByText('待处理任务', { exact: true })).toBeVisible({ timeout: 15000 });
  await page.locator('.schedule-env-item', { hasText: '待处理任务' }).click();

  // 页面上常驻多个隐藏抽屉（日志详情/设置），必须限定打开态，否则 strict mode 冲突
  const drawer = page.locator('.t-drawer.t-drawer--open');
  await expect(drawer).toBeVisible();
  await expect(drawer.getByText('本页仅能查看')).toBeVisible();
});
