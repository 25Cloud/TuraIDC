import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 账户策略（实名 / 手机 / 邮箱）在控制台的消费口径。
 *
 * 判据只在后端：`auth/info` 下发 `account_policy.missing_items` 与拼好的 `message`，
 * 前端不自己判断「要不要实名」，只按缺失项给提示条/对话框动作。
 */

const USER_INFO = {
  id: 1,
  name: 'e2e用户',
  nickname: 'e2e用户',
  email: 'e2e@example.com',
  phone: '',
  cash_balance: '0.00',
  is_verified: 0,
  created_at: '2026-08-20 10:00:00',
};

/** 后端拼好的整句：前端必须原样展示，不得二次拼接 */
const POLICY_MESSAGE = '继续操作前请先完成实名认证、绑定手机号';

/** 同样用 40301、但没有 data.scope 的另一种拦截（服务面板访问凭证失效） */
const CONSOLE_AREA_MESSAGE = '访问凭证缺失，请刷新页面重试';

type ProfileSaveFailure = 'account_policy' | 'other_40301';

interface MockOptions {
  /** 保存资料接口的失败形态；不传则保存成功 */
  profileSaveFailure?: ProfileSaveFailure;
  /** false 时 auth/info 不下发 account_policy（模拟内存快照为空） */
  includePolicyInInfo?: boolean;
}

interface MockHandle {
  setPolicy: (policy: Record<string, unknown>) => void;
}

async function mockClientApi(page: Page, options: MockOptions = {}): Promise<MockHandle> {
  let accountPolicy: Record<string, unknown> = {
    require_verification: true,
    require_phone: true,
    require_email: false,
    missing_items: ['verification', 'phone'],
    message: POLICY_MESSAGE,
  };
  const includePolicyInInfo = options.includePolicyInInfo !== false;

  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'account-policy-e2e-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const respond = (data: unknown, status = 200) =>
      route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond(includePolicyInInfo ? { ...USER_INFO, account_policy: accountPolicy } : { ...USER_INFO });
    }
    if (url.pathname.endsWith('/auth/notification-preferences')) {
      return respond({});
    }
    if (url.pathname.endsWith('/auth/profile') && method === 'PUT' && options.profileSaveFailure) {
      // 账户策略拦截带结构化 data（scope 是判别位）；另一种 40301 没有 scope
      const body =
        options.profileSaveFailure === 'account_policy'
          ? {
              code: 40301,
              message: POLICY_MESSAGE,
              data: { scope: 'account_policy', scene: 'checkout', missing_items: ['phone'] },
            }
          : { code: 40301, message: CONSOLE_AREA_MESSAGE };

      return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify(body) });
    }

    return respond({});
  });

  return {
    setPolicy: (policy) => {
      accountPolicy = policy;
    },
  };
}

test.describe('账户策略提示条与 40301 兜底', () => {
  test('缺缺失项时提示条出现，文案等于后端 message，动作跳对路由', async ({ page }) => {
    await mockClientApi(page);

    await page.goto('/client/dashboard', { waitUntil: 'domcontentloaded' });

    const banner = page.locator('.account-policy-banner');
    await expect(banner).toBeVisible();
    // 文案原样来自后端，不是前端拼的
    await expect(banner).toContainText(POLICY_MESSAGE);

    await banner.getByRole('button', { name: '去实名' }).click();
    await expect(page).toHaveURL(/\/client\/verification$/);

    // 第二条动作重新进一次页面（不复用上一步的 SPA 状态，避免实名页卸载动画干扰断言）
    await page.goto('/client/dashboard', { waitUntil: 'domcontentloaded' });
    const bannerAgain = page.locator('.account-policy-banner');
    await expect(bannerAgain).toBeVisible();

    // 绑定入口不是独立路由，落点是个人资料页 + ?bind=phone（弹窗本身在下一个用例里验证）
    await bannerAgain.getByRole('button', { name: '去绑定' }).click();
    await expect(page).toHaveURL(/\/client\/profile(\?bind=phone)?$/);
  });

  test('绑定深链 ?bind=phone 会直接打开绑定弹窗', async ({ page }) => {
    await mockClientApi(page);

    // 提示条/40301 对话框的「去绑定」推的就是这个地址
    await page.goto('/client/profile?bind=phone', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('.t-dialog', { hasText: '绑定手机号' })).toBeVisible();
    // 打开后即时清掉 query，刷新不会反复弹
    await expect(page).toHaveURL(/\/client\/profile$/);
  });

  test('补齐后重新拉 info 返回空数组，提示条消失', async ({ page }) => {
    const mock = await mockClientApi(page);

    await page.goto('/client/dashboard', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.account-policy-banner')).toBeVisible();

    // 模拟用户补齐：下一次 auth/info 不再有缺失项
    mock.setPolicy({
      require_verification: false,
      require_phone: false,
      require_email: false,
      missing_items: [],
      message: '',
    });

    await page.reload({ waitUntil: 'domcontentloaded' });

    await expect(page.locator('.account-policy-banner')).toHaveCount(0);
  });

  test('40301 + scope=account_policy：按 data.missing_items 弹对话框（不依赖内存快照），且无重复 toast', async ({
    page,
  }) => {
    // auth/info 故意不下发 account_policy：证明按钮来自错误响应体，而不是内存快照
    await mockClientApi(page, { profileSaveFailure: 'account_policy', includePolicyInInfo: false });

    await page.goto('/client/profile', { waitUntil: 'domcontentloaded' });

    await page.getByRole('button', { name: '保存资料' }).click();

    const dialog = page.locator('.t-dialog', { hasText: POLICY_MESSAGE });
    await expect(dialog).toBeVisible();

    // data.missing_items = ['phone'] → 直达按钮是「去绑定」
    await expect(dialog.getByRole('button', { name: '去绑定' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: '知道了' })).toBeVisible();

    // 该场景完全交给对话框：页面级 catch 不得再弹一条同文案 toast
    await expect(page.locator('.t-message', { hasText: POLICY_MESSAGE })).toHaveCount(0);

    // 直达按钮按缺失项走：phone → 个人资料页绑定弹窗
    await dialog.getByRole('button', { name: '去绑定' }).click();
    await expect(page).toHaveURL(/\/client\/profile(\?bind=phone)?$/);
  });

  test('其他 40301（无 data.scope）不弹账户策略对话框，仍给普通错误提示', async ({ page }) => {
    await mockClientApi(page, { profileSaveFailure: 'other_40301' });

    await page.goto('/client/profile', { waitUntil: 'domcontentloaded' });

    await page.getByRole('button', { name: '保存资料' }).click();

    // 普通错误提示照旧（且只有一条：页面 catch 不再重复弹）
    await expect(page.locator('.t-message', { hasText: CONSOLE_AREA_MESSAGE })).toHaveCount(1);
    // 账户策略对话框不得出现
    await expect(page.locator('.t-dialog', { hasText: '需要补齐账户信息' })).toHaveCount(0);
    await expect(page.locator('.t-dialog', { hasText: CONSOLE_AREA_MESSAGE })).toHaveCount(0);
  });
});
