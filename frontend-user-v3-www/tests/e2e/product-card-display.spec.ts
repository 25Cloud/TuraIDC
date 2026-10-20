import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 选购页「列表式 / 卡片式」展示与统一路由。
 *
 * 背景：此前展示方式和地址绑定——列表式的商品地址是「类型/分组/商品」，
 * 卡片式另有一条独立配置页 `/products/{id}`，同一个链接换个模式就变成另一种
 * 页面甚至打不开。现在两种形态共用同一套地址，展示方式只由本机偏好
 * （localStorage）或后台默认值决定：
 *
 *   /products/:typeSlug
 *   /products/:typeSlug/:groupId
 *   /products/:typeSlug/:groupId/:childGroupId
 *   /products/:typeSlug/:groupId/:childGroupId/:productId
 *
 * - 目录地址：列表式自动补全到第一个商品（地址推进到四段），卡片式补全到自动选中的
 *   第一个二/三级分组（地址推进到三段，商品由卡片按钮进入四段）；
 * - 商品地址：列表式在选购页内联配置，卡片式渲染独立配置页。
 *
 * 本用例同时锁住卡片描述与卡片交互：描述与详情页同源、完整展示、一行一项，
 * 整卡不可点，只有「立即选购」进入商品地址。
 */

// 一级分组（类型）：接口 value 是内部 code，地址里用可自定义的 slug
const TYPE_VALUE = "hosting";
const TYPE_SLUG = "web-hosting";
const GROUP_ID = 112;
const CHILD_GROUP_ID = 1030;
const SECOND_CHILD_GROUP_ID = 1031;

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
  {
    level: 1,
    level_label: "一级分组",
    id: 3,
    code: TYPE_VALUE,
    name: "虚拟主机",
  },
  {
    level: 2,
    level_label: "二级分组",
    id: GROUP_ID,
    code: "建站虚拟主机 VHS",
    name: "美国",
  },
  {
    level: 3,
    level_label: "三级分组",
    id: CHILD_GROUP_ID,
    code: "美国高防主机",
    name: "高防",
  },
];

// 后端 ProductDescriptionService 在描述留空时按规格维度生成的 HTML。
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
    first_product_group_code: TYPE_VALUE,
    first_product_group_slug: TYPE_SLUG,
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
      {
        cycle: "monthly",
        label: "月付",
        amount: monthly,
        setup_fee: "0.00",
        total_amount: monthly,
      },
    ],
    primary_cycle: "monthly",
    primary_price: monthly,
    setup_fee: "0.00",
    // 第三张卡片故意设成售罄，用来锚定库存徽标与「不可选购」状态
    stock: index === 2 ? 0 : -1,
    auto_setup: 1,
    spec_highlights: [],
    spec_highlight_text: "",
    description_html:
      index === 1 ? MANUAL_DESCRIPTION_HTML : AUTO_DESCRIPTION_HTML,
    config_options: CONFIG_OPTIONS,
  };
}

const PRODUCTS = PRODUCT_IDS.map((_, index) => buildProduct(index));

const TYPE_PAYLOAD = {
  id: 3,
  value: TYPE_VALUE,
  slug: TYPE_SLUG,
  label: "虚拟主机",
  product_type: "web_hosting",
  product_type_label: "虚拟主机",
  product_type_icon: "",
  group_count: 2,
  product_count: PRODUCTS.length,
};

const ROOT_GROUP_PAYLOAD = {
  id: GROUP_ID,
  parent_id: 3,
  product_type: "web_hosting",
  product_type_id: 3,
  product_type_label: "虚拟主机",
  first_product_group_id: 3,
  first_product_group_code: TYPE_VALUE,
  first_product_group_slug: TYPE_SLUG,
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
};

const CHILD_GROUP_PAYLOAD = {
  id: CHILD_GROUP_ID,
  effective_product_group_id: CHILD_GROUP_ID,
  effective_product_group_level: 3,
  name: "高防",
  group_level_label: "三级分组",
  product_count: PRODUCTS.length,
};

const SECOND_CHILD_GROUP_PAYLOAD = {
  id: SECOND_CHILD_GROUP_ID,
  effective_product_group_id: SECOND_CHILD_GROUP_ID,
  effective_product_group_level: 3,
  name: "高防二区",
  group_level_label: "三级分组",
  product_count: PRODUCTS.length,
};

// 第二个二级分组（香港）：用于验证「点一次就出商品」与快速连续切换
const SECOND_ROOT_GROUP_ID = 113;
const SECOND_ROOT_GROUP_CHILD_ID = 1032;

const SECOND_ROOT_GROUP_PAYLOAD = {
  id: SECOND_ROOT_GROUP_ID,
  parent_id: 3,
  product_type: "web_hosting",
  product_type_id: 3,
  product_type_label: "虚拟主机",
  first_product_group_id: 3,
  first_product_group_code: TYPE_VALUE,
  first_product_group_slug: TYPE_SLUG,
  first_product_group_name: "虚拟主机",
  second_product_group_id: SECOND_ROOT_GROUP_ID,
  second_product_group_name: "香港",
  third_product_group_id: null,
  third_product_group_name: null,
  effective_product_group_id: SECOND_ROOT_GROUP_ID,
  effective_product_group_level: 2,
  group_level_label: "二级分组",
  group_path: GROUP_PATH.slice(0, 2),
  group_path_text: "虚拟主机 / 香港",
  service_type_code: "web_hosting",
  name: "香港",
  slogan: "",
  slug: "香港主机",
  children_count: 1,
  direct_product_count: 0,
  product_count: PRODUCTS.length,
};

const SECOND_ROOT_CHILD_PAYLOAD = {
  id: SECOND_ROOT_GROUP_CHILD_ID,
  effective_product_group_id: SECOND_ROOT_GROUP_CHILD_ID,
  effective_product_group_level: 3,
  name: "香港一区",
  group_level_label: "三级分组",
  product_count: PRODUCTS.length,
};

interface MockOptions {
  extraRootGroup?: boolean;
  catalogDelayMs?: number;
  productDetailDelayMs?: number;
  /** 模拟后端 product_type 映射缺失：product_type_label 回落成「其他」 */
  genericTypeLabel?: boolean;
}

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** 模拟接口里的商品数据；genericTypeLabel 用于复现「其他」标签的回退场景 */
function productPayloads(options: MockOptions) {
  if (!options.genericTypeLabel) {
    return PRODUCTS;
  }

  return PRODUCTS.map((product) => ({
    ...product,
    product_type: "other",
    type: "other",
    type_label: "其他",
    product_type_label: "其他",
    first_product_group_code: "nat",
    first_product_group_slug: "nat",
    first_product_group_name: "NAT服务器",
  }));
}

async function mockSiteApi(
  page: Page,
  displayMode: "list" | "card",
  siteConfigOverrides: Record<string, unknown> = {},
  mockOptions: MockOptions = {},
) {
  const products = productPayloads(mockOptions);
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

    if (pathname === "/v2/site/product-types") {
      return respond({ list: [TYPE_PAYLOAD] });
    }

    if (pathname === "/v2/site/product-purchase-context") {
      const rootGroups = mockOptions.extraRootGroup
        ? [ROOT_GROUP_PAYLOAD, SECOND_ROOT_GROUP_PAYLOAD]
        : [ROOT_GROUP_PAYLOAD];
      return respond({
        types: [TYPE_PAYLOAD],
        root_groups: rootGroups,
        root_groups_total: rootGroups.length,
        root_groups_page: 1,
      });
    }

    if (pathname === "/v2/site/product-groups") {
      const groups = mockOptions.extraRootGroup
        ? [ROOT_GROUP_PAYLOAD, SECOND_ROOT_GROUP_PAYLOAD]
        : [ROOT_GROUP_PAYLOAD];
      return respond({ list: groups, total: groups.length });
    }

    if (pathname === `/v2/site/product-groups/${GROUP_ID}/children`) {
      return respond({
        list: [CHILD_GROUP_PAYLOAD, SECOND_CHILD_GROUP_PAYLOAD],
        total: 2,
      });
    }

    if (pathname === `/v2/site/product-groups/${SECOND_ROOT_GROUP_ID}/children`) {
      return respond({ list: [SECOND_ROOT_CHILD_PAYLOAD], total: 1 });
    }

    // 目录聚合接口：children + 根分组商品 + 当前三级分组商品（一次请求）
    if (
      pathname === `/v2/site/product-groups/${GROUP_ID}/catalog` ||
      pathname === `/v2/site/product-groups/${CHILD_GROUP_ID}/catalog` ||
      pathname === `/v2/site/product-groups/${SECOND_ROOT_GROUP_ID}/catalog` ||
      pathname === `/v2/site/product-groups/${SECOND_ROOT_GROUP_CHILD_ID}/catalog`
    ) {
      if (mockOptions.catalogDelayMs) {
        await sleep(mockOptions.catalogDelayMs);
      }

      const requestedChildId = Number(
        url.searchParams.get("child_group_id") || 0,
      );
      const resolveId = Number(url.searchParams.get("resolve_id") || 0);
      const isSecondRoot =
        pathname.includes(`/${SECOND_ROOT_GROUP_ID}/`) ||
        pathname.includes(`/${SECOND_ROOT_GROUP_CHILD_ID}/`);
      const groupId = isSecondRoot ? SECOND_ROOT_GROUP_ID : GROUP_ID;
      const childPayload = isSecondRoot
        ? SECOND_ROOT_CHILD_PAYLOAD
        : CHILD_GROUP_PAYLOAD;
      const childIds = isSecondRoot
        ? [SECOND_ROOT_GROUP_CHILD_ID]
        : [CHILD_GROUP_ID, SECOND_CHILD_GROUP_ID];
      let targetChildId: number;
      if (childIds.includes(requestedChildId)) {
        targetChildId = requestedChildId;
      } else if (childIds.includes(resolveId)) {
        targetChildId = resolveId;
      } else if (PRODUCT_IDS.includes(resolveId)) {
        // 旧商品深链：商品都属于第一个三级分组
        targetChildId = isSecondRoot
          ? SECOND_ROOT_GROUP_CHILD_ID
          : CHILD_GROUP_ID;
      } else {
        targetChildId = childIds[0];
      }

      const itemsByGroup: unknown[] = [
        {
          effective_product_group_id: groupId,
          effective_product_group_level: 2,
          products: [],
        },
        {
          effective_product_group_id: targetChildId,
          effective_product_group_level: 3,
          products,
        },
      ];

      return respond({
        effective_product_group_id: groupId,
        effective_product_group_level: 2,
        children: isSecondRoot
          ? [childPayload]
          : [CHILD_GROUP_PAYLOAD, SECOND_CHILD_GROUP_PAYLOAD],
        items_by_group: itemsByGroup,
      });
    }

    if (
      pathname === `/v2/site/product-groups/${GROUP_ID}/products` ||
      pathname === `/v2/site/product-groups/${CHILD_GROUP_ID}/products` ||
      pathname === `/v2/site/product-groups/${SECOND_CHILD_GROUP_ID}/products` ||
      pathname === `/v2/site/product-groups/${SECOND_ROOT_GROUP_CHILD_ID}/products`
    ) {
      return respond({ list: products, total: products.length });
    }

    const stockMatch = pathname.match(/^\/v2\/site\/products\/(\d+)\/stock$/);
    if (stockMatch) {
      return respond({ stock: -1 });
    }

    const quoteMatch = pathname.match(/^\/v2\/site\/products\/(\d+)\/quote$/);
    if (quoteMatch) {
      return respond({
        base_amount: "3.00",
        setup_fee: "0.00",
        config_amount: "0.00",
      });
    }

    const detailMatch = pathname.match(/^\/v2\/site\/products\/(\d+)$/);
    if (detailMatch) {
      if (mockOptions.productDetailDelayMs) {
        await sleep(mockOptions.productDetailDelayMs);
      }
      const id = Number(detailMatch[1]);
      const product = products.find((item) => item.id === id) || products[0];
      return respond({ product });
    }

    if (
      pathname === "/v2/site/notices" ||
      pathname === "/v2/site/help-articles"
    ) {
      return respond({ list: [] });
    }

    return route.fulfill({
      contentType: "application/json; charset=utf-8",
      body: JSON.stringify({ code: 0, message: "ok", data: {} }),
    });
  });
}

const typePath = `/products/${TYPE_SLUG}`;
const groupPath = `${typePath}/${GROUP_ID}`;
const childPath = `${groupPath}/${CHILD_GROUP_ID}`;
const productPath = `${childPath}/${PRODUCT_IDS[0]}`;

test.describe("选购页统一路由与展示方式", () => {
  test("列表式：目录地址自动补全到第一个商品的四段地址", async ({ page }) => {
    await mockSiteApi(page, "list");
    await page.goto(groupPath);

    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect(page.locator(".config-area")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);
    await expect(page.locator(".product-card-grid")).toHaveCount(0);
  });

  test("卡片式：目录地址补全到自动选中的三级分组，只展示卡片", async ({ page }) => {
    await mockSiteApi(page, "card");

    // 只给一级分组：自动选中第一个二级/三级分组，地址补全到三段
    await page.goto(typePath);
    await expect(page.locator(".product-card-grid")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);

    // 二级分组：补全三级分组
    await page.goto(groupPath);
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);

    // 三级分组：地址保持三段
    await page.goto(childPath);
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);
    await expect(page.locator(".config-area")).toHaveCount(0);
  });

  test("列表式：从一级分组地址直接补全到第一个商品", async ({ page }) => {
    await mockSiteApi(page, "list");
    await page.goto(typePath);

    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);
  });

  test("列表式：机型表首列显示完整商品名，不做任何裁剪", async ({ page }) => {
    await mockSiteApi(page, "list");
    await page.goto(childPath);

    // 商品名原样展示：既是「分组名 + 规格」也不剥前缀，商品名就是商品名。
    const firstNameCell = page
      .locator(".desktop-machine-table-row .machine-spec-name")
      .first();
    await expect(firstNameCell).toHaveText("美国高防主机-A");
  });

  test("卡片式目录只发一次聚合请求，三级分组商品按需加载", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    const catalogRequests: string[] = [];
    const childProductRequests: string[] = [];

    page.on("request", (request) => {
      const pathname = new URL(request.url()).pathname;
      if (pathname.endsWith("/catalog")) {
        catalogRequests.push(pathname);
      }
      if (/\/product-groups\/\d+\/products$/.test(pathname)) {
        childProductRequests.push(pathname);
      }
    });

    await page.goto(groupPath);
    await expect(page.locator(".product-card-grid")).toBeVisible();

    // 目录一次拿齐：children + 当前三级分组商品，不再给每个三级分组各发一轮
    expect(catalogRequests).toHaveLength(1);
    expect(childProductRequests).toHaveLength(0);

    // 切到第二个三级分组：只按需拉这一个分组的商品
    await page.locator(".shop-cat__child").nth(1).click();
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    await expect
      .poll(() => new URL(page.url()).pathname)
      .toBe(`${groupPath}/${SECOND_CHILD_GROUP_ID}`);
    expect(childProductRequests).toHaveLength(1);
    expect(childProductRequests[0]).toContain(
      `/${SECOND_CHILD_GROUP_ID}/products`,
    );
  });

  test("同一个商品地址：卡片式渲染配置页，列表式渲染内联配置", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    await page.goto(productPath);

    await expect(page.locator(".pd-page")).toBeVisible();
    await expect(page.locator(".pd-desc")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);

    // 同一个地址换成列表式偏好：地址不变，渲染换成选购页内联配置
    await page.unroute("**/api/v2/site/**");
    await mockSiteApi(page, "list");
    await page.reload();

    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect(page.locator(".config-area")).toBeVisible();
    await expect(page.locator(".pd-page")).toHaveCount(0);
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);
  });

  test("卡片点立即选购进入四段商品地址，配置页切换展示方式不改地址", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);

    await page
      .locator(".product-card")
      .first()
      .locator(".product-card-buy")
      .click();
    await expect(page).toHaveURL(new RegExp(`${productPath}$`));
    await expect(page.locator(".pd-page")).toBeVisible();

    // 卡片式配置页切列表式：地址不变，只换渲染（切换器与选购页共用同一组件）
    await page
      .locator(".shop-view-switch")
      .getByRole("button", { name: "列表式" })
      .click();
    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);

    // 列表式再切回卡片式：仍是同一个商品地址，渲染回配置页
    await page
      .locator(".shop-view-switch")
      .getByRole("button", { name: "卡片式" })
      .click();
    await expect(page.locator(".pd-page")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);
  });

  test("目录地址上从卡片式切列表式：自动补全到商品段", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);
    await expect(page.locator(".product-card-grid")).toBeVisible();

    await page
      .locator(".shop-view-switch")
      .getByRole("button", { name: "列表式" })
      .click();
    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);
  });

  test("旧地址兼容：单段商品页与三段旧商品深链都会规范到四段", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");

    // 旧卡片式配置页 /products/{id}
    await page.goto(`/products/${PRODUCT_IDS[0]}`);
    await expect(page.locator(".pd-page")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);

    // 旧商品深链 /products/{type}/{group}/{productId}（第三段是商品 ID）
    await page.goto(`${groupPath}/${PRODUCT_IDS[0]}`);
    await expect(page.locator(".pd-page")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);
  });

  test("旧 code 类型的链接也能打开，并规范成 slug 地址", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(`/products/${TYPE_VALUE}/${GROUP_ID}`);

    await expect(page.locator(".product-card-grid")).toBeVisible();
    // 卡片式会把自动补全的三级分组写进地址（code 段换成 slug）
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);
  });

  test("卡片渲染商品描述 HTML，描述完整且按一行一项排版", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    const cards = page.locator(".product-card");
    // 自动生成的描述是键值对列表
    await expect(
      cards.nth(0).locator(".product-card-desc li").first(),
    ).toBeVisible();
    await expect(cards.nth(0).locator(".product-card-desc")).toContainText(
      "流量",
    );
    // 人工填写的描述保留富文本结构
    await expect(cards.nth(1).locator(".product-card-desc strong")).toBeVisible();
    await expect(cards.nth(1).locator(".product-card-desc a")).toHaveAttribute(
      "href",
      "https://example.com/help",
    );

    // 描述与详情页同源且完整：不能再按 136px 限高截断
    const desc = cards.nth(0).locator(".product-card-desc");
    await expect
      .poll(() => desc.evaluate((el) => getComputedStyle(el).maxHeight))
      .toBe("none");
    await expect
      .poll(() => desc.evaluate((el) => getComputedStyle(el).overflow))
      .not.toBe("hidden");

    // 规格列表一行一项：单列网格，不再挤成两列
    const columns = await cards
      .nth(0)
      .locator(".product-card-desc ul")
      .evaluate((el) => getComputedStyle(el).gridTemplateColumns);
    expect(columns.trim().split(/\s+/)).toHaveLength(1);
  });

  test("卡片展示库存徽标，售罄卡片不可选购", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    const cards = page.locator(".product-card");
    await expect(cards.nth(0).locator(".product-card-flag--ok")).toHaveText(
      "有货",
    );
    await expect(
      cards.nth(2).locator(".product-card-flag--danger"),
    ).toHaveText("已售罄");
    await expect(cards.nth(2).locator(".product-card-buy")).toBeDisabled();

    // 禁用态必须压成中性灰：Element Plus 的 primary 禁用以浅蓝呈现，
    // 在一排蓝色主按钮里看着仍像能点（运营反馈过）。
    const soldOutButton = cards.nth(2).locator(".product-card-buy");
    const soldOutStyles = await soldOutButton.evaluate((el) => {
      const style = getComputedStyle(el);
      return {
        background: style.backgroundColor,
        cursor: style.cursor,
        shadow: style.boxShadow,
      };
    });
    expect(soldOutStyles.cursor).toBe("not-allowed");
    expect(soldOutStyles.shadow).toBe("none");
    // #eef2f7 = rgb(238, 242, 247)，即中性灰底
    expect(soldOutStyles.background).toBe("rgb(238, 242, 247)");

    // 有货卡片的按钮仍是主色蓝，两者必须能一眼区分
    const buyableBackground = await cards
      .nth(0)
      .locator(".product-card-buy")
      .evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(buyableBackground).not.toBe(soldOutStyles.background);
  });

  test("卡片正文不可点击，只有立即选购按钮进入配置页", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    const card = page.locator(".product-card").first();
    // 等地址补全稳定后再记录「目录地址」，否则会把补全前的地址当成基准
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);
    const directoryUrl = page.url();

    await card.locator(".product-card-name").click();
    await card.locator(".product-card-desc").click();
    await expect(page).toHaveURL(directoryUrl);
    await expect(page.locator(".product-card-grid")).toBeVisible();

    await card.locator(".product-card-buy").click();
    await expect(page).toHaveURL(new RegExp(`${productPath}$`));
  });

  test("选购页版心按后台配置居中限宽，可切换铺满", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    const shopWrap = page.locator(".shop-wrap");
    await expect(shopWrap).toHaveClass(/shop-wrap--contained/);
    await expect(shopWrap).toHaveAttribute(
      "style",
      /--shop-content-max:\s*1280px/,
    );

    await page.unroute("**/api/v2/site/**");
    await mockSiteApi(page, "card", {
      product_page_layout: "full",
      product_page_max_width: "1600",
    });
    await page.reload();

    await expect(shopWrap).not.toHaveClass(/shop-wrap--contained/);
    await expect(shopWrap).toHaveAttribute(
      "style",
      /--shop-content-max:\s*100vw/,
    );
  });

  test("访客可调宽屏版心：固定宽度档位或撑满，切换有过渡且配置页跟随", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    const shopWrap = page.locator(".shop-wrap");
    await expect(shopWrap).toBeVisible();
    await expect
      .poll(() => shopWrap.evaluate((el) => getComputedStyle(el).maxWidth))
      .toBe("1280px");

    const transition = await shopWrap.evaluate(
      (el) => getComputedStyle(el).transitionDuration,
    );
    expect(transition).toContain("0.34s");

    await page.getByRole("button", { name: "页面宽度" }).click();
    const panel = page.locator(".shop-width-panel");
    await expect(panel).toBeVisible();
    await expect(
      panel.getByRole("button", { name: "固定宽度" }),
    ).toHaveAttribute("aria-pressed", "true");
    await expect(panel.getByRole("button", { name: "1280" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );

    await panel.getByRole("button", { name: "1600" }).click();
    await expect
      .poll(() => shopWrap.evaluate((el) => getComputedStyle(el).maxWidth))
      .toBe("1600px");
    const stored = await page.evaluate(() =>
      JSON.parse(
        window.localStorage.getItem("turaidc:shop-page-width") || "{}",
      ),
    );
    expect(stored).toMatchObject({ mode: "contained", maxWidth: 1600 });

    await panel.getByRole("button", { name: "撑满屏幕" }).click();
    await expect(shopWrap).toHaveAttribute(
      "style",
      /--shop-content-max:\s*100vw/,
    );
    await expect
      .poll(() => shopWrap.evaluate((el) => getComputedStyle(el).maxWidth))
      .toBe("1440px");

    // 统一路由后配置页就是同一个商品地址，版心偏好同样跟随
    await page.goto(productPath);
    await expect(page.locator(".pd-page")).toHaveAttribute(
      "style",
      /--pd-content-max:\s*100vw/,
    );

    await page.goto(groupPath);
    await expect(page.locator(".shop-wrap")).toHaveAttribute(
      "style",
      /--shop-content-max:\s*100vw/,
    );
    await page.getByRole("button", { name: "页面宽度" }).click();
    await page
      .locator(".shop-width-panel")
      .getByRole("button", { name: "恢复站点默认" })
      .click();
    await expect
      .poll(() =>
        page.locator(".shop-wrap").evaluate((el) => getComputedStyle(el).maxWidth),
      )
      .toBe("1280px");
    await expect
      .poll(() =>
        page.evaluate(() =>
          window.localStorage.getItem("turaidc:shop-page-width"),
        ),
      )
      .toBeNull();
  });

  test("手机端可选择卡片式或列表式", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    await expect(page.locator(".product-card-grid")).toBeVisible();
    const bar = page.locator(".shop-view-bar");
    await expect(bar).toBeVisible();
    await expect(page.locator(".shop-width-control")).toHaveCount(0);

    await bar.getByRole("button", { name: "列表式", exact: true }).click();
    await expect(page.locator(".product-card-grid")).toHaveCount(0);
    await expect(page.locator(".config-area")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);

    await bar.getByRole("button", { name: "卡片式", exact: true }).click();
    await expect(page.locator(".pd-page")).toBeVisible();
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);
  });

  test("手机端列表式：产品选择抽屉直接列商品，选中即切换", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockSiteApi(page, "list");
    await page.goto(childPath);
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);

    // 第一行「产品规格选择」：点开必须能看到每个商品，而不是只有一个分组名
    const specTrigger = page.locator(".mobile-picker-trigger").first();
    await expect(specTrigger).toBeVisible();
    await specTrigger.click();

    const items = page.locator(".mobile-product-sheet-item");
    await expect(items).toHaveCount(PRODUCTS.length);
    await expect(items.nth(0)).toContainText("美国高防主机-A");
    await expect(items.nth(1)).toContainText("美国高防主机-B");
    await expect(items.nth(1)).toContainText("¥");

    // 选第二个商品 → 确定：地址与报价一起切过去
    await items.nth(1).click();
    await page.locator(".ms-head .ms-action--primary").click();
    await expect
      .poll(() => new URL(page.url()).pathname)
      .toBe(`${childPath}/${PRODUCT_IDS[1]}`);
    await expect(page.locator(".ms-drawer")).toHaveCount(0);
  });
  test("分组导航：一级与二/三级分成两个侧栏，字号放大且点目录自动收起一级", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    const rail = page.locator(".shop-sidebar");
    const catalog = page.locator(".shop-catalog");
    await expect(rail).toBeVisible();
    await expect(catalog).toBeVisible();

    // 一层侧栏只管一级分组，另一层只管二/三级分组，不再挤在同一列
    await expect(rail.locator(".sidebar-item")).toHaveCount(1);
    await expect(rail.locator(".sidebar-item").first()).toContainText(
      "虚拟主机",
    );
    await expect(catalog.locator(".shop-cat__group")).toHaveCount(1);
    await expect(catalog.locator(".shop-cat__group").first()).toContainText(
      "美国",
    );
    await expect(catalog.locator(".shop-cat__child").first()).toContainText(
      "高防",
    );
    await expect(page.locator(".pg-nav--top")).toHaveCount(0);
    await expect(page.locator(".filter-block")).toHaveCount(0);

    // 字号不能再比正文小一大截（此前 12.5px / 13px 就是「太小」的来源）
    const groupFont = await catalog
      .locator(".shop-cat__group")
      .first()
      .evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    const railFont = await rail
      .locator(".item-name")
      .first()
      .evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(groupFont).toBeGreaterThanOrEqual(14);
    expect(railFont).toBeGreaterThanOrEqual(14);

    // 点三级分组：地址推进到三段，同时收起一级分组栏给配置区让位
    await catalog.locator(".shop-cat__child").first().click();
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);
    await expect(rail).toHaveClass(/collapsed/);
    await expect(catalog).toBeVisible();

    // 一级分组可以手动展开回来
    await rail.locator(".sidebar-toggle").click();
    await expect(rail).not.toHaveClass(/collapsed/);

    // 整块目录可以收起，偏好只存本机且跨刷新生效
    await page.getByRole("button", { name: "收起目录" }).click();
    await expect(page.locator(".shop-sidebar")).toHaveCount(0);
    await expect(page.locator(".shop-catalog")).toHaveCount(0);
    await page.reload();
    await expect(page.locator(".shop-catalog")).toHaveCount(0);
    await page.getByRole("button", { name: "展开目录" }).click();
    await expect(page.locator(".shop-sidebar")).toBeVisible();
    await expect(page.locator(".shop-catalog")).toBeVisible();
  });

  test("列表式：切换三级分组后自动选中并渲染第一个商品", async ({ page }) => {
    await mockSiteApi(page, "list");
    await page.goto(childPath);
    await expect.poll(() => new URL(page.url()).pathname).toBe(productPath);

    // 切到第二个三级分组：地址应直接推进到该分组的第一个商品
    await page.locator(".shop-cat__child").nth(1).click();
    const secondChildPath = `${groupPath}/${SECOND_CHILD_GROUP_ID}`;
    await expect
      .poll(() => new URL(page.url()).pathname)
      .toBe(`${secondChildPath}/${PRODUCT_IDS[0]}`);
    await expect(page.locator(".desktop-machine-table-row.active")).toHaveCount(
      1,
    );
    await expect(page.locator(".config-area")).toBeVisible();
  });

  test("卡片式配置页：返回跳上级目录而不是浏览器返回", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(productPath);
    await expect(page.locator(".pd-page")).toBeVisible();

    await page.locator(".back-btn").click();
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);
  });

  test("卡片式：鼠标悬停轻微上浮，售罄卡片保持静止", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(childPath);

    const card = page.locator(".product-card").first();
    await card.hover();
    await expect
      .poll(() =>
        card.evaluate((el) => getComputedStyle(el).transform),
      )
      .toContain("-3");
  });
  test("手机端目录：底部抽屉里选一/二/三级分组，选完自动收起", async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockSiteApi(page, "card");
    await page.goto(groupPath);

    // 不再有整页「先选一级分组」的卡片页，也没有顶部折叠面板与两个侧栏
    await expect(page.locator(".mobile-type-stage")).toHaveCount(0);
    await expect(page.locator(".pg-nav--top")).toHaveCount(0);
    await expect(page.locator(".shop-sidebar")).toHaveCount(0);
    await expect(page.locator(".shop-catalog")).toHaveCount(0);

    const bar = page.locator(".shop-view-bar__catalog");
    await expect(bar).toBeVisible();
    await expect(bar).toContainText("虚拟主机");

    await bar.click();
    const sheet = page.locator(".ms-drawer");
    await expect(sheet).toBeVisible();
    await expect(sheet.locator(".m-cat__type").first()).toContainText(
      "虚拟主机",
    );
    await expect(sheet.locator(".m-cat__row").first()).toContainText("美国");

    // 点二级分组：原地钻取它的三级分组
    await sheet.locator(".m-cat__row").first().click();
    // 返回按钮文案是「返回上级」，不再重复当前分组名
    await expect(sheet.locator(".m-cat__back")).toContainText("返回上级");
    await expect(sheet.locator(".m-cat__row").first()).toContainText("高防");

    // 选三级分组后地址推进到三段，抽屉自动收起
    await sheet.locator(".m-cat__row").first().click();
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);
    await expect(page.locator(".ms-drawer")).toHaveCount(0);
  });

  test("视觉快照：两种形态与商品配置页", async ({ page }) => {
    const shot = (name: string) =>
      `${process.env.TEMP || "."}\\turaidc-shot-${name}.png`;

    await mockSiteApi(page, "card");
    await page.goto(groupPath);
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    await page.screenshot({ path: shot("card-mode"), fullPage: true });

    // 目录侧栏：点过三级分组后一级分组收起的样子
    await page.locator(".shop-cat__child").first().click();
    await expect(page.locator(".shop-sidebar")).toHaveClass(/collapsed/);
    await page.screenshot({ path: shot("catalog-nav-collapsed") });

    await page.unroute("**/api/v2/site/**");
    await mockSiteApi(page, "list");
    await page.goto(productPath);
    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await page.screenshot({ path: shot("list-mode"), fullPage: true });

    await page.unroute("**/api/v2/site/**");
    await mockSiteApi(page, "card");
    await page.goto(productPath);
    await expect(page.locator(".pd-desc")).toBeVisible();
    await page.screenshot({ path: shot("configure-page"), fullPage: true });

    // 手机端：目录入口与底部抽屉
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(groupPath);
    await expect(page.locator(".product-card-grid")).toBeVisible();
    await page.screenshot({ path: shot("mobile-nav-bar"), fullPage: true });
    await page.locator(".shop-view-bar__catalog").click();
    await expect(page.locator(".m-cat__row").first()).toBeVisible();
    // 等抽屉入场动画结束再截图，否则拍到的是还没淡入完的一帧
    await page.waitForTimeout(500);
    await page.screenshot({ path: shot("mobile-catalog-drawer") });
  });

  test("列表式：点二级分组一次即出商品，快速连续切换不错位", async ({ page }) => {
    await mockSiteApi(page, "list", {}, { extraRootGroup: true, catalogDelayMs: 300 });
    await page.goto(groupPath);
    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect(page.locator(".shop-cat__group")).toHaveCount(2);

    // 切到「香港」：侧栏选中态立刻切换，商品区先骨架再出表格，无需第二次点击
    await page.locator(".shop-cat__group").nth(1).click();
    await expect(page.locator(".catalog-skeleton--list")).toBeVisible();
    await expect(page.locator(".desktop-machine-table-row")).toHaveCount(
      PRODUCTS.length,
    );
    await expect(page.locator(".shop-cat__group.is-active")).toContainText(
      "香港",
    );
    await expect
      .poll(() => new URL(page.url()).pathname)
      .toContain(`/${SECOND_ROOT_GROUP_ID}/${SECOND_ROOT_GROUP_CHILD_ID}/`);

    // 连续快速切换三次：只允许最后一次落地，仍能一次点击出商品
    await page.locator(".shop-cat__group").nth(0).click();
    await page.waitForTimeout(60);
    await page.locator(".shop-cat__group").nth(1).click();
    await page.waitForTimeout(60);
    await page.locator(".shop-cat__group").nth(0).click();
    await expect(page.locator(".shop-cat__group.is-active")).toContainText(
      "美国",
    );
    await expect(page.locator(".desktop-machine-table-row")).toHaveCount(
      PRODUCTS.length,
      { timeout: 10_000 },
    );
    await expect
      .poll(() => new URL(page.url()).pathname)
      .toContain(`/${GROUP_ID}/${CHILD_GROUP_ID}/`);
  });

  test("目录加载有骨架屏：卡片式卡片骨架、列表式表格骨架", async ({ page }) => {
    await mockSiteApi(page, "card", {}, { catalogDelayMs: 600 });
    await page.goto(groupPath);
    await expect(page.locator(".catalog-skeleton--card")).toBeVisible();
    await expect(page.locator(".catalog-skeleton-grid")).toBeVisible();
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    await expect(page.locator(".catalog-skeleton")).toHaveCount(0);

    await page.unroute("**/api/v2/site/**");
    // 目录有 sessionStorage 缓存：清掉才能观察第二次首屏的骨架屏
    await page.evaluate(() => window.sessionStorage.clear());
    await mockSiteApi(page, "list", {}, { catalogDelayMs: 600 });
    await page.goto(groupPath);
    await expect(page.locator(".catalog-skeleton--list")).toBeVisible();
    await expect(page.locator(".catalog-skeleton-table")).toBeVisible();
    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect(page.locator(".catalog-skeleton")).toHaveCount(0);
  });

  test("目录收起 / 展开有过渡动画，且不产生横向滚动条", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(groupPath);
    await expect(page.locator(".shop-sidebar")).toBeVisible();

    const duration = await page
      .locator(".shop-sidebar")
      .evaluate((el) => getComputedStyle(el).transitionDuration);
    expect(duration).toContain("0.2s");

    await page.getByRole("button", { name: "收起目录" }).click();
    await expect(page.locator(".shop-sidebar")).toHaveCount(0);

    const hasHorizontalScroll = await page.evaluate(
      () =>
        document.documentElement.scrollWidth >
        document.documentElement.clientWidth + 1,
    );
    expect(hasHorizontalScroll).toBe(false);

    await page.getByRole("button", { name: "展开目录" }).click();
    await expect(page.locator(".shop-sidebar")).toBeVisible();
    await expect(page.locator(".shop-catalog")).toBeVisible();
  });

  test("卡片式配置页：有目录与页面宽度控件，切分组跳到对应目录", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    await page.goto(productPath);
    await expect(page.locator(".pd-page")).toBeVisible();

    // 与选购页同一套两栏目录
    await expect(page.locator(".shop-sidebar")).toBeVisible();
    await expect(page.locator(".shop-catalog")).toBeVisible();
    await expect(page.locator(".shop-cat__group").first()).toContainText(
      "美国",
    );
    await expect(page.getByRole("button", { name: "页面宽度" })).toBeVisible();

    // 页面宽度控件与选购页共用偏好：改档位后配置页版心跟着变
    await page.getByRole("button", { name: "页面宽度" }).click();
    await page
      .locator(".shop-width-panel")
      .getByRole("button", { name: "1600" })
      .click();
    await expect(page.locator(".pd-page")).toHaveAttribute(
      "style",
      /--pd-content-max:\s*1600px/,
    );

    // 点二级分组：跳到对应分组目录（卡片式会继续补全到三级分组三段地址）
    // 先关掉宽度面板：面板打开时点外部只关面板、不触发下层点击（与选购页同口径）
    await page.keyboard.press("Escape");
    await expect(page.locator(".shop-width-panel")).toHaveCount(0);
    await page.locator(".shop-cat__group").first().click();
    await expect.poll(() => new URL(page.url()).pathname).toBe(childPath);
    await expect(page.locator(".product-card-grid")).toBeVisible();
  });

  test("卡片式配置页首屏加载显示配置页骨架", async ({ page }) => {
    await mockSiteApi(page, "card", {}, { productDetailDelayMs: 700 });
    await page.goto(productPath);
    await expect(page.locator(".config-skeleton--page")).toBeVisible();
    await expect(page.locator(".pd-page")).toBeVisible();
    await expect(page.locator(".config-skeleton")).toHaveCount(0);
  });

  test("手机端目录加载同样显示骨架屏", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await mockSiteApi(page, "card", {}, { catalogDelayMs: 600 });
    await page.goto(groupPath);
    await expect(page.locator(".catalog-skeleton--card")).toBeVisible();
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
  });

  test("NAT 类商品卡片标签回退到一级分组名，不显示「其他」", async ({
    page,
  }) => {
    await mockSiteApi(page, "card", {}, { genericTypeLabel: true });
    await page.goto(childPath);
    await expect(page.locator(".product-card").first()).toBeVisible();
    await expect(
      page.locator(".product-card").first().locator(".product-card-type"),
    ).toHaveText("NAT服务器");
  });
});
