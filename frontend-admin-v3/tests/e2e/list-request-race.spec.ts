import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 管理端列表页请求竞态防护回归。
 *
 * 用户管理页此前只写 `loading = true/false`，没有请求序号：管理员连续切筛选
 * （关键字、状态、分页）时，先发出的**慢**响应可能晚到，并覆盖后发的新数据——
 * 界面表现为「筛选条件已经变了，列表还是上一次的结果」。
 *
 * 用例把首次列表请求挂起，让第二次（带关键字的）先返回并渲染，再放行第一次，
 * 断言旧数据被丢弃。修复前这条会红：放开旧请求后列表会回退成旧邮箱。
 */

const OLD_EMAIL = 'race-old@example.test';
const NEW_EMAIL = 'race-new@example.test';

async function seedAdminSession(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'list-request-race-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });
}

test('用户列表：慢的旧响应不覆盖新筛选结果', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', '列表页在桌面端列更稳定');

  await seedAdminSession(page);

  const releases: Array<() => void> = [];
  let userListCalls = 0;

  await page.route('**/api/v2/admin/**', async (route) => {
    const url = new URL(route.request().url());
    const reply = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return reply({
        admin: { id: 1, username: 'race-admin', nickname: '竞态管理员', permissions: ['*'] },
        features: {},
      });
    }

    if (url.pathname === '/api/v2/admin/users') {
      const current = userListCalls;

      userListCalls += 1;
      // 首次请求挂起，直到用例显式放行
      if (current === 0) {
        await new Promise<void>((resolve) => releases.push(resolve));
      }

      const email = current === 0 ? OLD_EMAIL : NEW_EMAIL;

      return reply({
        list: [{ id: 900 + current, email, nickname: email, status: 1, cash_balance: '0.00' }],
        total: 1,
      });
    }

    return reply({ list: [], total: 0, page: 1, page_size: 20 });
  });

  await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#main-content')).toBeVisible();

  // 同步点：必须确认首个请求已进入 handler 并被挂起；否则第二次点的过滤器
  // 可能才是有序的第一个请求，用例在「竞态还没形成」的时序下也会通过，等于没测到。
  await expect.poll(() => releases.length, { message: '首个列表请求应已被挂起' }).toBeGreaterThan(0);

  // 第二次请求：搜关键字，它的响应先返回并渲染
  const keywordInput = page.getByPlaceholder('搜索用户ID/邮箱/手机号/昵称/真实姓名/QQ/身份证');
  await keywordInput.fill('new');
  await keywordInput.press('Enter');

  await expect(page.locator('.users-account em').first()).toHaveText(NEW_EMAIL);

  // 放行第一次（旧）请求：它已是过期请求，必须被整段丢弃
  releases.forEach((release) => release());
  await page.waitForTimeout(400);

  await expect(page.locator('.users-account em').first()).toHaveText(NEW_EMAIL);
  await expect(page.locator('.users-account em').first()).not.toHaveText(OLD_EMAIL);
});
