import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 实例管理分类 tab 的一级分组图标渲染。
 *
 * 后端 grouped-overview 的 catalog_types 会带 icon_resolved：
 * mode=image 显示图片；mode=text 显示文字图标；mode=icon 显示内置 TDesign 图标；
 * mode=abbr 显示一级分组名缩写；图片加载失败时回退文字徽标，不能出现破图或报错。
 */

const USER_INFO = {
  id: 1,
  name: 'e2e用户',
  nickname: 'e2e用户',
  email: 'e2e@example.com',
  phone: '13800000000',
  cash_balance: '0.00',
  is_verified: 1,
};

// 1x1 透明 PNG，避免 e2e 依赖外网资源
const IMAGE_DATA_URI =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

interface MockIconResolved {
  mode: 'image' | 'text' | 'icon' | 'abbr';
  image: string | null;
  text: string | null;
  icon_name: string | null;
  abbr: string;
}

interface MockCatalogType {
  label: string;
  value: string;
  count: number;
  icon: string | null;
  icon_text: string | null;
  icon_image: string | null;
  icon_resolved: MockIconResolved;
}

const CATALOG_TYPES: MockCatalogType[] = [
  {
    label: '云服务器',
    value: 'vps',
    count: 1,
    icon: null,
    icon_text: null,
    icon_image: IMAGE_DATA_URI,
    icon_resolved: { mode: 'image', image: IMAGE_DATA_URI, text: null, icon_name: null, abbr: '云服' },
  },
  {
    label: '游戏云',
    value: 'game',
    count: 1,
    icon: null,
    icon_text: '游戏',
    icon_image: null,
    icon_resolved: { mode: 'text', image: null, text: '游戏', icon_name: null, abbr: '游戏' },
  },
  {
    label: '虚拟主机',
    value: 'host',
    count: 1,
    icon: 'Desktop',
    icon_text: null,
    icon_image: null,
    icon_resolved: { mode: 'icon', image: null, text: null, icon_name: 'Desktop', abbr: '虚拟' },
  },
  {
    label: '双线机房',
    value: 'dual',
    count: 1,
    icon: null,
    icon_text: null,
    icon_image: null,
    icon_resolved: { mode: 'abbr', image: null, text: null, icon_name: null, abbr: '双线' },
  },
  {
    label: '图片挂了',
    value: 'broken',
    count: 1,
    icon: null,
    icon_text: null,
    icon_image: '/media/not-exists.png',
    icon_resolved: {
      mode: 'image',
      image: '/media/not-exists.png',
      text: null,
      icon_name: null,
      abbr: '图片',
    },
  },
];

async function mockServicesApi(page: Page) {
  await page.addInitScript(() => {
    window.localStorage.setItem('client_token', 'services-group-tab-icons-token');
    window.localStorage.setItem('client_last_active_at', String(Date.now()));
  });

  await page.route('**/api/v2/client/**', async (route: Route) => {
    const url = new URL(route.request().url());
    const respond = (data: unknown) =>
      route.fulfill({ contentType: 'application/json', body: JSON.stringify({ code: 0, data }) });

    if (url.pathname.endsWith('/auth/info')) {
      return respond(USER_INFO);
    }

    if (url.pathname.endsWith('/services/grouped-overview')) {
      return respond({ total: 5, category_total: 5, list: [], catalog_types: CATALOG_TYPES });
    }

    if (url.pathname.endsWith('/services')) {
      return respond({ list: [], total: 0, page: 1, page_size: 10 });
    }

    return respond({});
  });
}

test.describe('实例管理分类 tab 图标', () => {
  test('按 icon_resolved 渲染图片 / 文字 / 内置图标 / 缩写，图片失败回落文字', async ({ page }) => {
    await mockServicesApi(page);
    await page.goto('/client/services');

    const tabs = page.locator('.service-group-tabs .service-group-tab');
    await expect(tabs).toHaveCount(6);

    // 图片模式：真实 <img> 且 src 正确
    const imageTab = tabs.nth(1);
    await expect(imageTab.locator('.service-group-tab__icon--image img')).toHaveAttribute('src', IMAGE_DATA_URI);

    // 文字图标模式：文字徽标
    const textTab = tabs.nth(2);
    await expect(textTab.locator('.service-group-tab__icon--text')).toHaveText('游戏');

    // 内置 TDesign 图标模式
    const iconTab = tabs.nth(3);
    await expect(iconTab.locator('.service-group-tab__icon--tdesign .t-icon')).toHaveCount(1);

    // 名称缩写模式
    const abbrTab = tabs.nth(4);
    await expect(abbrTab.locator('.service-group-tab__icon--text')).toHaveText('双线');

    // 图片地址 404：回退文字徽标（缩写），不出现破图，也不影响其它 tab
    const brokenTab = tabs.nth(5);
    await expect(brokenTab.locator('.service-group-tab__icon--text')).toHaveText('图片');
    await expect(brokenTab.locator('.service-group-tab__icon--image img')).toHaveCount(0);

    // tab 文案与数量仍然完整
    await expect(tabs.nth(1)).toContainText('云服务器');
    await expect(tabs.nth(1)).toContainText('1');
    await expect(page.locator('.service-group-tab.is-active')).toHaveCount(1);
  });
});
