import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 顶栏下拉（mega-menu）回归。
 *
 * 两个线上问题：
 *  1. 进了 /products 后，顶栏「产品」这个已经激活的项被反向处理——悬停时主动收起，
 *     于是「在产品页拉不开产品下拉」，而这恰恰是用户最想横向换目录的时刻；
 *     /notices、/help 同理。
 *  2. 下拉打开后到数据回来之间没有任何占位：先是整块空白（公告/帮助），
 *     产品面板则因为「目录已回来、地区分组还在飞」被当成「取完了且为空」，
 *     先闪一句「暂无产品分类」再被内容顶掉。
 *
 * 顺带钉住两条交互契约：下拉必须能用键盘打开（此前只有 hover 可达），
 * 以及点击导航项只开关面板、不直接跳页，一级入口统一走面板里的「全部产品」。
 */

const PRODUCT_TYPES = {
  list: [
    {
      id: 1,
      value: "vps",
      label: "云服务器",
      first_product_group_code: "vps",
      product_type: "cloud_server",
      product_count: 99,
      group_count: 2,
    },
    {
      id: 2,
      value: "dedicated",
      label: "独立服务器",
      first_product_group_code: "dedicated",
      product_type: "dedicated",
      product_count: 10,
      group_count: 1,
    },
  ],
};

const PRODUCT_GROUPS = {
  vps: [
    {
      id: 1,
      name: "美国",
      slogan: "",
      product_count: 48,
      product_type: "cloud_server",
      first_product_group_code: "vps",
      product_type_id: 1,
      children_count: 4,
    },
    {
      id: 2,
      name: "香港",
      slogan: "",
      product_count: 17,
      product_type: "cloud_server",
      first_product_group_code: "vps",
      product_type_id: 1,
      children_count: 3,
    },
  ],
  dedicated: [
    {
      id: 31,
      name: "韩国",
      slogan: "",
      product_count: 8,
      product_type: "dedicated",
      first_product_group_code: "dedicated",
      product_type_id: 2,
      children_count: 1,
    },
  ],
};

type MockOptions = {
  /** 给地区分组接口加人为延迟，用来观察「还在取数」那段区间 */
  groupsDelayMs?: number;
};

async function mockSiteApi(page: Page, options: MockOptions = {}) {
  await page.route("**/api/v2/site/**", async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname.replace(/^\/api/, "");
    const respond = (data: unknown) =>
      route.fulfill({
        contentType: "application/json; charset=utf-8",
        body: JSON.stringify({ code: 0, message: "ok", data }),
      });

    if (pathname === "/v2/site/config") {
      return respond({ site_name: "测试站点" });
    }
    if (pathname === "/v2/site/product-types") {
      return respond(PRODUCT_TYPES);
    }
    if (pathname === "/v2/site/product-groups") {
      const code = url.searchParams.get("first_product_group_code") || "vps";
      if (options.groupsDelayMs) {
        await new Promise((resolve) =>
          setTimeout(resolve, options.groupsDelayMs),
        );
      }
      return respond({
        list: PRODUCT_GROUPS[code as keyof typeof PRODUCT_GROUPS] || [],
      });
    }
    if (pathname === "/v2/site/notices") {
      return respond({ list: [] });
    }
    if (pathname === "/v2/site/help-articles") {
      return respond({ list: [] });
    }

    return respond({ list: [], total: 0 });
  });
}

function navLink(page: Page, label: string) {
  return page.locator(".main-nav__link", { hasText: label }).first();
}

const megaMenu = (page: Page) => page.locator(".mega-menu");

test.describe("顶栏下拉", () => {
  test("在产品页内悬停「产品」能拉下菜单", async ({ page }) => {
    await mockSiteApi(page);
    await page.goto("/products");

    await navLink(page, "产品").hover();

    await expect(megaMenu(page)).toBeVisible();
    await expect(
      megaMenu(page).locator(".mega-type-btn").first(),
    ).toContainText("云服务器");
    await expect(megaMenu(page).locator(".mega-group-card")).toHaveCount(2);
  });

  test("当前页所属的导航项不再被压制（公告 / 帮助同理）", async ({ page }) => {
    await mockSiteApi(page);

    await page.goto("/notices");
    await navLink(page, "公告").hover();
    await expect(megaMenu(page)).toBeVisible();

    await page.goto("/help");
    await navLink(page, "帮助").hover();
    await expect(megaMenu(page)).toBeVisible();
  });

  test("取数期间显示骨架占位，不闪「暂无产品分类」", async ({ page }) => {
    await mockSiteApi(page, { groupsDelayMs: 2500 });
    await page.goto("/");

    await navLink(page, "产品").hover();

    // 分组还在飞：必须是占位骨架，且任何空态文案都不允许出现
    await expect(
      megaMenu(page).locator(".mega-skeleton-item").first(),
    ).toBeVisible();
    await expect(megaMenu(page).locator(".mega-menu__empty")).toHaveCount(0);
    await expect(page.getByText("暂无产品分类")).toHaveCount(0);

    // 面板高度在占位与真实内容之间保持稳定，不塌陷
    const pendingHeight = (await megaMenu(page).boundingBox())?.height ?? 0;
    expect(pendingHeight).toBeGreaterThan(200);

    await expect(megaMenu(page).locator(".mega-group-card")).toHaveCount(2);
    await expect(megaMenu(page).locator(".mega-skeleton-item")).toHaveCount(0);

    const settledHeight = (await megaMenu(page).boundingBox())?.height ?? 0;
    expect(Math.abs(settledHeight - pendingHeight)).toBeLessThan(40);
  });

  test("键盘可展开、Esc 可收起", async ({ page }) => {
    await mockSiteApi(page);
    await page.goto("/");

    // 从页面顶部一路 Tab 到「产品」，不写死中间有几个可聚焦元素
    for (let i = 0; i < 15; i += 1) {
      await page.keyboard.press("Tab");
      const focused = await navLink(page, "产品").evaluate(
        (el) => el === document.activeElement,
      );
      if (focused) break;
    }

    await expect(navLink(page, "产品")).toBeFocused();
    await expect(megaMenu(page)).toBeVisible();
    await expect(navLink(page, "产品")).toHaveAttribute(
      "aria-expanded",
      "true",
    );

    await page.keyboard.press("Escape");
    await expect(megaMenu(page)).toHaveCount(0);
  });

  test("点击导航项只开关面板不跳页，且面板内有一级入口", async ({ page }) => {
    await mockSiteApi(page);
    await page.goto("/");

    await navLink(page, "产品").click();

    await expect(megaMenu(page)).toBeVisible();
    expect(page.url()).not.toContain("/products");

    const overview = megaMenu(page).locator(".mega-type-more");
    await expect(overview).toHaveText("全部产品 →");

    // 再次点击同一项才收起
    await navLink(page, "产品").click();
    await expect(megaMenu(page)).toHaveCount(0);

    // 一级入口可达
    await navLink(page, "产品").click();
    await overview.click();
    await expect(page).toHaveURL(/\/products$/);
  });

  test("分组接口失败时给「重新加载」，不永久停在空态", async ({ page }) => {
    await mockSiteApi(page);

    let failing = true;
    // 覆盖掉通用 mock：先失败一次，重试再放行
    await page.route("**/api/v2/site/product-groups*", async (route) => {
      if (failing) {
        return route.fulfill({
          status: 500,
          contentType: "application/json; charset=utf-8",
          body: JSON.stringify({ code: 500, message: "boom" }),
        });
      }
      return route.fulfill({
        contentType: "application/json; charset=utf-8",
        body: JSON.stringify({
          code: 0,
          message: "ok",
          data: { list: PRODUCT_GROUPS.vps },
        }),
      });
    });

    await page.goto("/");
    await navLink(page, "产品").hover();

    await expect(megaMenu(page).getByText("分类加载失败")).toBeVisible();
    // 失败不能被当成「取完了且为空」
    await expect(page.getByText("暂无产品分类")).toHaveCount(0);

    failing = false;
    await megaMenu(page).getByRole("button", { name: "重新加载" }).click();

    await expect(megaMenu(page).locator(".mega-group-card")).toHaveCount(2);
    await expect(megaMenu(page).getByText("分类加载失败")).toHaveCount(0);
  });

  test("下拉面板不被长文案撑出容器", async ({ page }) => {
    await mockSiteApi(page);

    // 公告摘要里混入不可断的长片段：1fr 的栅格轨道下限是 auto，会被撑爆
    await page.route("**/api/v2/site/notices*", (route) =>
      route.fulfill({
        contentType: "application/json; charset=utf-8",
        body: JSON.stringify({
          code: 0,
          message: "ok",
          data: {
            list: Array.from({ length: 6 }, (_, index) => ({
              id: index + 1,
              title: `公告标题${index + 1}`,
              summary: `https://example.com/very/long/path/segment-${index}-${"x".repeat(160)}`,
              category: "运维",
            })),
          },
        }),
      }),
    );

    await page.goto("/");
    await navLink(page, "公告").hover();
    await expect(megaMenu(page)).toBeVisible();

    const overflow = await page.evaluate(() => {
      const menu = document.querySelector(".mega-menu") as HTMLElement;
      const inner = menu.querySelector(".mega-menu__inner") as HTMLElement;
      return {
        menu: menu.scrollWidth - menu.clientWidth,
        inner: inner.scrollWidth - inner.clientWidth,
      };
    });
    expect(overflow.menu).toBeLessThanOrEqual(1);
    expect(overflow.inner).toBeLessThanOrEqual(1);
  });

  test("公告接口失败同样给「重新加载」", async ({ page }) => {
    await mockSiteApi(page);

    let failing = true;
    await page.route("**/api/v2/site/notices*", async (route) => {
      if (failing) {
        return route.fulfill({
          status: 500,
          contentType: "application/json; charset=utf-8",
          body: JSON.stringify({ code: 500, message: "boom" }),
        });
      }
      return route.fulfill({
        contentType: "application/json; charset=utf-8",
        body: JSON.stringify({
          code: 0,
          message: "ok",
          data: {
            list: [
              {
                id: 1,
                title: "公告标题",
                summary: "公告摘要",
                category: "运维",
              },
            ],
          },
        }),
      });
    });

    await page.goto("/");
    await navLink(page, "公告").hover();

    await expect(megaMenu(page).getByText("公告加载失败")).toBeVisible();
    await expect(page.getByText("暂无公告")).toHaveCount(0);

    failing = false;
    await megaMenu(page).getByRole("button", { name: "重新加载" }).click();

    await expect(megaMenu(page).locator(".mega-group-card")).toHaveCount(1);
  });
});
