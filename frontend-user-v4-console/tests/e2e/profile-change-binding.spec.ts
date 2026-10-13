import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

const BOUND_PHONE = '13800000000';
const BOUND_EMAIL = 'e2e@example.com';

// 未绑定手机号/邮箱的场景：securityItems 会把入口文案切成「前往绑定」，
// 此时后端 UpdatePhoneRequest 的 old_code 是 nullable，弹窗也不该再要原号码取证。
const UNBOUND_USER = {
  id: 1,
  name: 'e2e用户',
  nickname: 'e2e用户',
  email: '',
  phone: '',
  cash_balance: '0.00',
  is_verified: 0,
  created_at: '2026-08-20 10:00:00',
};

const BOUND_USER = {
  ...UNBOUND_USER,
  email: BOUND_EMAIL,
  phone: BOUND_PHONE,
};

interface ApiStub {
  onSendPhoneCode?: (body: Record<string, unknown>) => void;
  onSendEmailCode?: (body: Record<string, unknown>) => void;
  onUpdatePhone?: (body: Record<string, unknown>) => void;
  onUpdateEmail?: (body: Record<string, unknown>) => void;
}

// 装配账户安全分区用到的接口桩。captcha-config 返回空对象，useGeeTestCaptcha
// 解析出 enabled=false，发码时不会真的唤起人机验证组件。
async function mockClientApi(page: Page, stub: ApiStub = {}, user = BOUND_USER) {
  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'profile-binding-test-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond(user);
    }
    if (url.pathname.endsWith('/auth/notification-preferences')) {
      return respond({});
    }
    if (url.pathname.endsWith('/auth/phone-code') && method === 'POST') {
      stub.onSendPhoneCode?.(route.request().postDataJSON() as Record<string, unknown>);
      return respond({});
    }
    if (url.pathname.endsWith('/auth/email-code') && method === 'POST') {
      stub.onSendEmailCode?.(route.request().postDataJSON() as Record<string, unknown>);
      return respond({});
    }
    if (url.pathname.endsWith('/auth/phone') && method === 'PUT') {
      stub.onUpdatePhone?.(route.request().postDataJSON() as Record<string, unknown>);
      return respond({ phone: '15900000000' });
    }
    if (url.pathname.endsWith('/auth/email') && method === 'PUT') {
      stub.onUpdateEmail?.(route.request().postDataJSON() as Record<string, unknown>);
      return respond({ email: 'new@example.com' });
    }

    return respond({});
  });
}

async function openSecurityTab(page: Page) {
  await page.goto('/client/profile', { waitUntil: 'domcontentloaded' });
  await page.locator('.t-menu__item', { hasText: '账户安全' }).click();
  await expect(page.locator('.security-list')).toBeVisible();
}

/**
 * 按 label 精确匹配一个表单项。
 *
 * 两个坑：
 * 1. 不能用 `.t-form__item` + `hasText: '原手机号'`：「原手机号」与「原手机号验证码」
 *    两项都会命中，定位会返回两个节点。改用正则锚定 label 文本本身。
 * 2. 不能写成 `.t-form__item`.filter({ has: dialog.locator('.t-form__label', ...) })：
 *    Playwright 的 has 内部定位器相对**外层元素**解析，带上 .t-dialog 前缀后
 *    实际在找 `.t-form__item .t-dialog .t-form__label`，恒为空。
 */
function formItem(dialog: ReturnType<Page['locator']>, label: string) {
  return dialog.locator('.t-form__label', { hasText: new RegExp(`^\\s*${label}\\s*$`) }).locator('..');
}

function fieldInput(dialog: ReturnType<Page['locator']>, label: string) {
  return formItem(dialog, label).locator('input');
}

function sendCodeButton(dialog: ReturnType<Page['locator']>, label: string) {
  return formItem(dialog, label).getByRole('button', { name: /发送验证码|^\d+s$/ });
}

async function openPhoneDialog(page: Page, actionLabel = '更换绑定') {
  await page.locator('.security-item', { hasText: '安全手机' }).getByRole('button', { name: actionLabel }).click();
  const dialog = page.locator('.t-dialog').filter({ hasText: /绑定手机/ });
  await expect(dialog).toBeVisible();
  return dialog;
}

async function openEmailDialog(page: Page, actionLabel = '更换绑定') {
  await page.locator('.security-item', { hasText: '安全邮箱' }).getByRole('button', { name: actionLabel }).click();
  const dialog = page.locator('.t-dialog').filter({ hasText: /绑定邮箱/ });
  await expect(dialog).toBeVisible();
  return dialog;
}

// 后端 UpdatePhoneRequest 在已绑定手机号时把 old_code 定为 required，控制器
// 也会 verifyPhoneCode(旧号码, old_code)。此前弹窗模板只画了新号码与新验证码，
// 用户被卡在「必须填 old_code 却无框可填」的死角，且没有任何入口向原号码发码。
test('更换绑定手机：已绑定时给出原手机号与原号码发码入口', async ({ page }) => {
  const sent: Record<string, unknown>[] = [];
  await mockClientApi(page, { onSendPhoneCode: (body) => sent.push(body) });
  await openSecurityTab(page);
  const dialog = await openPhoneDialog(page);

  await expect(fieldInput(dialog, '原手机号')).toHaveValue(BOUND_PHONE);
  await expect(fieldInput(dialog, '原手机号验证码')).toBeEditable();

  await sendCodeButton(dialog, '原手机号验证码').click();
  await expect(page.locator('.t-message').filter({ hasText: '验证码已发送至原手机号' })).toBeVisible();
  expect(sent).toHaveLength(1);
  // purpose 必须走 verify_bound_phone：后端据此校验目标就是当前绑定号码，
  // 换成 change_phone 会被 SendPhoneCodeRequest 之外的分支放行到任意号码。
  expect(sent[0]).toMatchObject({ phone: BOUND_PHONE, purpose: 'verify_bound_phone' });
  // 发码后进入倒计时，防止连点
  await expect(sendCodeButton(dialog, '原手机号验证码')).toHaveText(/^\d+s$/);
});

test('更换绑定手机：缺原号码验证码时被本地拦下且不发请求', async ({ page }) => {
  let putCalls = 0;
  await mockClientApi(page, { onUpdatePhone: () => (putCalls += 1) });
  await openSecurityTab(page);
  const dialog = await openPhoneDialog(page);

  await fieldInput(dialog, '新手机号').fill('15900000000');
  await fieldInput(dialog, '验证码').fill('123456');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '请输入原手机号验证码' })).toBeVisible();
  await expect(dialog).toBeVisible();
  expect(putCalls).toBe(0);
});

test('更换绑定手机：新旧验证码齐备后携带 old_code 提交', async ({ page }) => {
  const payloads: Record<string, unknown>[] = [];
  await mockClientApi(page, { onUpdatePhone: (body) => payloads.push(body) });
  await openSecurityTab(page);
  const dialog = await openPhoneDialog(page);

  await fieldInput(dialog, '原手机号验证码').fill('654321');
  await fieldInput(dialog, '新手机号').fill('15900000000');
  await fieldInput(dialog, '验证码').fill('123456');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '手机号修改成功' })).toBeVisible();
  expect(payloads).toHaveLength(1);
  expect(payloads[0]).toMatchObject({
    phone: '15900000000',
    code: '123456',
    old_code: '654321',
  });
});

// 邮箱换绑是同一处模板缺陷的复制：emailForm.oldCode 同样从未被渲染，
// 而 submitEmailChange 与后端 UpdateEmailRequest 都要求它。
test('更换绑定邮箱：已绑定时给出原邮箱与原邮箱发码入口', async ({ page }) => {
  const sent: Record<string, unknown>[] = [];
  await mockClientApi(page, { onSendEmailCode: (body) => sent.push(body) });
  await openSecurityTab(page);
  const dialog = await openEmailDialog(page);

  await expect(fieldInput(dialog, '原邮箱')).toHaveValue(BOUND_EMAIL);
  await expect(fieldInput(dialog, '原邮箱验证码')).toBeEditable();

  await sendCodeButton(dialog, '原邮箱验证码').click();
  await expect(page.locator('.t-message').filter({ hasText: '验证码已发送至原邮箱' })).toBeVisible();
  expect(sent).toHaveLength(1);
  expect(sent[0]).toMatchObject({ email: BOUND_EMAIL, purpose: 'verify_bound_email' });
});

test('更换绑定邮箱：缺原邮箱验证码时被本地拦下且不发请求', async ({ page }) => {
  let putCalls = 0;
  await mockClientApi(page, { onUpdateEmail: () => (putCalls += 1) });
  await openSecurityTab(page);
  const dialog = await openEmailDialog(page);

  await fieldInput(dialog, '新邮箱').fill('new@example.com');
  await fieldInput(dialog, '验证码').fill('123456');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '请输入原邮箱验证码' })).toBeVisible();
  await expect(dialog).toBeVisible();
  expect(putCalls).toBe(0);
});

// 未绑定时后端 old_code 是 nullable，多要一个输入框反而是错的。
test('未绑定手机号：不索取原号码验证码即可直接绑定', async ({ page }) => {
  const payloads: Record<string, unknown>[] = [];
  await mockClientApi(page, { onUpdatePhone: (body) => payloads.push(body) }, UNBOUND_USER);
  await openSecurityTab(page);
  const dialog = await openPhoneDialog(page, '前往绑定');

  await expect(fieldInput(dialog, '原手机号')).toHaveCount(0);
  await expect(fieldInput(dialog, '原手机号验证码')).toHaveCount(0);
  await expect(dialog.locator('.t-dialog__header')).toHaveText('绑定手机号');

  await fieldInput(dialog, '新手机号').fill('15900000000');
  await fieldInput(dialog, '验证码').fill('123456');
  await dialog.getByRole('button', { name: '确定' }).click();

  await expect(page.locator('.t-message').filter({ hasText: '手机号修改成功' })).toBeVisible();
  expect(payloads).toHaveLength(1);
  expect(payloads[0]).toMatchObject({ phone: '15900000000', code: '123456' });
  expect(payloads[0].old_code).toBeUndefined();
});
