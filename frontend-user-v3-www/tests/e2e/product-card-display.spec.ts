import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 选购页「列表式 / 卡片式」切换与商品描述渲染。
 *
 * 背景：选购页此前只有一种形态——商品规格表格与配置区同页铺开，运营没有任何
 * 表达空间（商品没有可编辑的描述，展示方式也无法按站点偏好调整）。本次新增：
 *
 *   - 站点默认展示方式由后台「商品展示」决定，经既有 /v2/site/config 下发；
 *   - 访客可在选购页分组筛选条上方的切换条自行选择列表/卡片，偏好只存本机
 *     localStorage，不写后端、不影响其他访客；
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

    if (pathname === `/v2/site/product-groups/${GROUP_ID}/children`) {
      return respond({
        list: [
          {
            id: CHILD_GROUP_ID,
            effective_product_group_id: CHILD_GROUP_ID,
            effective_product_group_level: 3,
            name: "高防",
            group_level_label: "三级分组",
            product_count: PRODUCTS.length,
          },
        ],
        total: 1,
      });
    }

    if (
      pathname === `/v2/site/product-groups/${GROUP_ID}/products` ||
      pathname === `/v2/site/product-groups/${CHILD_GROUP_ID}/products`
    ) {
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
  test("站点默认列表式：不渲染卡片网格，访客可切到卡片式并记住偏好", async ({
    page,
  }) => {
    await mockSiteApi(page, "list");
    await page.goto(catalogPath);

    await expect(page.locator(".product-card-grid")).toHaveCount(0);
    await expect(page.locator(".desktop-machine-table")).toBeVisible();

    // 切换条在分组筛选条上方、靠右对齐（与筛选条同一行宽度，不悬空）
    const bar = page.locator(".shop-view-bar");
    await expect(bar).toBeVisible();
    const barBox = await bar.boundingBox();
    const switchBox = await page.locator(".shop-view-switch").boundingBox();
    expect(barBox).not.toBeNull();
    expect(switchBox).not.toBeNull();
    expect(switchBox!.x).toBeGreaterThan(barBox!.x + barBox!.width / 2);

    // 默认形态按钮处于选中态
    await expect(
      page.getByRole("button", { name: "列表式", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");

    // 切到卡片式：网格出现、配置区让位
    await page.getByRole("button", { name: "卡片式", exact: true }).click();
    await expect(page.locator(".product-card-grid")).toBeVisible();
    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    await expect(page.locator(".config-area")).toHaveCount(0);

    // 卡片态地址必须保留目录：/products/:type/:group?child=:child
    // 以前这里会退化成裸 /products，刷新/返回都丢分类。
    await expect
      .poll(() => {
        const url = new URL(page.url());
        return `${url.pathname}${url.search}`;
      })
      .toBe(`/products/${TYPE_CODE}/${GROUP_ID}?child=${CHILD_GROUP_ID}`);

    // 偏好只存本机，刷新后仍是卡片式，且目录（三级分组「高防」）仍在
    await expect
      .poll(() =>
        page.evaluate(() => window.localStorage.getItem("turaidc:shop-display-mode")),
      )
      .toBe("card");
    await page.reload();
    await expect(page.locator(".product-card-grid")).toBeVisible();
    await expect(page.locator(".shop-view-bar__name")).toHaveText("高防");
    await expect
      .poll(() => new URL(page.url()).pathname)
      .toBe(`/products/${TYPE_CODE}/${GROUP_ID}`);
  });

  test("卡片式地址保留目录：刷新与从配置页返回都能回到原分类", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    await expect(page.locator(".product-card")).toHaveCount(PRODUCTS.length);
    // 进入卡片态后地址是 /products/hosting/112?child=1030
    await expect
      .poll(() => {
        const url = new URL(page.url());
        return `${url.pathname}?${url.searchParams.get("child")}`;
      })
      .toBe(`/products/${TYPE_CODE}/${GROUP_ID}?${CHILD_GROUP_ID}`);

    // 点卡片进入独立配置页，再点返回：应回到原分类的卡片网格，而不是裸 /products
    await page.locator(".product-card").first().locator(".product-card-name").click();
    await expect(page).toHaveURL(new RegExp(`/products/${PRODUCT_IDS[0]}$`));

    await page.goBack();
    await expect(page.locator(".product-card-grid")).toBeVisible();
    await expect(page.locator(".shop-view-bar__name")).toHaveText("高防");
    await expect
      .poll(() => {
        const url = new URL(page.url());
        return url.searchParams.get("child");
      })
      .toBe(String(CHILD_GROUP_ID));
  });

  test("站点默认卡片式：访客切回列表式后恢复表格配置区", async ({ page }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    await expect(page.locator(".product-card-grid")).toBeVisible();
    await expect(
      page.getByRole("button", { name: "卡片式", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");

    await page.getByRole("button", { name: "列表式", exact: true }).click();

    await expect(page.locator(".product-card-grid")).toHaveCount(0);
    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await expect
      .poll(() =>
        page.evaluate(() => window.localStorage.getItem("turaidc:shop-display-mode")),
      )
      .toBe("list");
    // 列表态选中商品后地址带上商品 ID（以及三级分组），刷新可回到同一配置
    await expect
      .poll(() => {
        const url = new URL(page.url());
        return url.pathname;
      })
      .toBe(`/products/${TYPE_CODE}/${GROUP_ID}/${CHILD_GROUP_ID}/${PRODUCT_IDS[0]}`);
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

    // 后台改成铺满：版心变量下发 100vw，容器不再限宽
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
      /--shop-content-max:\s*100vw/,
    );
  });

  test("访客可调宽屏版心：固定宽度档位或撑满，切换有过渡且配置页跟随", async ({
    page,
  }) => {
    await mockSiteApi(page, "card");
    await page.goto(catalogPath);

    const shopWrap = page.locator(".shop-wrap");
    await expect(shopWrap).toBeVisible();
    // 站点默认：居中 1280
    await expect
      .poll(() => shopWrap.evaluate((el) => getComputedStyle(el).maxWidth))
      .toBe("1280px");

    // 宽度切换必须带过渡（"良好切换动画"）：max-width / border-color 各 0.34s
    const transition = await shopWrap.evaluate(
      (el) => getComputedStyle(el).transitionDuration,
    );
    expect(transition).toContain("0.34s");

    // 打开「页面宽度」面板：默认固定宽度、当前档位 1280
    await page.getByRole("button", { name: "页面宽度" }).click();
    const panel = page.locator(".shop-width-panel");
    await expect(panel).toBeVisible();
    await expect(
      panel.getByRole("button", { name: "固定宽度" }),
    ).toHaveAttribute("aria-pressed", "true");
    await expect(
      panel.getByRole("button", { name: "1280" }),
    ).toHaveAttribute("aria-pressed", "true");

    // 选更大的固定宽度：容器 max-width 跟着变，偏好落 localStorage
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
    await page.screenshot({
      path: `${process.env.TEMP || "."}\\turaidc-www-width-panel.png`,
      fullPage: false,
    });

    // 切到撑满屏幕：变量变 100vw，计算宽度等于视口宽
    await panel.getByRole("button", { name: "撑满屏幕" }).click();
    await expect(shopWrap).toHaveAttribute(
      "style",
      /--shop-content-max:\s*100vw/,
    );
    await expect
      .poll(() => shopWrap.evaluate((el) => getComputedStyle(el).maxWidth))
      .toBe("1440px");

    // 独立配置页跟随同一个版心选择
    await page.goto(`/products/${PRODUCT_IDS[0]}`);
    await expect(page.locator(".pd-page")).toHaveAttribute(
      "style",
      /--pd-content-max:\s*100vw/,
    );

    // 刷新后偏好保留；恢复站点默认后回到 1280 且清掉本机偏好
    await page.goto(catalogPath);
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
    await page.goto(catalogPath);

    // 站点默认卡片式，手机端也能看到切换条（宽度面板只属于宽屏）
    await expect(page.locator(".product-card-grid")).toBeVisible();
    const bar = page.locator(".shop-view-bar");
    await expect(bar).toBeVisible();
    await expect(page.locator(".shop-width-control")).toHaveCount(0);

    await bar.getByRole("button", { name: "列表式", exact: true }).click();
    await expect(page.locator(".product-card-grid")).toHaveCount(0);
    await expect(page.locator(".config-area")).toBeVisible();
    await expect
      .poll(() =>
        page.evaluate(() =>
          window.localStorage.getItem("turaidc:shop-display-mode"),
        ),
      )
      .toBe("list");
    await page.screenshot({
      path: `${process.env.TEMP || "."}\\turaidc-www-mobile-view.png`,
      fullPage: false,
    });

    await bar.getByRole("button", { name: "卡片式", exact: true }).click();
    await expect(page.locator(".product-card-grid")).toBeVisible();
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
    // 等目录与配置区真正渲染完再截图，否则会截到加载中的空白页
    await expect(page.locator(".desktop-machine-table")).toBeVisible();
    await page.screenshot({ path: shot("list-mode"), fullPage: true });

    await page.goto(`/products/${PRODUCT_IDS[1]}`);
    await expect(page.locator(".pd-desc")).toBeVisible();
    await page.screenshot({ path: shot("configure-page"), fullPage: true });
  });
});