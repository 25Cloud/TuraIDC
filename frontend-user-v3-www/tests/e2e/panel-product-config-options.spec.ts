import type { Page } from "@playwright/test";
import { expect, test } from "@playwright/test";

/**
 * 面板型商品（虚拟主机 / CDN）的配置项与描述回归。
 *
 * 线上现象：虚拟主机与 CDN 的配置项在选购页「看不到任何信息」，也没有描述可选购。
 *
 * 根因一：上游智简魔方把这类商品的全部配置项的 field 都给成了 cpu
 * （虚拟主机 5 项：WEB空间 / 数据库空间 / 绑定域名数 / 流量限制 / 带宽）。
 * 本系统官网表单、订单计价、上游提交历来直接拿 field 当键，于是这些项被按
 * 「cpu 就是处理器」分类，全部吸进 CPU 专用选择器，只有第一项能渲染、其余项消失。
 *
 * 根因二：官网拿分组 description 当商品介绍，而生产上第一/二/三级分组的
 * description 全是空字符串，选购页整块留白。
 *
 * 下面 mock 的字段值取自生产真实响应（商品 985），修复后的形态多一个
 * form_key —— 商品内唯一，由后端按上游 config_id 补位。
 */

const TYPE_CODE = "hosting";
const GROUP_ID = 112;
const CHILD_GROUP_ID = 1030;
const PRODUCT_ID = 985;

const CONFIG_OPTIONS = [
  { id: 2336431, name: "WEB空间", values: ["500M"], sort: 1 },
  { id: 2336430, name: "数据库空间", values: ["100M"], sort: 2 },
  { id: 2336427, name: "绑定域名数", values: ["不限制"], sort: 3 },
  { id: 2336428, name: "流量限制", values: ["50G"], sort: 4 },
  // 带宽给两个取值，用来验证各配置项的选中值互不影响
  {
    id: 2336429,
    name: "带宽",
    values: ["100Mbps带宽共享", "200Mbps带宽共享"],
    sort: 5,
  },
].map((item) => ({
  id: item.id,
  field: "cpu",
  spec_key: "",
  // 修复后后端下发：field 被复用时按 config_id 补位成商品内唯一键
  form_key: `cpu#${item.id}`,
  submit_field: "cpu",
  name: item.name,
  description: "",
  hidden: 0,
  required: 0,
  sort_order: item.sort,
  option_type: 6,
  option_mode: "select",
  parameter: item.values.map((value) => `${value}|${value}`).join(","),
  qty_minimum: 0,
  qty_maximum: 0,
  qty_step: 1,
  qty_stage: 1,
  unit: "",
  suffix_text: "",
  text_content: "",
  sub: item.values.map((value, index) => ({
    id: 10007134 + item.sort * 10 + index,
    label: "",
    version: value,
    option_name: value,
    value,
    option_name_first: value,
    hidden: 0,
    sort_order: index,
    qty_minimum: 0,
    qty_maximum: 0,
  })),
}));

const GROUP_PATH = [
  { level: 1, level_label: "一级分组", id: 3, code: "hosting", name: "虚拟主机" },
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

const PRODUCT_SUMMARY = {
  id: PRODUCT_ID,
  name: "美国高防主机-A",
  display_name: "美国高防主机-A",
  product_display_name: "美国高防主机-A",
  combined_display_name: "美国高防主机-A",
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
  pricing: { monthly: "3.00", annually: "30.00" },
  pricing_entries: [
    { cycle: "monthly", label: "月付", amount: "3.00", setup_fee: "0.00", total_amount: "3.00" },
    { cycle: "annually", label: "年付", amount: "30.00", setup_fee: "0.00", total_amount: "30.00" },
  ],
  primary_cycle: "monthly",
  primary_price: "3.00",
  setup_fee: "0.00",
  stock: -1,
  auto_setup: 1,
  spec_highlights: [],
  spec_highlight_text: "",
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
      return respond({ site_name: "测试站点" });
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
            product_count: 17,
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
            product_count: 8,
          },
        ],
        root_groups_total: 1,
        root_groups_page: 1,
        root_groups_page_size: 50,
      });
    }

    // 目录聚合接口：该用例只关心根分组下的一台商品，children 为空
    if (pathname === `/v2/site/product-groups/${GROUP_ID}/catalog`) {
      return respond({
        effective_product_group_id: GROUP_ID,
        effective_product_group_level: 2,
        children: [],
        items_by_group: [
          {
            effective_product_group_id: GROUP_ID,
            effective_product_group_level: 2,
            products: [PRODUCT_SUMMARY],
          },
        ],
      });
    }

    if (pathname === `/v2/site/product-groups/${GROUP_ID}/products`) {
      return respond({
        list: [PRODUCT_SUMMARY],
        total: 1,
        page: 1,
        page_size: 20,
      });
    }

    if (pathname === `/v2/site/products/${PRODUCT_ID}/stock`) {
      return respond({ stock: -1 });
    }

    if (pathname === `/v2/site/products/${PRODUCT_ID}/quote`) {
      return respond({
        base_amount: "3.00",
        setup_fee: "0.00",
        total: "3.00",
        items: [],
      });
    }

    if (pathname === `/v2/site/products/${PRODUCT_ID}`) {
      return respond({
        product: {
          ...PRODUCT_SUMMARY,
          group: {
            id: CHILD_GROUP_ID,
            product_type: "web_hosting",
            product_type_id: 3,
            product_type_label: "虚拟主机",
            name: "高防",
            display_name: "美国高防主机-A",
            // 生产上分组描述全空，这正是选购页没有描述的原因
            slogan: "",
            parent_id: GROUP_ID,
            parent_name: "美国",
            parent_slogan: "",
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
            group_path: GROUP_PATH,
            group_path_text: "虚拟主机 / 美国 / 高防",
            service_type_code: "web_hosting",
            full_name: "虚拟主机 / 美国 / 高防",
          },
          // 修复后后端下发 remark；生产上它是空的
          remark: "",
          config_options: CONFIG_OPTIONS,
          siblings: [],
        },
      });
    }

    if (pathname === "/v2/site/notices" || pathname === "/v2/site/help-articles") {
      return respond({ list: [] });
    }

    return respond({ list: [], total: 0 });
  });
}

const productPath = `/products/${TYPE_CODE}/${GROUP_ID}/${CHILD_GROUP_ID}/${PRODUCT_ID}`;

test.describe("面板型商品的配置项与描述", () => {
  test.beforeEach(async ({ page }) => {
    await mockSiteApi(page);
  });

  test("上游把 field 复用成 cpu 时，全部配置项仍各自可见", async ({ page }) => {
    await page.goto(productPath);

    // 修复前这五项都被判成 CPU 规格，只有第一项渲染，其余四组整组消失
    for (const name of [
      "WEB空间",
      "数据库空间",
      "绑定域名数",
      "流量限制",
      "带宽",
    ]) {
      await expect(
        page.getByText(name, { exact: false }).first(),
      ).toBeVisible();
    }
  });

  test("规格项不被误判成 CPU/内存规格", async ({ page }) => {
    await page.goto(productPath);

    await expect(page.getByText("WEB空间", { exact: false }).first()).toBeVisible();

    const cpuBlocks = page.locator(".config-block-title", { hasText: /^CPU$/ });
    const memBlocks = page.locator(".config-block-title", { hasText: /^内存$/ });
    await expect(cpuBlocks).toHaveCount(0);
    await expect(memBlocks).toHaveCount(0);
  });

  test("描述全空时用真实规格摘要兜底，不留白", async ({ page }) => {
    await page.goto(productPath);

    const intro = page.locator(".product-intro-card");
    await expect(intro).toBeVisible();
    // 分组 description 与商品 remark 都是空的，兜底只能来自真实配置项
    await expect(intro).toContainText("WEB空间");
    await expect(intro).toContainText("500M");
    await expect(intro).toContainText("100Mbps带宽共享");
  });

  test("各配置项的选中值互不覆盖", async ({ page }) => {
    await page.goto(productPath);

    // 结算明细逐项列出选中值。共用 cpu 键时它们会全部变成同一项的取值
    const summaryRow = (label: string) =>
      page
        .locator(".cost-item")
        .filter({ has: page.getByText(label, { exact: true }) })
        .first();

    await expect(summaryRow("WEB空间")).toContainText("500M");
    await expect(summaryRow("数据库空间")).toContainText("100M");
    await expect(summaryRow("流量限制")).toContainText("50G");
    await expect(summaryRow("带宽")).toContainText("100Mbps带宽共享");

    await page
      .locator(".config-block", { hasText: "带宽" })
      .locator(".opt-btn", { hasText: "200Mbps带宽共享" })
      .first()
      .click();

    // 改带宽不该牵动其它项 —— 修复前五项共用一个键，改一个等于改全部
    await expect(summaryRow("带宽")).toContainText("200Mbps带宽共享");
    await expect(summaryRow("WEB空间")).toContainText("500M");
    await expect(summaryRow("流量限制")).toContainText("50G");
  });
});