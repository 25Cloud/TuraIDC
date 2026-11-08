import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 服务控制台「配置项升级（补差价）」链路。
 *
 * 判据全部来自后端：
 * - 可选配置来自 `GET /v2/client/services/{id}/upgrades` 的 `options`
 *   （`ServiceUpgradeService::previewForUser()` 把上游 `/v1/hosts/{id}/actions/upgrade` 的 data 原样透传）；
 * - 差价来自 `POST .../upgrades/quotes` 的 `quote.amount_total`（后端账单金额也取它）；
 * - 提交走 `POST .../upgrades/orders`，返回 `{id, invoice_no, service_id}`，前端按流量包对话框同一套跳支付页。
 *
 * 这里故意用「容器 + 别名」混合形状的 options（`products` 容器、`id` 与 `product_id` 混用），
 * 覆盖前端容错解析；解析不到任何带 id 的条目时必须落到空态，而不是空白对话框。
 */

const SERVICE = {
  id: 1,
  name: 'e2e升级实例',
  status: 1,
  billing_cycle: 'monthly',
  billing_cycle_label: '月付',
  product_spec_display: '2 核 4G',
  specs: [
    { label: 'CPU', value: '2 核' },
    { label: '内存', value: '4 G' },
  ],
  actions: { upgrade: true, refresh: true },
};

/** 上游选项：9001 用 `id` + pricing 字典，9002 用 `product_id` + billing_cycle */
const DEFAULT_OPTIONS = {
  products: [
    { id: 9001, name: 'CN2 高配', pricing: { monthly: '88.80', quarterly: '248.00' } },
    { product_id: 9002, name: 'CN2 旗舰', billing_cycle: 'monthly' },
  ],
};

/** 每个目标配置的报价（后端按 product_id + billing_cycle 返回 quote.amount_total） */
const QUOTES: Record<string, { name: string; amount_total: string }> = {
  '9001': { name: 'CN2 高配', amount_total: '88.80' },
  '9002': { name: 'CN2 旗舰', amount_total: '128.00' },
};

interface MockOptions {
  /** 覆盖 preview 的 options 字段，用来构造空态 */
  previewOptions?: unknown;
}

interface MockHandle {
  /** 让 preview 接口返回 500 + 指定文案 */
  failPreview: (message: string) => void;
  /** 恢复 preview 正常返回 */
  fixPreview: () => void;
}

async function mockClientApi(page: Page, options: MockOptions = {}): Promise<MockHandle> {
  let previewFailure: string | null = null;
  const previewOptions = options.previewOptions ?? DEFAULT_OPTIONS;

  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'service-upgrade-e2e-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    const ok = (data: unknown) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return ok({ id: 1, name: 'e2e用户', nickname: 'e2e用户', email: 'e2e@example.com', cash_balance: '0.00' });
    }

    if (url.pathname.endsWith('/services/1/upgrades') && method === 'GET') {
      if (previewFailure !== null) {
        return route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ code: 50000, message: previewFailure }),
        });
      }

      return ok({
        service_id: 1,
        service_name: 'e2e升级实例',
        upstream_host_id: 778899,
        options: previewOptions,
      });
    }

    if (url.pathname.endsWith('/services/1/upgrades/quotes')) {
      const body = JSON.parse(route.request().postData() || '{}') as { product_id?: number | string };
      const quote = QUOTES[String(body.product_id ?? '')] || { name: String(body.product_id), amount_total: '0.00' };

      return ok({
        service_id: 1,
        product_id: Number(body.product_id || 0),
        billing_cycle: 'monthly',
        quote: { name: quote.name, amount_total: quote.amount_total, billingcycle: 'monthly' },
      });
    }

    if (url.pathname.endsWith('/services/1/upgrades/orders')) {
      return ok({ id: 42, invoice_no: 'INV-UPGRADE-42', service_id: 1 });
    }

    if (url.pathname.endsWith('/services/1') && method === 'GET') {
      return ok({ service: SERVICE });
    }

    return ok({});
  });

  return {
    failPreview: (message) => {
      previewFailure = message;
    },
    fixPreview: () => {
      previewFailure = null;
    },
  };
}

/** 从服务控制台头部的「更多」进入「规格升级」 */
async function openUpgradeDialog(page: Page) {
  await page.goto('/client/services/1', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: '更多' }).click();
  await page.getByText('规格升级', { exact: true }).click();

  const dialog = page.locator('.t-dialog', { hasText: '配置项升级' });
  await expect(dialog).toBeVisible();

  return dialog;
}

test.describe('服务控制台 · 配置项升级（补差价）', () => {
  test('打开对话框展示当前配置与可选配置，切换目标后差价更新，提交跳账单页', async ({ page }) => {
    await mockClientApi(page);

    const dialog = await openUpgradeDialog(page);

    // 当前配置：来自服务详情（实例名 / 规格 / 周期）
    await expect(dialog).toContainText('当前配置');
    await expect(dialog).toContainText('e2e升级实例');
    await expect(dialog).toContainText('月付');

    // 可选配置：options 的 products 容器，id 与 product_id 两种别名都要解析出来
    const optionButtons = dialog.locator('.t-radio-button');
    await expect(optionButtons).toHaveCount(2);
    await expect(dialog).toContainText('CN2 高配');
    await expect(dialog).toContainText('CN2 旗舰');

    // 默认选中第一项并已报价：差价 = quote.amount_total
    await expect(dialog).toContainText('¥88.80');

    // 切到第二项 → 重新 quotes → 差价跟着变
    await optionButtons.nth(1).click();
    await expect(dialog).toContainText('¥128.00');

    // 提交生成账单 → 与流量包对话框一致：跳账单支付页
    await dialog.getByRole('button', { name: '生成升级账单' }).click();
    await expect(page).toHaveURL(/\/client\/invoices\/42\/pay/);
  });

  test('上游没有可用配置时给空态提示，且不允许提交', async ({ page }) => {
    // 全是缺 id 的脏数据：解析不出可选项 → 空态
    await mockClientApi(page, {
      previewOptions: { products: [{ name: '缺少 id 的脏数据' }, { productId: '' }] },
    });

    const dialog = await openUpgradeDialog(page);

    await expect(dialog).toContainText('暂无可升级的配置');
    await expect(dialog.locator('.t-radio-button')).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: '生成升级账单' })).toBeDisabled();
  });

  test('预览失败给可读提示与重试入口，重试成功后正常加载', async ({ page }) => {
    const mock = await mockClientApi(page);
    mock.failPreview('上游连接超时');

    const dialog = await openUpgradeDialog(page);

    // 失败态：直接展示后端给的错误文案，并给「重试」
    await expect(dialog).toContainText('上游连接超时');
    const retryButton = dialog.getByRole('button', { name: '重试' });
    await expect(retryButton).toBeVisible();

    mock.fixPreview();
    await retryButton.click();

    await expect(dialog.locator('.t-radio-button')).toHaveCount(2);
    await expect(dialog).toContainText('¥88.80');
  });
});
