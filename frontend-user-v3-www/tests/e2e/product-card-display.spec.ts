import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 选购页「列表式 / 卡片式」切换与商品描述渲染。
 *
 * 背景：选购页此前只有一种形态——商品规格表格与配置区同页铺开，运营没有任何
 * 表达空间（商品没有可编辑的描述，展示方式也无法按站点偏好调整）。本次新增：
 *
 *   - 全站展示方式由后台「站点设置 → 基础设置」决定，经既有 /v2/site/config 下发；
 *   - 前台不提供用户级切换：排版是站点经营方的决策，同一站点只呈现一种形态；
 *   - 描述只有一个真源 products.description，留空时按规格维度自动生成
 *     <ul><li>键: 值</li></ul>，由后端 ProductDescriptionService 产出。
 *
 * 本用例同时是视觉回归的锚点：mock 的字段形态取自生产真实响应，描述取自
 * 虚拟主机商品的实际规格维度（虚拟主机与 CDN 的全部配置项 field 都是 cpu，
 * 识别必须靠名称，否则「WEB空间 12T」会被算成内存）。
 */

const TYPE_CODE = "hosting";
const GROUP_ID = 112;
const CHILD_GROUP_ID = 1030;

const PRODUCT_IDS = [985, 986, 987];

const CONFIG_OPTIONS = [
  { id: 2336431, name: "WEB空间", value: "500M", sort: 1 },
  { id: 2336427, name: "绑定域名数", value: "不限制", sort: 2 },
  { id: 2336428, name: "流量限制", value: "50G", sort: 3 },
  { id: 2336429, name: "带宽", value: "100Mbps带宽共享", sort: 4 },
].map((item) => ({
  id: item.id,
  field: "cpu",
  spec_key: "",
  form_key: `cpu#${item.id}`,
  submit_field: "cpu",
  name: item.name,
  description: "",
  hidden: 0,
  required: 0,
  sort_order: item.sort,
  option_type: 6,
  option_mode: "select",
  parameter: `${item.value}|${item.value}`,
  qty_minimum: 0,
  qty_maximum: 0,
  qty_step: 1,
  qty_stage: 1,
  unit: "",
  suffix_text: "",
  text_content: "",
  sub: [
    {
      id: 10007134 + item.sort * 10,
      label: "",
      version: item.value,
      option_name: item.value,
      value: item.value,
      option_name_first: item.value,
      hidden: 0,
      sort_order: 0,
      qty_minimum: 0,
      qty_maximum: 0,
    },
  ],
}));

const GROUP_PATH = [
  { level: 1, level_label: "一级分组", id: 3, code: TYPE_CODE, name: "虚拟主机" },
  { level: 2, level_label: "二级分组", id: GROUP_ID, code: "建站虚拟主机 VHS", name: "美国" },
  { level: 3, level_label: "三级分组", id: CHILD_GROUP_ID, code: "美国高防主机", name: "高防" },
];

// 后端 ProductDescriptionService 在描述留空时按规格维度生成的 HTML。
// 顺序跟 ProductSpecHighlightService::DIMENSIONS 的定义序一致。
const AUTO_DESCRIPTION_HTML =
  "<ul><li>域名: 不限制</li><li>流量: 50G</li><li>硬盘: 500M</li><li>带宽: 100Mbps带宽共享</li></ul>";

// 人工填写的描述：带一个链接与一个强调，用来验证富文本标签确实按原样渲染
const MANUAL_DESCRIPTION_HTML =
  '<p>高防线路，<strong>含 10G 防护</strong>，详情见 <a href="https://example.com/help">帮助中心</a>。</p>';

function buildProduct(index: number) {
  const id = PRODUCT_IDS[index];
  const names = ["美国高防主机-A", "美国高防主机-B（推荐）", "美国高防主机-C"];
  const monthly = ["3.00", "8.00", "15.00"][index];

  return {
    id,
    name: names[index],
    display_name: names[index],
    product_display_name: names[index],
    combined_display_name: names[index],
    product_type: "web_hosting",
    type: "web_hosting",
    type_label: "虚拟主机",
    first_product_group_id: 3,
    first_product_group_code: TYPE_CODE,
    first_product_group_name: "虚拟主机",
    second_product_group_id: GROUP_ID,
    second_product_group_name: "美国",
    second_product_group_description: "",
    third_product_group_id: CHILD_GROUP_ID,
    third_product_group_name: "高防",
    third_product_group_description: "",
    effective_product_group_id: CHILD_GROUP_ID,
    effective_product_group_level: 3,
    group_level_label: "三级分组",
    group_path: GROUP_PATH,
    group_path_text: "虚拟主机 / 美国 / 高防",
    product_type_label: "虚拟主机",
    service_type_code: "web_hosting",
    pricing: { monthly, annually: String(Number(monthly) * 10) },
    pricing_entries: [
      { cycle: "monthly", label: "月付", amount: monthly, setup_fee: "0.00", total_amount: monthly },
    ],
    primary_cycle: "monthly",
    primary_price: monthly,
    setup_fee: "0.00",
    // 第三张卡片故意设成售罄，用来锚定库存徽标与「不可选购」状态
    stock: index === 2 ? 0 : -1,
    auto_setup: 1,
    spec_highlights: [],
    spec_highlight_text: "",
    // 卡片式靠这个字段渲染描述
    description_html: index === 1 ? MANUAL_DESCRIPTION_HTML : AUTO_DESCRIPTION_HTML,
    config_options: CONFIG_OPTIONS,
  };
}

const PRODUCTS = PRODUCT_IDS.map((_, index) => buildProduct(index));

async function mockSiteApi(
  page: Page,
  displayMode: "list" | "card",
  siteConfigOverrides: Record<string, unknown> = {},
) {
  await page.route("**/api/v2/site/**", async (route) => {
    const url = new URL(route.request().url());
    const pathname = url.pathname.replace(/^\/api/, "");
    const respond = (data: unknown) =>
      route.fulfill({
        contentType: "application/json; charset=utf-8",
        body: JSON.stringify({ code: 0, message: "操作成功", data }),
      });

    if (pathname === "/v2/site/config") {
      return respond({
        site_name: "测试站点",
        // 全站展示方式：后台设定值，与 ProductDescriptionService::normalizeDisplayMode 同口径
        product_display_mode: displayMode,
        ...siteConfigOverrides,
      });
    }

    if (pathname === "/v2/site/product-purchase-context") {
      return respond({
        types: [
          {
            id: 3,
            value: TYPE_CODE,
            label: "虚拟主机",
            product_type: "web_hosting",
            group_count: 2,
            product_count: PRODUCTS.length,
          },
        ],
        root_groups: [
          {
            id: GROUP_ID,
            parent_id: 3,
            product_type: "web_hosting",
            product_type_id: 3,
            product_type_label: "虚拟主机",
            first_product_group_id: 3,
            first_product_group_code: TYPE_CODE,
            first_product_group_name: "虚拟主机",
            second_product_group_id: GROUP_ID,
            second_product_group_name: "美国",
            third_product_group_id: null,
            third_product_group_name: null,
            effective_product_group_id: GROUP_ID,
            effective_product_group_level: 2,
            group_level_label: "二级分组",
            group_path: GROUP_PATH.slice(0, 2),
            group_path_text: "虚拟主机 / 美国",
            service_type_code: "web_hosting",
            name: "美国",
            slogan: "",
            slug: "建站虚拟主机 VHS",
            children_count: 2,
            direct_product_count: 0,
            product_count: PRODUCTS.length,
          },
        ],
        root_groups_total: 1,
        root_groups_page: 1,
      });
    }

    if (pathname === `/v2/site/product-groups/${GROUP_ID}/products`) {
      return respond({ list: PRODUCTS, total: PRODUCTS.length });
    }

    if (pathname === `/v2/site/products/${PRODUCT_IDS[0]}/stock`) {
      return respond({ stock: -1 });
    }

    if (pathname === `/v2/site/products/${PRODUCT_IDS[0]}/quote`) {
      return respond({ base_amount: "3.00", setup_fee: "0.00", config_amount: "0.00" });
    }

    const detailMatch = pathname.match(/^\/v2\/site\/products\/(\d+)$/);
    if (detailMatch) {
      const id = Number(detailMatch[1]);
      const product = PRODUCTS.find((item) => item.id === id) || PRODUCTS[0];
      return respond({ product });
    }

    if (pathname === "/v2/site/notices" || pathname === "/v2/site/help-articles") {
      return respond({ list: [] });
    }

    return route.fulfill({
      contentType: "application/json; charset=utf-8",
      body: JSON.stringify({ code: 0, message: "ok", data: {} }),
    });
  });
}

const catalogPath = `/products?type=${TYPE_CODE}&group=${GROUP_ID}`;

test.describe("选购页展示方式与商品描述", () => {
  test("站点配置为列表式时不渲染卡片网格，且页面不提供切换入口", async ({ page }) => {
    await mockSiteApi(page, "list");
    await page.goto(catalogPath);

    await expect(page.locator(".product-card-grid")).toHaveCount(0);
    // 展示方式只由后台站点设置决定，前台不再提供用户级切换
    await expect(page.locator(".display-mode-switch")).toHaveCount(0);
  });

  test("卡片式展示卡片网格且不自动选中商品", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    const grid = page.locator(".product-card-grid");
    await expect(grid).toBeVisible();
    // 三张卡片
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    // 卡片式下没有选中态，配置区不该出现
    await expect(page.locator(".config-area")).toHaveCount(0);
  });

  test("卡片渲染商品描述 HTML", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    const cards = page.locator(".product-card");
    // 自动生成的描述是键值对列表
    await expect(cards.nth(0).locator(".product-card-desc li").first()).toBeVisible();
    await expect(cards.nth(0).locator(".product-card-desc")).toContainText("流量");
    // 人工填写的描述保留富文本结构
    await expect(cards.nth(1).locator(".product-card-desc strong")).toBeVisible();
    await expect(cards.nth(1).locator(".product-card-desc a")).toHaveAttribute("href", "https://example.com/help");
  });

  test("卡片展示库存徽标，售罄卡片不可选购", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    const cards = page.locator(".product-card");
    // 有货：后台开启「卡片显示库存」时的常态徽标
    await expect(cards.nth(0).locator(".product-card-flag--ok")).toHaveText("有货");
    // 售罄：无论开关如何都要提示，且按钮不可点
    await expect(cards.nth(2).locator(".product-card-flag--danger")).toHaveText("已售罄");
    await expect(cards.nth(2).locator(".product-card-buy")).toBeDisabled();
  });

  test("点击卡片正文也能进入独立配置页", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    // 此前只有「立即选购」按钮绑了跳转，点卡片正文没反应
    await page.locator(".product-card").nth(0).locator(".product-card-name").click();

    await expect(page).toHaveURL(new RegExp(`/products/${PRODUCT_IDS[0]}$`));
  });

  test("选购页版心按后台配置居中限宽，可切换铺满", async ({ page }) => {
    // 默认：居中 1280（接口未下发版心字段时的兜底）
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    const shopWrap = page.locator(".shop-wrap");
    await expect(shopWrap).toHaveClass(/shop-wrap--contained/);
    await expect(shopWrap).toHaveAttribute(
      "style",
      /--shop-content-max:\s*1280px/,
    );

    // 后台改成铺满 + 自定义宽度：不再限宽，但宽度值仍按配置下发
    await page.unroute("**/api/v2/site/**");
    await mockSiteApi(page, "card", {
      product_page_layout: "full",
      product_page_max_width: "1600",
    });
    await page.reload();

    await expect(page.locator(".shop-wrap")).not.toHaveClass(
      /shop-wrap--contained/,
    );
    await expect(page.locator(".shop-wrap")).toHaveAttribute(
      "style",
      /--shop-content-max:\s*1600px/,
    );
  });

  test("点立即选购进入独立配置页，配置页展示描述", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    await page.locator(".product-card").nth(0).locator(".product-card-buy").click();

    await expect(page).toHaveURL(new RegExp(`/products/${PRODUCT_IDS[0]}$`));
    await expect(page.locator(".pd-desc")).toBeVisible();
    await expect(page.locator(".pd-desc")).toContainText("流量");
  });

  test("视觉快照：两种形态与独立配置页", async ({ page }) => {
    // 直接落盘到临时目录，便于人工比对；不进 test-results 避免污染仓库
    const shot = (name: string) => `${process.env.TEMP || "."}\\turaidc-shot-${name}.png`;

    await mockSiteApi(page, "card");
    await page.goto(catalogPath);
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    await page.screenshot({ path: shot("card-mode"), fullPage: true });

    // 列表式不再靠前台切换：换掉站点配置重新加载，验证站点默认值直接生效
    await page.unroute("**/api/v2/site/**");
    await mockSiteApi(page, "list");
    await page.reload();
    await expect(page.locator(".product-card-grid")).toHaveCount(0);
    await page.screenshot({ path: shot("list-mode"), fullPage: true });

    await page.goto(`/products/${PRODUCT_IDS[1]}`);
    await expect(page.locator(".pd-desc")).toBeVisible();
    await page.screenshot({ path: shot("configure-page"), fullPage: true });
  });
});