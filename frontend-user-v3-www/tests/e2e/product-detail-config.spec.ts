import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 独立配置页（/products/:id）的选配、报价与展示回归。
 *
 * 本文件锚定四类历史问题：
 *
 * 1. 地区选择失效：上游给地区字段起的 key 各不相同（region / area / node…）。
 *    页面曾按 configForm.region 读写、报价也直接读 region，用户点了「香港」
 *    报价与提交仍是默认地区。现在读写统一走共享层的 regionValue（regionFieldKey），
 *    提交给上游的是选项的传参值（value），不是数字 id。
 * 2. 整页闪烁：切换系统版本 / 计费周期会触发整页 v-loading 遮罩，看起来像整页重刷。
 *    现在遮罩只在「首次还没有商品数据」时出现。
 * 3. 代理折扣不展示：命中代理折扣矩阵时报价里 total 已是折后价，但明细没有折扣行，
 *    用户看到的各项之和与合计对不上。现在补原价划线、折扣标签与折扣行。
 * 4. 独立配置页缺少机型对比：同组其它机型只活在一排 tab 里。现在桌面端给对比表，
 *    移动端给抽屉式选择器。
 */

const PRODUCT_ID = 2001;
const SIBLING_PRODUCT_ID = 2002;
const SECOND_GROUP_ID = 112;
const THIRD_GROUP_ID = 1030;

// 地区字段故意用 area：真实上游就是这么给的，写死 region 会导致选中值读不到
const AREA_CONFIG = {
  id: 900001,
  field: "area",
  spec_key: "",
  form_key: "area",
  submit_field: "area",
  name: "地区",
  description: "",
  hidden: 0,
  required: 0,
  sort_order: 1,
  option_type: 6,
  option_mode: "select",
  parameter: "美国|us,香港|hk",
  qty_minimum: 0,
  qty_maximum: 0,
  qty_step: 1,
  qty_stage: 1,
  unit: "",
  suffix_text: "",
  text_content: "",
  sub: [
    {
      id: 9001,
      label: "",
      version: "美国",
      option_name: "美国",
      value: "us",
      option_name_first: "美国",
      hidden: 0,
      sort_order: 0,
      qty_minimum: 0,
      qty_maximum: 0,
    },
    {
      id: 9002,
      label: "",
      version: "香港",
      option_name: "香港",
      value: "hk",
      option_name_first: "香港",
      hidden: 0,
      sort_order: 1,
      qty_minimum: 0,
      qty_maximum: 0,
    },
  ],
};

// 同一个系统两个版本：切版本是「整页闪烁」用例的触发点
const OS_CONFIG = {
  id: 900002,
  field: "os",
  spec_key: "",
  form_key: "os",
  submit_field: "os",
  name: "操作系统",
  description: "",
  hidden: 0,
  required: 0,
  sort_order: 2,
  option_type: 6,
  option_mode: "select",
  parameter: "CentOS^7.9|centos79,CentOS^8.5|centos85",
  qty_minimum: 0,
  qty_maximum: 0,
  qty_step: 1,
  qty_stage: 1,
  unit: "",
  suffix_text: "",
  text_content: "",
  sub: [
    {
      id: 8101,
      label: "",
      version: "CentOS^7.9",
      option_name: "CentOS^7.9",
      value: "centos79",
      option_name_first: "CentOS^7.9",
      hidden: 0,
      sort_order: 0,
      qty_minimum: 0,
      qty_maximum: 0,
    },
    {
      id: 8102,
      label: "",
      version: "CentOS^8.5",
      option_name: "CentOS^8.5",
      value: "centos85",
      option_name_first: "CentOS^8.5",
      hidden: 0,
      sort_order: 1,
      qty_minimum: 0,
      qty_maximum: 0,
    },
  ],
};

const PRICING_ENTRIES = [
  {
    cycle: "monthly",
    label: "月付",
    amount: "100.00",
    setup_fee: "0.00",
    total_amount: "100.00",
  },
  {
    cycle: "quarterly",
    label: "季付",
    amount: "280.00",
    setup_fee: "0.00",
    total_amount: "280.00",
  },
];

function detailProduct(id: number, name: string) {
  return {
    id,
    name,
    display_name: name,
    product_display_name: name,
    combined_display_name: name,
    instance_spec_text: name,
    product_type: "cloud_server",
    type: "cloud_server",
    type_label: "云服务器",
    first_product_group_id: 3,
    first_product_group_code: "cloud_server",
    first_product_group_name: "云服务器",
    second_product_group_id: SECOND_GROUP_ID,
    second_product_group_name: "香港",
    third_product_group_id: THIRD_GROUP_ID,
    third_product_group_name: "计算型",
    effective_product_group_id: THIRD_GROUP_ID,
    effective_product_group_level: 3,
    group_level_label: "三级分组",
    group_path_text: "云服务器 / 香港 / 计算型",
    service_type_code: "cloud_server",
    pricing: { monthly: "100.00", quarterly: "280.00" },
    pricing_entries: PRICING_ENTRIES,
    primary_cycle: "monthly",
    primary_price: "100.00",
    setup_fee: "0.00",
    setup_fee_display: "0.00",
    stock: -1,
    auto_setup: 1,
    spec_highlights: [],
    spec_highlight_text: "",
    description_html: "<p>测试机型网络与规格说明。</p>",
    config_options: [AREA_CONFIG, OS_CONFIG],
    group: {
      id: THIRD_GROUP_ID,
      name: "计算型",
      display_name: name,
      slogan: "",
      parent_id: SECOND_GROUP_ID,
      parent_name: "香港",
      parent_slogan: "",
      effective_product_group_id: THIRD_GROUP_ID,
      effective_product_group_level: 3,
      full_name: "云服务器 / 香港 / 计算型",
    },
    // 未登录 / 未命中折扣矩阵时的商品级折扣字段
    has_agent_discount: false,
    agent_group_name: "",
    agent_discount_percent: "",
    agent_primary_price: "0.00",
  };
}

// 机型对比表的数据源：同组在售商品，字段与列表式机型表一致
function groupProduct(id: number, name: string, extra: Record<string, unknown> = {}) {
  return {
    id,
    name,
    display_name: name,
    product_display_name: name,
    combined_display_name: name,
    instance_spec_text: name,
    cpu_display: "4 vCPU",
    memory_display: "8G",
    cpu_model_name: "Intel Xeon Platinum 8369B",
    cpu_base_frequency: "2.7",
    cpu_turbo_frequency: "3.5",
    primary_cycle: "monthly",
    primary_price: "100.00",
    pricing: { monthly: "100.00" },
    pricing_entries: [PRICING_ENTRIES[0]],
    setup_fee: "0.00",
    stock: -1,
    auto_setup: 1,
    spec_highlights: [],
    has_agent_discount: false,
    agent_discount_percent: "",
    agent_primary_price: "0.00",
    ...extra,
  };
}

const GROUP_PRODUCTS = [
  groupProduct(PRODUCT_ID, "计算型 S1"),
  groupProduct(SIBLING_PRODUCT_ID, "计算型 S2", {
    cpu_display: "8 vCPU",
    memory_display: "16G",
    cpu_model_name: "AMD EPYC 7K62",
    primary_price: "220.00",
    pricing: { monthly: "220.00" },
    pricing_entries: [
      {
        cycle: "monthly",
        label: "月付",
        amount: "220.00",
        setup_fee: "0.00",
        total_amount: "220.00",
      },
    ],
  }),
];

type QuoteOptions = {
  agentDiscount?: boolean;
};

function buildQuote(options: QuoteOptions = {}) {
  if (options.agentDiscount) {
    return {
      base_amount: "100.00",
      config_amount: "0.00",
      setup_fee: "0.00",
      subtotal_amount: "85.00",
      discount_amount: "0.00",
      total_amount: "85.00",
      original_total_amount: "100.00",
      agent_discount_rate: "85.00",
      agent_discount_amount: "15.00",
      agent_amount: "85.00",
      agent_group_name: "金牌代理",
      agent_discount_percent: "8.5折",
      user_coupon_id: 0,
      coupon: null,
      available_coupons: [],
      quote_token: "quote-token-agent",
      items: [],
    };
  }

  return {
    base_amount: "100.00",
    config_amount: "0.00",
    setup_fee: "0.00",
    subtotal_amount: "100.00",
    discount_amount: "0.00",
    total_amount: "100.00",
    original_total_amount: "100.00",
    agent_discount_rate: "100.00",
    agent_discount_amount: "0.00",
    agent_amount: "100.00",
    agent_group_name: "",
    agent_discount_percent: "",
    user_coupon_id: 0,
    coupon: null,
    available_coupons: [],
    quote_token: "quote-token",
    items: [],
  };
}

async function mockSiteApi(
  page: Page,
  options: { quote?: QuoteOptions } = {},
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
      return respond({ site_name: "测试站点" });
    }

    // 机型对比表的数据源
    if (pathname.startsWith("/v2/site/product-groups/")) {
      return respond({ list: GROUP_PRODUCTS, total: GROUP_PRODUCTS.length });
    }

    const stockMatch = pathname.match(/^\/v2\/site\/products\/(\d+)\/stock$/);
    if (stockMatch) {
      return respond({ stock: -1 });
    }

    if (pathname.match(/^\/v2\/site\/products\/(\d+)\/quote$/)) {
      return respond(buildQuote(options.quote));
    }

    const detailMatch = pathname.match(/^\/v2\/site\/products\/(\d+)$/);
    if (detailMatch) {
      const id = Number(detailMatch[1]);
      const product =
        id === SIBLING_PRODUCT_ID
          ? detailProduct(SIBLING_PRODUCT_ID, "计算型 S2")
          : detailProduct(PRODUCT_ID, "计算型 S1");

      return respond({ product });
    }

    if (pathname === "/v2/site/notices" || pathname === "/v2/site/help-articles") {
      return respond({ list: [] });
    }

    return respond({ list: [], total: 0 });
  });
}

const detailPath = `/products/${PRODUCT_ID}`;

function regionGroup(page: Page) {
  // 分组标题走 i18n，中文为「区域」，用正则同时兼容「地区」
  return page
    .locator(".cfg-group")
    .filter({ hasText: /区域|地区/ })
    .first();
}

test.describe("独立配置页选配与报价", () => {
  test("切换地区后报价带的是所选地区的传参值", async ({ page }) => {
    await mockSiteApi(page);
    await page.goto(detailPath);
    await expect(page.locator(".pd-body")).toBeVisible();

    // 首屏报价也走同一个接口，所以按「请求体里地区已经是 hk」来等，
    // 不能只等第一个 quote 请求（那可能是加载时那个默认地区的报价）。
    const quoteWithSelectedRegion = page.waitForRequest(
      (request) => {
        if (request.method() !== "POST" || !request.url().includes("/quote")) {
          return false;
        }

        const payload = request.postDataJSON() as {
          config?: Record<string, unknown>;
        };

        return payload?.config?.area === "hk";
      },
      { timeout: 10_000 },
    );

    await regionGroup(page).locator(".opt-btn", { hasText: "香港" }).click();

    const body = (await quoteWithSelectedRegion).postDataJSON() as {
      config?: Record<string, unknown>;
    };

    // 传参值而不是数字 id：上游只认 value
    expect(body.config?.area).toBe("hk");
  });

  test("切换系统版本与计费周期不出现整页 loading 遮罩", async ({ page }) => {
    await mockSiteApi(page);
    await page.goto(detailPath);
    await expect(page.locator(".pd-body")).toBeVisible();

    const bodyHandle = await page.locator(".pd-body").elementHandle();
    expect(bodyHandle).not.toBeNull();

    await page.locator(".ver-btn").nth(1).click();
    await expect(page.locator(".el-loading-mask")).toHaveCount(0);
    await expect(page.locator(".pd-body")).toBeVisible();

    await page.locator(".cycle-btn").nth(1).click();
    await expect(page.locator(".el-loading-mask")).toHaveCount(0);
    await expect(page.locator(".pd-body")).toBeVisible();

    // 元素没有被卸载重建：整页闪烁的实质就是配置区整个被替换掉
    expect(await bodyHandle?.evaluate((el) => el.isConnected)).toBe(true);
  });

  test("命中代理折扣时展示原价、折扣标签与折扣行", async ({ page }) => {
    await mockSiteApi(page, { quote: { agentDiscount: true } });
    await page.goto(detailPath);
    await expect(page.locator(".pd-body")).toBeVisible();

    const discountRow = page
      .locator(".cost-item--discount")
      .filter({ hasText: "代理折扣" })
      .first();
    await expect(discountRow).toBeVisible();
    await expect(discountRow).toContainText("金牌代理");
    await expect(discountRow).toContainText("-¥15.00");

    // 合计区：原价划线 + 折后应付
    await expect(page.locator(".cost-amount--original")).toContainText("100.00");
    await expect(page.locator(".cost-price-wrap")).toContainText("85.00");
    await expect(page.locator(".cost-price-wrap .discount-tag")).toContainText(
      "8.5折",
    );
  });

  test("机型对比表列出同组机型，点击切换后仍停在本页", async ({ page }) => {
    await mockSiteApi(page);
    await page.goto(detailPath);
    await expect(page.locator(".pd-body")).toBeVisible();

    const table = page.locator(".pd-sku");
    await expect(table).toBeVisible();
    await expect(table.locator(".sku-row--head")).toContainText("实例规格");
    await expect(table.locator("button.sku-row")).toHaveCount(
      GROUP_PRODUCTS.length,
    );

    await table.locator("button.sku-row").nth(1).click();

    await expect(page).toHaveURL(new RegExp(`/products/${SIBLING_PRODUCT_ID}$`));
    await expect(page.locator(".pd-body")).toBeVisible();
  });
});

test.describe("独立配置页移动端抽屉选择器", () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test("地区与机型在移动端走抽屉选择，桌面表格不渲染", async ({ page }) => {
    await mockSiteApi(page);
    await page.goto(detailPath);
    await expect(page.locator(".pd-body")).toBeVisible();

    // 桌面端机型对比表在移动端不渲染
    await expect(page.locator(".pd-sku")).toHaveCount(0);

    // 地区：触发器 → 抽屉 → 选中 → 回填
    const region = regionGroup(page);
    await region.locator(".mobile-picker-trigger").click();
    await page.locator(".mopt-item", { hasText: "香港" }).click();
    await page.locator(".ms-action--primary").click();
    await expect(region.locator(".mobile-picker-trigger")).toContainText("香港");

    // 机型：同组两个机型都出现在抽屉里
    const skuGroup = page
      .locator(".cfg-group")
      .filter({ hasText: "产品规格" })
      .first();
    await skuGroup.locator(".mobile-picker-trigger").click();
    await expect(page.locator(".mopt-item")).toHaveCount(GROUP_PRODUCTS.length);
    await expect(page.locator(".mopt-item", { hasText: "计算型 S2" })).toBeVisible();
  });
});

test.describe("独立配置页视觉快照", () => {
  test("桌面 / 平板 / 手机三档截图落临时目录供人工比对", async ({ page }) => {
    const shot = (name: string) =>
      `${process.env.TEMP || "."}\\turaidc-pd-${name}.png`;

    await mockSiteApi(page, { quote: { agentDiscount: true } });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(detailPath);
    await expect(page.locator(".pd-body")).toBeVisible();
    await page.screenshot({ path: shot("desktop"), fullPage: true });

    await page.setViewportSize({ width: 820, height: 1180 });
    await page.reload();
    await expect(page.locator(".pd-body")).toBeVisible();
    await page.screenshot({ path: shot("tablet"), fullPage: true });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await expect(page.locator(".pd-body")).toBeVisible();
    await page.screenshot({ path: shot("mobile"), fullPage: true });
  });
});
