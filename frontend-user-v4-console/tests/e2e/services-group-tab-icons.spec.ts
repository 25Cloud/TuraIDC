import type { Page, Route } from '@playwright/test';
import { expect, test } from '@playwright/test';

/**
 * 实例管理分类 tab 的一级分组图标渲染。
 *
 * 后端 grouped-overview 的 catalog_types 会带 icon_resolved（内置图标 > 文字图标 > 名称缩写），
 * 图片图标已下线：mode=image 的历史缓存也不能渲染 <img>，必须降级为文字/缩写，不能出现破图或空图标。
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
  icon_resolved?: MockIconResolved | null;
}

const CATALOG_TYPES: MockCatalogType[] = [
  {
    label: '云服务器',
    value: 'vps',
    count: 1,
    icon: 'server',
    icon_text: null,
    icon_image: null,
    icon_resolved: { mode: 'icon', image: null, text: null, icon_name: 'server', abbr: '云服' },
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
    // 老缓存缺 icon_resolved：历史 Pascal 值 Monitor 应归一成 desktop
    label: '虚拟主机',
    value: 'host',
    count: 1,
    icon: 'Monitor',
    icon_text: null,
    icon_image: null,
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
    // 历史 icon_text 别名 platform 应归一成 control-platform
    label: '平台旧数据',
    value: 'legacy',
    count: 1,
    icon: null,
    icon_text: 'platform',
    icon_image: null,
  },
  {
    // 历史图片模式：已下线，降级为文字徽标，不渲染 <img>
    label: '图片旧数据',
    value: 'legacy-image',
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
      return respond({ total: 6, category_total: 6, list: [], catalog_types: CATALOG_TYPES });
    }

    if (url.pathname.endsWith('/services')) {
      return respond({ list: [], total: 0, page: 1, page_size: 10 });
    }

    return respond({});
  });
}

test.describe('实例管理分类 tab 图标', () => {
  test('按规范名渲染内置图标 / 文字 / 缩写，历史别名可解析且不渲染图片', async ({ page }) => {
    await mockServicesApi(page);
    await page.goto('/client/services');

    const tabs = page.locator('.service-group-tabs .service-group-tab');
    await expect(tabs).toHaveCount(7);

    // 内置 TDesign 图标模式
    await expect(tabs.nth(1).locator('.service-group-tab__icon--tdesign .t-icon')).toHaveCount(1);
    // 文字图标模式：文字徽标
    await expect(tabs.nth(2).locator('.service-group-tab__icon--text')).toHaveText('游戏');
    // 老缓存 icon='Monitor' → 规范名 desktop，仍渲染内置图标
    await expect(tabs.nth(3).locator('.service-group-tab__icon--tdesign .t-icon')).toHaveCount(1);
    // 名称缩写模式
    await expect(tabs.nth(4).locator('.service-group-tab__icon--text')).toHaveText('双线');
    // 历史 icon_text 别名 platform → control-platform，渲染内置图标
    await expect(tabs.nth(5).locator('.service-group-tab__icon--tdesign .t-icon')).toHaveCount(1);
    // 历史图片模式：降级为文字徽标（缩写），不出现 <img>、不出现破图
    await expect(tabs.nth(6).locator('.service-group-tab__icon--text')).toHaveText('图片');
    await expect(page.locator('.service-group-tab__icon img')).toHaveCount(0);

    // tab 文案与数量仍然完整
    await expect(tabs.nth(1)).toContainText('云服务器');
    await expect(tabs.nth(1)).toContainText('1');
    await expect(page.locator('.service-group-tab.is-active')).toHaveCount(1);
  });
});
