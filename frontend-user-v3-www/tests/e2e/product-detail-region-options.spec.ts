import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 区域配置项（config_options[].sub）保真回归。
 *
 * 工单现场：上游「智简魔方财务」的区域有两个选项
 * 「美国数据中心【原生】」「美国数据中心【非原生】」，同步到本站后独立配置页
 * 只剩一个按钮「美国数据中心」——后缀和第二个选项一起消失。
 *
 * 根因在共享层 parseSubOptions：只有 >=2 个可见 sub 才用 sub，否则整条回退到
 * parameter 拼串。本文件锚定三种 sub 数量下的渲染与提交口径：
 *
 *   1. 两个 sub（只差后缀）→ 两个按钮，文字带后缀，提交各自的真实传参值；
 *   2. 一个 sub → 仍然用 sub 原文（不再被 parameter 拼串改写）；
 *   3. 零个 sub → 才回退 parameter。
 *   4. option_type=20（单选-层级联动，值是 sub 的 ID）不得被当成数量型渲染。
 */

const SECOND_GROUP_ID = 112;
const THIRD_GROUP_ID = 1030;

const TWO_SUB_PRODUCT_ID = 2101;
const ONE_SUB_PRODUCT_ID = 2102;
const NO_SUB_PRODUCT_ID = 2103;
const LEVEL_LINKAGE_PRODUCT_ID = 2104;

type SubSeed = {
  id: number;
  option_name: string;
  version?: string;
  value?: string;
  option_name_first?: string;
  hidden?: number;
};

// 后端 SiteProductDetailResource::trimSubOptions 的形状：id 是字符串、
// label 一律空（前端按 version → option_name 取值），value 是真实传参值。
function subOptions(seeds: SubSeed[]) {
  return seeds.map((seed, index) => ({
    id: String(seed.id),
    label: "",
    version: seed.version ?? seed.option_name,
    option_name: seed.option_name,
    value: seed.value ?? "",
    option_name_first: seed.option_name_first ?? "",
    hidden: seed.hidden ?? 0,
    sort_order: index,
    qty_minimum: null,
    qty_maximum: null,
  }));
}

function areaConfig(options: { parameter?: string; subs: SubSeed[] }) {
  return {
    id: 910001,
    field: "area",
    spec_key: "",
    form_key: "area",
    submit_field: "area",
    name: "区域",
    description: "",
    hidden: 0,
    required: 0,
    sort_order: 1,
    option_type: 20,
    option_mode: "select",
    parameter: options.parameter ?? "",
    qty_minimum: 0,
    qty_maximum: 0,
    qty_step: 1,
    qty_stage: 1,
    unit: "",
    suffix_text: "",
    text_content: "",
    sub: subOptions(options.subs),
  };
}

// 非区间型的离散项：option_type=20、没有 parameter、qty 全 0。
// 曾经被 isNumberField 的 min===max 兜底判成数量型，整组选项塌成数字输入框。
function networkTypeConfig(subs: SubSeed[]) {
  return {
    id: 910002,
    field: "network_type",
    spec_key: "",
    form_key: "network_type",
    submit_field: "network_type",
    name: "网络类型",
    description: "",
    hidden: 0,
    required: 0,
    sort_order: 2,
    option_type: 20,
    option_mode: "select",
    parameter: "",
    qty_minimum: 0,
    qty_maximum: 0,
    qty_step: 1,
    qty_stage: 1,
    unit: "",
    suffix_text: "",
    text_content: "",
    sub: subOptions(subs),
  };
}

// 没有系统配置项时页面会退回内置的演示系统分组，报价会带一个假系统值。
// 这里给一份真实结构的最小系统项，避免无关噪声混进断言。
function osConfig() {
  return {
    id: 910003,
    field: "os",
    spec_key: "",
    form_key: "os",
    submit_field: "os",
    name: "操作系统",
    description: "",
    hidden: 0,
    required: 0,
    sort_order: 9,
    option_type: 5,
    option_mode: "select",
    parameter: "centos79|CentOS^CentOS-7.9",
    qty_minimum: 0,
    qty_maximum: 0,
    qty_step: 1,
    qty_stage: 1,
    unit: "",
    suffix_text: "",
    text_content: "",
    sub: subOptions([
      { id: 8101, option_name: "CentOS^CentOS-7.9", value: "centos79" },
    ]),
  };
}

const PRICING_ENTRIES = [
  {
    cycle: "monthly",
    label: "月付",
    amount: "100.00",
    setup_fee: "0.00",
    total_amount: "100.00",
  },
];

function detailProduct(id: number, name: string, configOptions: unknown[]) {
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
    first_product_group_slug: "cloud-server",
    first_product_group_name: "云服务器",
    second_product_group_id: SECOND_GROUP_ID,
    second_product_group_name: "美国",
    third_product_group_id: THIRD_GROUP_ID,
    third_product_group_name: "计算型",
    effective_product_group_id: THIRD_GROUP_ID,
    effective_product_group_level: 3,
    group_level_label: "三级分组",
    group_path_text: "云服务器 / 美国 / 计算型",
    service_type_code: "cloud_server",
    pricing: { monthly: "100.00" },
    pricing_entries: PRICING_ENTRIES,
    primary_cycle: "monthly",
    primary_price: "100.00",
    setup_fee: "0.00",
    setup_fee_display: "0.00",
    stock: -1,
    auto_setup: 1,
    spec_highlights: [],
    spec_highlight_text: "",
    description_html: "<p>测试机型。</p>",
    config_options: configOptions,
    group: {
      id: THIRD_GROUP_ID,
      name: "计算型",
      display_name: name,
      slogan: "",
      parent_id: SECOND_GROUP_ID,
      parent_name: "美国",
      parent_slogan: "",
      effective_product_group_id: THIRD_GROUP_ID,
      effective_product_group_level: 3,
      full_name: "云服务器 / 美国 / 计算型",
    },
    has_agent_discount: false,
    agent_group_name: "",
    agent_discount_percent: "",
    agent_primary_price: "0.00",
  };
}

function buildQuote() {
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

const PRODUCTS: Record<number, unknown> = {
  // 两个变体只差后缀，parameter 里也是两份真实传参值
  [TWO_SUB_PRODUCT_ID]: detailProduct(TWO_SUB_PRODUCT_ID, "美国原生/非原生", [
    areaConfig({
      parameter: "us-nat|美国数据中心【原生】,us-unnat|美国数据中心【非原生】",
      subs: [
        {
          id: 5518001,
          option_name: "US^美国^美国数据中心【原生】",
          version: "美国数据中心【原生】",
          value: "us-nat",
        },
        {
          id: 5518002,
          option_name: "US^美国^美国数据中心【非原生】",
          version: "美国数据中心【非原生】",
          value: "us-unnat",
        },
      ],
    }),
    osConfig(),
  ]),
  // 工单最小复现：一个可见 sub，parameter 里放的是被折叠掉的旧文案
  [ONE_SUB_PRODUCT_ID]: detailProduct(ONE_SUB_PRODUCT_ID, "美国原生单选项", [
    areaConfig({
      parameter: "美国数据中心|美国数据中心",
      subs: [
        {
          id: 5518003,
          option_name: "美国数据中心【原生】",
          version: "美国数据中心【原生】",
          value: "us-nat",
        },
      ],
    }),
    osConfig(),
  ]),
  // 完全没有 sub：parameter 是唯一来源，必须继续兜底
  [NO_SUB_PRODUCT_ID]: detailProduct(NO_SUB_PRODUCT_ID, "纯 parameter 区域", [
    areaConfig({
      parameter: "us|美国数据中心【原生】,us2|美国数据中心【非原生】",
      subs: [],
    }),
    osConfig(),
  ]),
  // 层级联动（option_type=20）的离散项，没有 parameter、qty 全 0
  [LEVEL_LINKAGE_PRODUCT_ID]: detailProduct(
    LEVEL_LINKAGE_PRODUCT_ID,
    "网络类型离散项",
    [
      areaConfig({
        subs: [
          {
            id: 5518004,
            option_name: "美国数据中心【原生】",
            value: "us-nat",
          },
        ],
      }),
      networkTypeConfig([
        { id: 5518011, option_name: "BGP", value: "bgp" },
        { id: 5518012, option_name: "CN2", value: "cn2" },
      ]),
      osConfig(),
    ],
  ),
};

async function mockSiteApi(page: Page) {
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
        product_display_mode: "card",
      });
    }

    if (pathname.startsWith("/v2/site/product-groups/")) {
      return respond({ list: [], total: 0 });
    }

    const stockMatch = pathname.match(/^\/v2\/site\/products\/(\d+)\/stock$/);
    if (stockMatch) {
      return respond({ stock: -1 });
    }

    if (pathname.match(/^\/v2\/site\/products\/(\d+)\/quote$/)) {
      return respond(buildQuote());
    }

    const detailMatch = pathname.match(/^\/v2\/site\/products\/(\d+)$/);
    if (detailMatch) {
      const product = PRODUCTS[Number(detailMatch[1])];

      return respond({ product: product ?? null });
    }

    if (
      pathname === "/v2/site/notices" ||
      pathname === "/v2/site/help-articles"
    ) {
      return respond({ list: [] });
    }

    return respond({ list: [], total: 0 });
  });
}

// 与独立配置页的规范地址一致：/products/{typeSlug}/{二级}/{三级}/{商品}
function detailPath(productId: number) {
  return `/products/cloud-server/${SECOND_GROUP_ID}/${THIRD_GROUP_ID}/${productId}`;
}

function regionGroup(page: Page) {
  return page
    .locator(".cfg-group")
    .filter({ hasText: /区域|地区/ })
    .first();
}

function waitForQuotedRegion(page: Page, area: string) {
  return page.waitForRequest(
    (request) => {
      if (request.method() !== "POST" || !request.url().includes("/quote")) {
        return false;
      }

      const payload = request.postDataJSON() as {
        config?: Record<string, unknown>;
      };

      return payload?.config?.area === area;
    },
    { timeout: 10_000 },
  );
}

test.describe("配置项 sub 保真", () => {
  test("只差【原生】/【非原生】后缀的两个变体都渲染，按钮文字保留后缀", async ({
    page,
  }) => {
    await mockSiteApi(page);
    await page.goto(detailPath(TWO_SUB_PRODUCT_ID));
    await expect(page.locator(".pd-body")).toBeVisible();

    const region = regionGroup(page);

    // 两个按钮都在：少一个就是「第二个选项丢失」
    await expect(region.locator(".opt-btn")).toHaveCount(2);
    await expect(region.locator(".opt-btn").nth(0)).toHaveText(
      "美国数据中心【原生】",
    );
    await expect(region.locator(".opt-btn").nth(1)).toHaveText(
      "美国数据中心【非原生】",
    );

    // 提交的是各自的真实传参值，不是被拼串改写过的 id
    const quoted = waitForQuotedRegion(page, "us-unnat");
    await region.locator(".opt-btn", { hasText: "非原生" }).click();
    await quoted;
  });

  test("只有一个可见 sub 时仍用 sub 原文，不回退 parameter 拼串", async ({
    page,
  }) => {
    await mockSiteApi(page);

    // 默认选中第一个区域，首屏报价就该带 sub 的真实传参值
    const quoted = waitForQuotedRegion(page, "us-nat");
    await page.goto(detailPath(ONE_SUB_PRODUCT_ID));
    await expect(page.locator(".pd-body")).toBeVisible();
    await quoted;

    const region = regionGroup(page);

    // 工单症状：这里曾经只有一个「美国数据中心」按钮
    await expect(region.locator(".opt-btn")).toHaveCount(1);
    await expect(region.locator(".opt-btn").first()).toHaveText(
      "美国数据中心【原生】",
    );
    await expect(
      region.locator(".opt-btn", { hasText: /^美国数据中心$/ }),
    ).toHaveCount(0);
  });

  test("完全没有 sub 时仍然回退 parameter", async ({ page }) => {
    await mockSiteApi(page);
    const quoted = waitForQuotedRegion(page, "us");
    await page.goto(detailPath(NO_SUB_PRODUCT_ID));
    await expect(page.locator(".pd-body")).toBeVisible();
    await quoted;

    const region = regionGroup(page);

    await expect(region.locator(".opt-btn")).toHaveCount(2);
    await expect(region.locator(".opt-btn").nth(1)).toHaveText(
      "美国数据中心【非原生】",
    );
  });

  test("option_type=20 的离散项不被当成数量型，选项按钮照常渲染", async ({
    page,
  }) => {
    await mockSiteApi(page);
    await page.goto(detailPath(LEVEL_LINKAGE_PRODUCT_ID));
    await expect(page.locator(".pd-body")).toBeVisible();

    const row = page
      .locator(".spec-row")
      .filter({ hasText: "网络类型" })
      .first();
    await expect(row).toBeVisible();

    await expect(row.locator(".opt-btn")).toHaveCount(2);
    await expect(row.locator(".opt-btn").nth(0)).toHaveText("BGP");
    await expect(row.locator(".opt-btn").nth(1)).toHaveText("CN2");

    // min===max===0 且没有 parameter 的兜底不能盖过 sub：这里不该出现数字输入框
    await expect(row.locator(".el-input-number")).toHaveCount(0);
  });
});
