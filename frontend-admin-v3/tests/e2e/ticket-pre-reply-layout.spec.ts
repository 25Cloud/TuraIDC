import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 「工单预回复设置」页排版回归。
 *
 * 此前该页的问题：只有一枚「保存设置」贴在卡片右上角、开关与说明文字挤在同一行、
 * 文本域宽度与下拉框各自为政。现在统一成「标签一行 → 说明一行 → 控件一行」，
 * 保存按钮收进页面底部的固定操作条。这里锁定：
 *  1) 卡片内页头只放标题与整页说明；
 *  2) 开关行标签与开关同行、说明独占下一行；
 *  3) 文本域撑满控件行；
 *  4) 保存操作条 sticky 在视口底部右对齐，窄屏按钮铺满整行；
 *  5) 整页无横向溢出、渲染期无未捕获错误。
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

test('工单预回复设置页：字段三段排版、文本域撑满、保存条吸底', async ({ page }, testInfo) => {
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await seedAdminSession(page);
  await mockAdminApi(page);
  await page.goto('/admin/ticket-pre-reply-settings', { waitUntil: 'domcontentloaded' });

  const toolbar = page.locator('.ticket-pre-reply-toolbar');
  await expect(toolbar).toBeVisible({ timeout: 15000 });
  await expect(page.locator('.ticket-pre-reply-summary strong')).toHaveText('工单预回复');

  // 保存按钮在底部固定操作条里，不再挤在卡片标题行
  const actions = page.locator('.ticket-pre-reply-actions');
  await expect(actions.getByRole('button', { name: '保存设置' })).toBeVisible();

  const actionsLayout = await actions.evaluate((element) => {
    const style = window.getComputedStyle(element);
    const button = element.querySelector('.t-button');
    const barRect = element.getBoundingClientRect();

    return {
      position: style.position,
      bottom: style.bottom,
      justifyContent: style.justifyContent,
      barWidth: barRect.width,
      barRight: barRect.right,
      buttonWidth: button ? button.getBoundingClientRect().width : 0,
      buttonRight: button ? button.getBoundingClientRect().right : 0,
    };
  });
  expect(actionsLayout.position, '保存条应吸在视口底部').toBe('sticky');
  expect(actionsLayout.bottom).toBe('0px');
  expect(actionsLayout.justifyContent, '保存按钮右对齐').toBe('flex-end');

  if (testInfo.project.name === 'desktop') {
    // 桌面：按钮靠右，与操作条右边缘只隔内边距
    expect(actionsLayout.barRight - actionsLayout.buttonRight).toBeLessThanOrEqual(40);
    expect(actionsLayout.buttonWidth).toBeLessThan(actionsLayout.barWidth);
  } else {
    // 窄屏：按钮铺满整行，不被挤成小点
    expect(actionsLayout.buttonWidth).toBeGreaterThan(actionsLayout.barWidth - 40);
  }

  // 四个字段：启用开关 / 预回复管理员 / 回复内容 / 上游预回复内容
  await expect(page.locator('.ticket-pre-reply-form .admin-field')).toHaveCount(4);

  // 开关行：标签在左、开关在右同一行，说明落到下一行整行
  const switchField = page.locator('.ticket-pre-reply-form .admin-field--switch').first();
  const switchLayout = await switchField.evaluate((element) => {
    const label = element.querySelector('.admin-field__label')!.getBoundingClientRect();
    const control = element.querySelector('.admin-field__control')!.getBoundingClientRect();
    const description = element.querySelector('.admin-field__desc')!.getBoundingClientRect();

    return {
      labelTop: label.top,
      labelRight: label.right,
      controlLeft: control.left,
      controlTop: control.top,
      controlBottom: control.bottom,
      descTop: description.top,
      descWidth: description.width,
    };
  });
  expect(Math.abs(switchLayout.labelTop - switchLayout.controlTop), '开关应与标签同一行').toBeLessThanOrEqual(2);
  expect(switchLayout.controlLeft, '开关应排在标签右侧').toBeGreaterThan(switchLayout.labelRight);
  expect(switchLayout.descTop, '说明应独占下一行').toBeGreaterThanOrEqual(switchLayout.controlBottom - 1);
  expect(switchLayout.descWidth, '说明应占整行，不被压成窄条').toBeGreaterThan(160);

  // 说明写在控件上方，placeholder 只留示例值
  const upstreamField = page.locator('.ticket-pre-reply-form .admin-field').nth(3);
  await expect(upstreamField.locator('.admin-field__desc')).toHaveText(
    '仅对会传递到上游的工单生效，留空则回退上方内容',
  );
  await expect(upstreamField.locator('textarea')).toHaveAttribute(
    'placeholder',
    '例如：该工单已同步给上游服务商，请耐心等待处理。',
  );

  // 文本域必须撑满控件行，不能缩成一段窄条
  const textareaField = page.locator('.ticket-pre-reply-form .admin-field').nth(2);
  const textareaWidth = await textareaField.evaluate((element) => {
    const control = element.querySelector('.admin-field__control')!;
    const field = element.querySelector('.t-textarea');
    if (!field) return null;

    return {
      control: control.getBoundingClientRect().width,
      field: field.getBoundingClientRect().width,
    };
  });
  expect(textareaWidth).not.toBeNull();
  expect(Math.abs(textareaWidth!.control - textareaWidth!.field)).toBeLessThanOrEqual(2);

  if (testInfo.project.name === 'desktop') {
    // 表单宽度收敛，不铺满整张卡片，也不窄到把说明挤成一条
    const formWidth = await page.locator('.ticket-pre-reply-form').evaluate((element) => ({
      width: element.getBoundingClientRect().width,
      maxWidth: Number.parseFloat(window.getComputedStyle(element).maxWidth || '0'),
    }));
    expect(formWidth.width).toBeLessThanOrEqual(900);
  } else {
    // 窄屏：收窄的控件修饰符失效，控件铺满整行
    const controlWidth = await page
      .locator('.ticket-pre-reply-form .admin-field__control--medium')
      .first()
      .evaluate((element) => window.getComputedStyle(element).maxWidth);
    expect(controlWidth).toBe('none');
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
