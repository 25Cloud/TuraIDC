import { expect, test } from '@playwright/test';

/**
 * AuthShell 暗色主题回归：登录/注册/找回密码页共用该外壳，
 * 其暗色规则曾因 :deep(:root[...]) 编译失效而整页保持亮色。
 * 断言 html[theme-mode="dark"] 下 --auth-bg-start 为暗色值，切回浅色恢复亮色值。
 */
test('AuthShell 暗色主题切换背景变量', async ({ page }) => {
  await page.goto('/client/login');

  const shell = page.locator('.auth-shell');
  await expect(shell).toBeVisible();

  const readBg = () => shell.evaluate((el) => getComputedStyle(el).getPropertyValue('--auth-bg-start').trim());
  // 初始浅色（本地默认无持久化主题）
  expect(await readBg()).toBe('#f8fafc');

  // 切暗色：html 加 theme-mode="dark"
  await page.evaluate(() => document.documentElement.setAttribute('theme-mode', 'dark'));
  await page.waitForTimeout(100);
  expect(await readBg()).toBe('#0b1220');

  // 切回浅色
  await page.evaluate(() => document.documentElement.removeAttribute('theme-mode'));
  await page.waitForTimeout(100);
  expect(await readBg()).toBe('#f8fafc');
});
