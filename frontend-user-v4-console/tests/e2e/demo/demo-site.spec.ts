import { expect, test } from '@playwright/test';

const BASE = 'http://127.0.0.1:4190';

interface Snapshot {
  href: string;
  hits: number;
  misses: string[];
  text: string;
}

async function snapshot(page: import('@playwright/test').Page): Promise<Snapshot> {
  return page.evaluate(() => {
    const diagnostics = (window as unknown as { __DEMO_DIAGNOSTICS__?: { misses: string[]; hits: number } })
      .__DEMO_DIAGNOSTICS__;

    return {
      href: location.href,
      hits: diagnostics?.hits ?? 0,
      misses: [...new Set(diagnostics?.misses ?? [])],
      text: (document.body.textContent || '').replace(/\s+/g, ' '),
    };
  });
}

test('homepage renders system site with product data', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });

  await page.goto(`${BASE}/`, { waitUntil: 'load' });
  await page.waitForTimeout(3000);

  const state = await snapshot(page);
  console.log(
    `HOME ${JSON.stringify({
      href: state.href,
      hits: state.hits,
      misses: state.misses,
      text: state.text.slice(0, 260),
    })}`,
  );

  expect(state.misses).toEqual([]);
  expect(await page.locator('#turaidc-demo-nav').count()).toBe(1);
  expect(state.text).toContain('云服务器');
  expect(state.text).toContain('香港');
  expect(errors).toEqual([]);
});

test('console login with demo credentials reaches dashboard with data', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));

  await page.goto(`${BASE}/`, { waitUntil: 'load' });
  await page.waitForTimeout(1200);

  const loginLink = page.locator('a', { hasText: /^登录$/ }).first();
  const href = await loginLink.getAttribute('href');
  console.log(`LOGIN_HREF ${href}`);
  expect(href).toContain('/console/#/client/login');

  await page.goto(`${BASE}/console/#/client/login`, { waitUntil: 'load' });
  await page.waitForTimeout(2000);

  const loginState = await snapshot(page);
  const loginText = loginState.text;
  console.log(
    `LOGIN_PAGE ${JSON.stringify({ href: loginState.href, misses: loginState.misses, text: loginText.slice(0, 300) })}`,
  );
  expect(loginText).toContain('demo123456');

  const submit = page.locator('.client-auth-form t-button, .client-auth-form button').last();
  const submitCount = await page.locator('.client-auth-form button').count();
  console.log(`SUBMIT_BUTTONS ${submitCount}`);
  await submit.click({ force: true, timeout: 15000 });
  await page.waitForTimeout(3000);

  const state = await snapshot(page);
  console.log(
    `CONSOLE ${JSON.stringify({
      href: state.href,
      hits: state.hits,
      misses: state.misses,
      text: state.text.slice(0, 260),
    })}`,
  );

  expect(state.href).toContain('#/client/dashboard');
  expect(state.text).toContain('演示客户');
  expect(state.text).toContain('1286.40');
  expect(state.misses).toEqual([]);
  expect(errors).toEqual([]);
});

test('admin root auto-enters dashboard with data', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));

  await page.goto(`${BASE}/admin/`, { waitUntil: 'load' });
  await page.waitForTimeout(3500);

  const state = await snapshot(page);
  console.log(
    `ADMIN ${JSON.stringify({
      href: state.href,
      hits: state.hits,
      misses: state.misses,
      text: state.text.slice(0, 200),
    })}`,
  );

  expect(state.href).toContain('#/admin/dashboard');
  expect(state.text).toContain('今日营业额');
  expect(errors).toEqual([]);
});

test('start page lists demo accounts', async ({ page }) => {
  await page.goto(`${BASE}/start/`, { waitUntil: 'load' });
  await page.waitForTimeout(800);

  const text = ((await page.locator('body').textContent()) || '').replace(/\s+/g, ' ');
  expect(text).toContain('demo123456');
  expect(text).toContain('admin');
});
