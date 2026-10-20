import { Buffer as BufferFrom } from 'node:buffer';

import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 一级分组图标配置（管理端商品目录 → 管理一级分组）。
 *
 * 覆盖：
 * 1. 列表预览按后端 icon_resolved 渲染：icon→内置图标、text→文字徽标、image→缩略图、abbr→名称缩写；
 * 2. 编辑表单能回填 icon_text / icon_image，图片上传走媒体库接口；
 * 3. 保存时把 icon_text / icon_image 提交给 /v2/admin/product-types/{value}。
 */

const IMAGE_DATA_URI =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

const UPLOADED_IMAGE = '/media/group-icon-e2e.png';

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
  icon_resolved: MockIconResolved;
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
      icon_resolved: { mode: 'icon', image: null, text: null, icon_name: 'Server', abbr: '云服' },
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
      value: 'game',
      label: '游戏云',
      product_type: 'game_cloud',
      usage_count: 1,
      group_count: 0,
      icon: null,
      icon_text: null,
      icon_image: IMAGE_DATA_URI,
      icon_resolved: { mode: 'image', image: IMAGE_DATA_URI, text: null, icon_name: null, abbr: '游戏' },
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

  const uploadedRequests: string[] = [];

  await page.route('**/api/v2/admin/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond({ admin: { id: 1, username: 'icon-test', nickname: 'icon-test', permissions: ['*'] } });
    }

    if (url.pathname.endsWith('/product-types')) {
      return respond({ list: productTypes() });
    }

    if (url.pathname.endsWith('/product-groups/tree')) {
      return respond({ tree: [], total: 0 });
    }

    if (url.pathname.endsWith('/products')) {
      return respond({ list: [], total: 0, page: 1, page_size: 20 });
    }

    // 媒体库上传：返回站内相对路径 + 可访问地址
    if (url.pathname.endsWith('/media-files') && route.request().method() === 'POST') {
      uploadedRequests.push('upload');
      return respond({
        id: 9001,
        filename: 'group-icon-e2e.png',
        path: UPLOADED_IMAGE,
        url: `http://api.example.test${UPLOADED_IMAGE}`,
        mime_type: 'image/png',
        type: 'image',
        size: 68,
      });
    }

    return respond({});
  });

  return { uploadedRequests };
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
  test('列表按 icon_resolved 预览四类图标，编辑表单回填并可上传图片保存', async ({ page }) => {
    const { uploadedRequests } = await mockAdminApi(page);
    const dialog = await openTypeManager(page);

    const items = dialog.locator('.type-manager-item');
    await expect(items).toHaveCount(4);

    // 内置图标：渲染 TDesign 图标
    await expect(items.nth(0).locator('.type-manager-item__group-icon.is-icon .t-icon')).toHaveCount(1);
    // 文字图标
    await expect(items.nth(1).locator('.type-manager-item__group-icon.is-text')).toHaveText('轻量');
    // 图片图标
    await expect(items.nth(2).locator('.type-manager-item__group-icon.is-image img')).toHaveAttribute(
      'src',
      IMAGE_DATA_URI,
    );
    // 自动缩写
    await expect(items.nth(3).locator('.type-manager-item__group-icon.is-abbr')).toHaveText('双线');

    // 编辑「轻量云」：文字图标回填 + 图片上传 + 保存 payload
    await items.nth(1).getByRole('button', { name: '编辑一级分组' }).click();
    await expect(dialog.getByText('文字图标', { exact: true })).toBeVisible();
    await expect(dialog.getByText('图片图标', { exact: true })).toBeVisible();

    const iconTextInput = dialog.locator('.type-group-icon-form-item').first().locator('input');
    await expect(iconTextInput).toHaveValue('轻量');

    // 上传图片（隐藏的 file input，走媒体库接口）
    await dialog.locator('.group-icon-file-input').setInputFiles({
      name: 'group-icon-e2e.png',
      mimeType: 'image/png',
      buffer: BufferFrom.from(IMAGE_DATA_URI.split(',')[1], 'base64'),
    });
    await expect.poll(() => uploadedRequests.length).toBe(1);
    await expect(dialog.locator('.group-icon-image-row .group-icon-preview img')).toHaveAttribute(
      'src',
      /group-icon-e2e\.png/,
    );

    // 保存：捕获提交给商品类型更新接口的图标字段
    const updateRequest = page.waitForRequest((request) => {
      const url = new URL(request.url());
      return request.method() === 'PUT' && url.pathname.includes('/product-types/');
    });
    await dialog.getByRole('button', { name: '保存' }).click();
    const payload = (await updateRequest).postDataJSON() as Record<string, unknown>;

    expect(payload.icon_text).toBe('轻量');
    expect(payload.icon_image).toBe(UPLOADED_IMAGE);
  });
});
