import { expect, test } from '@playwright/test';

/**
 * 演示站路由巡检：三端各挑几条主路径走一遍，断言「没有 JS 报错、没有落到兜底数据、
 * 页面上确实有示例内容」。
 *
 * 与 `demo-site.spec.ts` 的分工：那个验证四个入口「能不能进、进到哪」，
 * 这个验证「点进去之后有没有东西」。
 *
 * 需要先构建产物：`node backend/scripts/build_demo.mjs`。
 */
const ROUTES: Array<{ app: string; path: string; expect: string }> = [
  { app: 'user', path: '/#/', expect: '云服务器' },
  { app: 'user', path: '/#/products', expect: '个商品' },
  { app: 'console', path: '/console/#/client/dashboard', expect: '1286.40' },
  { app: 'console', path: '/console/#/client/services', expect: '演示香港' },
  { app: 'console', path: '/console/#/client/invoices', expect: 'DEMO2026' },
  { app: 'console', path: '/console/#/client/tickets', expect: '演示工单' },
  { app: 'console', path: '/console/#/client/profile', expect: '演示客户' },
  { app: 'admin', path: '/admin/#/admin/dashboard', expect: '今日营业额' },
  { app: 'admin', path: '/admin/#/admin/products', expect: '香港轻量云' },
  { app: 'admin', path: '/admin/#/admin/users', expect: '演示客户' },
  { app: 'admin', path: '/admin/#/admin/finance/orders', expect: '订单' },
];

test('every demo route renders fixture content without falls or errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    // TDesign 的图标 webfont 走自家 CDN，是演示站唯一允许的外网请求（见设计文档）
    if (message.type() === 'error' && !message.text().includes('tdesign.gtimg.com')) {
      errors.push(message.text());
    }
  });

  // 控制台先登录一次，后面才能在登录态里巡检
  await page.goto('/console/#/client/login', { waitUntil: 'load' });
  await page.waitForTimeout(1500);
  await page.locator('.client-auth-form button').last().click({ force: true });
  await page.waitForTimeout(2500);

  const failures: string[] = [];

  for (const route of ROUTES) {
    await page.goto(route.path, { waitUntil: 'load' });
    await page.waitForTimeout(1600);

    const state = await page.evaluate(() => {
      const diagnostics = (window as unknown as { __DEMO_DIAGNOSTICS__?: { misses: string[] } }).__DEMO_DIAGNOSTICS__;

      return {
        misses: [...new Set(diagnostics?.misses ?? [])],
        text: (document.body.textContent || '').replace(/\s+/g, ' '),
      };
    });

    console.log(`ROUTE ${route.path} misses=${JSON.stringify(state.misses)} has=${state.text.includes(route.expect)}`);

    if (state.misses.length > 0) {
      failures.push(`${route.path} 有未命中接口：${state.misses.join(', ')}`);
    }
    if (!state.text.includes(route.expect)) {
      failures.push(`${route.path} 页面上没有「${route.expect}」`);
    }
  }

  expect(failures, failures.join(' | ')).toEqual([]);
  expect(errors, `出现 JS 报错：${errors.join(' | ')}`).toEqual([]);
});
