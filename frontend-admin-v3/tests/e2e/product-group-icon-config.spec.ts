import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 一级分组图标配置（管理端商品目录 → 管理一级分组）。
 *
 * 口径：一级分组图标只允许内置 TDesign 图标（kebab 名），管理端不再有图片上传/图片地址入口。
 *
 * 覆盖：
 * 1. 列表按后端 icon_resolved 渲染内置图标 / 文字图标 / 名称缩写，不出现 <img>；
 * 2. 选择图标 → 保存：PUT 只提交 icon（不再提交 icon_image / icon_text），保存后列表按新图标渲染；
 * 3. 历史 icon_text 别名（platform / Gamepad 等）与老接口缺 icon_resolved 时仍能归一成规范图标名。
 */

interface MockIconResolved {
  mode: 'image' | 'text' | 'icon' | 'abbr';
  image: string | null;
  text: string | null;
  icon_name: string | null;
  abbr: string;
}

interface MockProductType {
  value: string;
  label: string;
  product_type: string;
  usage_count: number;
  group_count: number;
  icon: string | null;
  icon_text: string | null;
  icon_image: string | null;
  icon_resolved?: MockIconResolved | null;
}

function productTypes(): MockProductType[] {
  return [
    {
      value: 'vps',
      label: '云服务器',
      product_type: 'cloud_server',
      usage_count: 2,
      group_count: 1,
      icon: 'Server',
      icon_text: null,
      icon_image: null,
      icon_resolved: { mode: 'icon', image: null, text: null, icon_name: 'server', abbr: '云服' },
    },
    {
      value: 'light',
      label: '轻量云',
      product_type: 'cloud_server',
      usage_count: 1,
      group_count: 0,
      icon: null,
      icon_text: '轻量',
      icon_image: null,
      icon_resolved: { mode: 'text', image: null, text: '轻量', icon_name: null, abbr: '轻量' },
    },
    {
      // 历史数据：早期把图标别名写在 icon_text 列，且老接口没有 icon_resolved
      value: 'legacy-platform',
      label: '平台旧数据',
      product_type: 'other',
      usage_count: 1,
      group_count: 0,
      icon: null,
      icon_text: 'platform',
      icon_image: null,
    },
    {
      // 历史系统值：icon 列是 Pascal 名 Gamepad，老接口没有 icon_resolved
      value: 'legacy-game',
      label: '游戏云旧数据',
      product_type: 'game_cloud',
      usage_count: 1,
      group_count: 0,
      icon: 'Gamepad',
      icon_text: null,
      icon_image: null,
    },
    {
      value: 'dual',
      label: '双线机房',
      product_type: 'other',
      usage_count: 0,
      group_count: 0,
      icon: null,
      icon_text: null,
      icon_image: null,
      icon_resolved: { mode: 'abbr', image: null, text: null, icon_name: null, abbr: '双线' },
    },
  ];
}

async function mockAdminApi(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('admin_token', 'product-group-icon-test-token');
    window.localStorage.setItem('admin_last_active_at', String(Date.now()));
  });

  const types = productTypes();
  const updatePayloads: Array<Record<string, unknown>> = [];

  await page.route('**/api/v2/admin/**', async (route: Route) => {
    const request = route.request();
    const url = new URL(request.url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'icon-test', nickname: 'icon-test', permissions: ['*'] } });
    }

    if (url.pathname.endsWith('/product-types') && request.method() === 'GET') {
      return respond({ list: types });
    }

    if (url.pathname.includes('/product-types/') && request.method() === 'PUT') {
      const value = decodeURIComponent(url.pathname.split('/').pop() || '');
      const payload = (request.postDataJSON() || {}) as Record<string, unknown>;
      updatePayloads.push(payload);

      const target = types.find((item) => item.value === value);
      if (target) {
        target.icon = String(payload.icon || '') || null;
        target.icon_text = null;
        target.icon_resolved = {
          mode: 'icon',
          image: null,
          text: null,
          icon_name: String(payload.icon || ''),
          abbr: target.label.slice(0, 2),
        };
      }

      return respond({ type: target || null });
    }

    if (url.pathname.endsWith('/product-groups/tree')) {
      return respond({ tree: [], total: 0 });
    }

    if (url.pathname.endsWith('/products')) {
      return respond({ list: [], total: 0, page: 1, page_size: 20 });
    }

    return respond({});
  });

  return { updatePayloads };
}

async function openTypeManager(page: Page) {
  await page.goto('/admin/products', { waitUntil: 'domcontentloaded' });
  // 窄屏的管理入口在「筛选分类」抽屉里的类型侧栏中
  if ((page.viewportSize()?.width || 1440) <= 768) {
    await page.getByRole('button', { name: /筛选分类/ }).click();
  }
  await page.getByRole('button', { name: '管理一级分组' }).first().click();
  const dialog = page.locator('.type-manager-dialog');
  await expect(dialog).toBeVisible();
  return dialog;
}

test.describe('一级分组图标配置', () => {
  test('列表按规范化图标名渲染，历史 icon_text 别名可解析且不出现图片', async ({ page }) => {
    await mockAdminApi(page);
    const dialog = await openTypeManager(page);

    const items = dialog.locator('.type-manager-item');
    await expect(items).toHaveCount(5);

    // 内置图标：渲染 TDesign 图标
    await expect(items.nth(0).locator('.type-manager-item__group-icon.is-icon .t-icon')).toHaveCount(1);
    // 文字图标
    await expect(items.nth(1).locator('.type-manager-item__group-icon.is-text')).toHaveText('轻量');
    // 历史 icon_text 别名 platform → control-platform（老接口无 icon_resolved，走前端归一）
    const legacyPlatform = items.nth(2).locator('.type-manager-item__group-icon');
    await expect(legacyPlatform).toHaveClass(/is-icon/);
    await expect(legacyPlatform.locator('.t-icon')).toHaveCount(1);
    await expect(legacyPlatform).toHaveAttribute('title', 'control-platform');
    // 历史 Pascal 值 Gamepad → control-platform
    const legacyGamepad = items.nth(3).locator('.type-manager-item__group-icon');
    await expect(legacyGamepad).toHaveClass(/is-icon/);
    await expect(legacyGamepad).toHaveAttribute('title', 'control-platform');
    // 自动缩写
    await expect(items.nth(4).locator('.type-manager-item__group-icon.is-abbr')).toHaveText('双线');

    // 图片图标已下线：列表没有缩略图，表单也没有上传入口
    await expect(dialog.locator('.type-manager-item__group-icon img')).toHaveCount(0);
    await expect(dialog.locator('.group-icon-file-input')).toHaveCount(0);
    await expect(dialog.getByText('图片图标', { exact: true })).toHaveCount(0);
  });

  test('选择内置图标保存后只提交 icon，列表按新图标渲染', async ({ page }) => {
    const { updatePayloads } = await mockAdminApi(page);
    const dialog = await openTypeManager(page);

    const items = dialog.locator('.type-manager-item');
    // 编辑「轻量云」（当前是文字图标，选中内置图标后覆盖）
    await items.nth(1).getByRole('button', { name: '编辑一级分组' }).click();

    // 只保留图标选择器：没有图片/文字图标配置项
    const iconPicker = dialog.locator('.type-icon-picker');
    await expect(iconPicker).toBeVisible();
    await expect(dialog.getByText('图片图标', { exact: true })).toHaveCount(0);
    await expect(dialog.locator('.group-icon-file-input')).toHaveCount(0);

    // 选择「云服务」图标
    await iconPicker.getByRole('radio', { name: '云服务' }).click();
    await expect(iconPicker.getByRole('radio', { name: '云服务' })).toHaveAttribute('aria-checked', 'true');

    const updateRequest = page.waitForRequest((request) => {
      const url = new URL(request.url());
      return request.method() === 'PUT' && url.pathname.includes('/product-types/');
    });
    await dialog.getByRole('button', { name: '保存' }).click();
    const payload = (await updateRequest).postDataJSON() as Record<string, unknown>;

    // 保存只写 icon：不再提交 icon_image，也不写 icon_text
    expect(payload.icon).toBe('cloud');
    expect('icon_image' in payload).toBe(false);
    expect('icon_text' in payload).toBe(false);
    expect(updatePayloads).toHaveLength(1);

    // 保存后列表刷新：该分组按规范化图标名渲染 TDesign 图标
    const savedItem = dialog.locator('.type-manager-item').nth(1);
    await expect(savedItem.locator('.type-manager-item__group-icon.is-icon .t-icon')).toHaveCount(1);
    await expect(savedItem.locator('.type-manager-item__group-icon')).toHaveAttribute('title', 'cloud');
  });
});
