import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「工单预回复设置」页排版回归。
 *
 * 线上反馈：整页控件挤在一起、莫名其妙的折行、控件宽度奇怪。此前该页的问题：
 *  1. 只有一个孤零零的「保存设置」按钮贴在卡片右上角，下面直接接表单，没有页头；
 *  2. 开关与说明文字只靠 margin-left: 8px 贴着，行高又不一致，看起来像同一个标签；
 *  3. 表单限宽 720px 但控件宽度各自为政，下拉框窄、文本域宽度不统一。
 *
 * 这里锁定修复后的形态：页头左说明右保存、控件与说明有可换行的 12px 间距、
 * 文本域撑满整行、窄屏说明独占一行且不产生横向溢出。
 */

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'pre-reply-layout-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

async function mockAdminApi(page: Page) {
  await page.route('**/api/v2/admin/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (pathname.endsWith('/auth/info')) {
      return reply({
        admin: {
          id: 1,
          username: 'pre-reply-check',
          nickname: 'pre-reply-check',
          permissions: ['*'],
        },
      });
    }

    if (pathname.endsWith('/ticket-pre-reply-settings')) {
      return reply({
        admin_users: [
          { id: 1, username: 'ops-a', nickname: '运营小 A' },
          { id: 2, username: 'ops-b', nickname: '运营小 B' },
        ],
        settings: {
          enabled: true,
          admin_user_id: 1,
          content: '您好，您的工单已收到，我们会尽快处理。',
          upstream_content: '',
        },
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });
}

test('工单预回复设置页：页头对齐、控件与说明有间距、文本域撑满', async ({ page }, testInfo) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/ticket-pre-reply-settings', { waitUntil: 'domcontentloaded' });

  const toolbar = page.locator('.ticket-pre-reply-toolbar');
  await expect(toolbar).toBeVisible({ timeout: 15000 });
  await expect(page.locator('.ticket-pre-reply-summary strong')).toHaveText('工单预回复');
  await expect(toolbar.getByRole('button', { name: '保存设置' })).toBeVisible();

  const toolbarLayout = await toolbar.evaluate((element) => {
    const summary = element.querySelector('.ticket-pre-reply-summary');
    const button = element.querySelector('.t-button');
    if (!summary || !button) return null;

    return {
      display: window.getComputedStyle(element).display,
      direction: window.getComputedStyle(element).flexDirection,
      summaryLeft: summary.getBoundingClientRect().left,
      buttonRight: button.getBoundingClientRect().right,
      gap: button.getBoundingClientRect().left - summary.getBoundingClientRect().right,
    };
  });
  expect(toolbarLayout).not.toBeNull();
  expect(toolbarLayout!.display).toBe('flex');

  if (testInfo.project.name === 'desktop') {
    // 桌面：左右两段，说明在左、保存在右，中间留出空隙
    expect(toolbarLayout!.direction).toBe('row');
    expect(toolbarLayout!.gap).toBeGreaterThanOrEqual(8);

    // 表单宽度收敛，不铺满整张卡片，也不窄到把说明挤成一条
    const formWidth = await page.locator('.ticket-pre-reply-form').evaluate((element) => ({
      width: element.getBoundingClientRect().width,
      maxWidth: Number.parseFloat(window.getComputedStyle(element).maxWidth || '0'),
    }));
    expect(formWidth.width).toBeLessThanOrEqual(900);

    // 开关行：说明文字与开关之间至少 8px，说明不贴着开关
    const switchRow = page.locator('.ticket-pre-reply-form .t-form__item').first();
    const spacing = await switchRow.evaluate((element) => {
      const controls = element.querySelector('.t-form__controls-content');
      const hint = element.querySelector('.ticket-pre-reply-hint');
      if (!controls || !hint) return null;

      const hintRect = hint.getBoundingClientRect();
      const controlRect = controls.firstElementChild?.getBoundingClientRect();

      return {
        wrap: window.getComputedStyle(controls).flexWrap,
        leftGap: controlRect ? hintRect.left - controlRect.right : null,
        hintWidth: hintRect.width,
      };
    });
    expect(spacing).not.toBeNull();
    expect(spacing!.wrap).toBe('wrap');
    expect(spacing!.leftGap).toBeGreaterThanOrEqual(8);
    expect(spacing!.hintWidth).toBeGreaterThan(160);

    // 文本域必须撑满控件行，不能缩成一段窄条
    const textarea = page.locator('.ticket-pre-reply-form .t-form__item').nth(2);
    const textareaWidth = await textarea.evaluate((element) => {
      const controls = element.querySelector('.t-form__controls-content');
      const field = element.querySelector('.t-textarea');
      if (!controls || !field) return null;

      return {
        controls: controls.getBoundingClientRect().width,
        field: field.getBoundingClientRect().width,
      };
    });
    expect(textareaWidth).not.toBeNull();
    expect(Math.abs(textareaWidth!.controls - textareaWidth!.field)).toBeLessThanOrEqual(2);
  } else {
    // 窄屏：页头改为纵向，保存按钮占满整行
    const toolbarColumn = await toolbar.evaluate((element) => {
      const button = element.querySelector('.t-button');
      return {
        direction: window.getComputedStyle(element).flexDirection,
        buttonWidth: button?.getBoundingClientRect().width || 0,
        toolbarWidth: element.getBoundingClientRect().width,
      };
    });
    expect(toolbarColumn.direction).toBe('column');
    expect(toolbarColumn.buttonWidth).toBeGreaterThan(toolbarColumn.toolbarWidth - 8);

    // 说明文字在窄屏整段换行，不与开关挤在同一行
    const switchRow = page.locator('.ticket-pre-reply-form .t-form__item').first();
    const stacked = await switchRow.evaluate((element) => {
      const switchElement = element.querySelector('.t-switch');
      const hint = element.querySelector('.ticket-pre-reply-hint');
      if (!switchElement || !hint) return null;

      const switchRect = switchElement.getBoundingClientRect();
      const hintRect = hint.getBoundingClientRect();

      return { hintTop: hintRect.top, switchBottom: switchRect.bottom };
    });
    expect(stacked).not.toBeNull();
    expect(stacked!.hintTop).toBeGreaterThanOrEqual(stacked!.switchBottom - 1);
  }

  // 整页不应出现横向溢出
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1);

  expect(pageErrors, `渲染期不应有未捕获错误：${pageErrors.join(' | ')}`).toEqual([]);

  await page.screenshot({
    path: `${process.env.TEMP || '.'}\\turaidc-admin-pre-reply-${testInfo.project.name}.png`,
    fullPage: true,
  });
});
