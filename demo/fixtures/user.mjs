/**
 * 用户端（站点根 `/`，frontend-user-v3-www）示例数据。
 *
 * 键是 `METHOD /path`（路径不含查询串，允许 `:id` 通配）；值是业务 `data`。
 * 首页与选购页的产品、公告、帮助都从 `base.mjs` 的商品目录派生，保证与控制台 /
 * 管理端说的是同一批商品、同一个客户。
 *
 * 商品目录是四层：一级类型 → 二级分组 → 三级分组 → SKU。三处消费方式不同：
 *  - 首页 `/v2/site/home`：`root_groups` 出分类页签，`group_catalog_map` 出卡片文案；
 *  - 选购页：`product-purchase-context` 出类型与二级分组，再按二级分组拉目录聚合
 *    （`children` + `items_by_group`）；
 *  - 详情页：按 SKU 取 `products/:id`，库存另有 `products/:id/stock`，报价走
 *    `POST products/:id/quote`。
 *
 * 目录与详情都是「按 id 命中」的接口，而 transport 的 `routes.find` 按 Object 插入
 * 顺序匹配，所以具体 id 的键必须写在通配键之前——这里全部由目录数据生成，顺序天然正确。
 */
import { CUSTOMER, PRODUCTS, SITE_CONFIG, TICKETS } from './base.mjs';

/* ------------------------------------------------------------------ *
 * 站点配置：在 base 的公开配置上补齐页脚与客服位（都是演示口径）
 * ------------------------------------------------------------------ */
const SITE = {
  ...SITE_CONFIG,
  browser_title: 'TuraIDC 演示站',
  service_email: 'support@example.com',
  service_phone: '400-000-0000',
  service_hours: '工作日 9:00 - 21:00',
  service_qq_group: '演示交流群 1105174267',
  support_group_title: '演示交流群',
  support_group_text: '扫码进群，群里只答疑演示站相关的用法问题。',
  support_group_qr: '',
};

/* ------------------------------------------------------------------ *
 * 金额与计费周期
 * ------------------------------------------------------------------ */

/** 金额一律两位小数字符串，跟后端 `decimal(10,2)` 的口径一致 */
const money = (value) => Number(value).toFixed(2);

/** 月付标价 → 四档周期报价（季付 95 折、半年 93 折、年付 9 折，演示口径） */
function pricingEntries(monthly) {
  const base = Number(monthly);

  return [
    { cycle: 'monthly', label: '月付', amount: money(base), total_amount: money(base) },
    { cycle: 'quarterly', label: '季付', amount: money(base * 3 * 0.95), total_amount: money(base * 3 * 0.95) },
    { cycle: 'semiannually', label: '半年付', amount: money(base * 6 * 0.93), total_amount: money(base * 6 * 0.93) },
    { cycle: 'annually', label: '年付', amount: money(base * 12 * 0.9), total_amount: money(base * 12 * 0.9) },
  ];
}

/** 流量包是一次性商品，不产生续费周期 */
function oneTimePricing(amount) {
  return [{ cycle: 'one_time', label: '一次性', amount: money(amount), total_amount: money(amount) }];
}

/* ------------------------------------------------------------------ *
 * 商品目录：类型 / 二级分组 / 三级分组
 * ------------------------------------------------------------------ */
const TYPES = [
  {
    id: 1,
    code: 'cloud_server',
    slug: 'cloud',
    name: '云服务器',
    groups: [
      {
        id: 3211,
        name: '香港云服务器',
        slug: 'hk-cloud',
        slogan: '三网 CN2 优化线路，建站与跨境业务首选',
        children: [
          { id: 32111, name: '香港-轻量云', products: [3201, 32011] },
          { id: 32112, name: '香港-计算型', products: [32012] },
        ],
      },
      {
        id: 3212,
        name: '新加坡云服务器',
        slug: 'sg-cloud',
        slogan: '东南亚节点，适合测试与轻量业务',
        children: [{ id: 32121, name: '新加坡-轻量云', products: [3203, 32031] }],
      },
    ],
  },
  {
    id: 2,
    code: 'dedicated',
    slug: 'dedicated',
    name: '独立服务器',
    groups: [
      {
        id: 3221,
        name: '日本独立服务器',
        slug: 'jp-dedicated',
        slogan: '独享物理资源，可加 IP 段与大带宽',
        children: [{ id: 32211, name: '日本-单路独服', products: [3202, 32202] }],
      },
    ],
  },
  {
    id: 3,
    code: 'traffic_package',
    slug: 'traffic',
    name: '增值服务',
    groups: [
      {
        id: 3231,
        name: '流量包',
        slug: 'traffic-pack',
        slogan: '当月有效，可叠加在任意云服务器上',
        children: [{ id: 32311, name: '流量包-按个', products: [3204] }],
      },
    ],
  },
];

/**
 * SKU 清单：`baseId` 指向 base.mjs 里的商品（名称、摘要、标签、区域都从它继承），
 * `name` / `price` / `stock` 只在这里覆盖——同族加配置的商品仍然共用一套口径。
 */
const SKU_ROWS = [
  { id: 3201, baseId: 3201, price: '68.00', stock: -1, spec: '2核 4G / 60G SSD / 30Mbps' },
  { id: 32011, baseId: 3201, name: '香港轻量云 2 核 8G', price: '168.00', stock: -1, spec: '2核 8G / 120G SSD / 30Mbps' },
  { id: 32012, baseId: 3201, name: '香港计算型 4 核 8G', price: '268.00', stock: 4, spec: '4核 8G / 120G SSD / 50Mbps' },
  { id: 3203, baseId: 3203, price: '29.00', stock: -1, spec: '1核 2G / 40G SSD / 10Mbps' },
  { id: 32031, baseId: 3203, name: '新加坡云服务器 2 核 4G', price: '59.00', stock: -1, spec: '2核 4G / 60G SSD / 20Mbps' },
  { id: 3202, baseId: 3202, price: '899.00', stock: 6, spec: '16核 32G / 960G SSD / 100Mbps' },
  { id: 32202, baseId: 3202, name: '日本独立服务器 Gold-6133', price: '1299.00', stock: 2, spec: '20核 64G / 2T NVMe / 200Mbps' },
  { id: 3204, baseId: 3204, price: '15.00', stock: -1, spec: '100GB / 当月有效' },
];

const baseProduct = (id) => PRODUCTS.find((item) => item.id === id) || {};

/** SKU 挂在哪个三级分组下（目录、面包屑与下单路径都靠它） */
function locateSku(skuId) {
  for (const type of TYPES) {
    for (const group of type.groups) {
      for (const child of group.children) {
        if (child.products.includes(skuId)) {
          return { type, group, child };
        }
      }
    }
  }

  return null;
}

/* ------------------------------------------------------------------ *
 * 配置项：不同商品族给不同的可选范围，详情页据此渲染选配面板
 * ------------------------------------------------------------------ */

/** 商品描述：列表卡片与详情页共用同一段 HTML（后端口径是富文本片段） */
const FAMILY_DESCRIPTION = {
  cloud_server:
    '<p>三网 CN2 优化线路，Linux 与 Windows 都能装，付款后自动开通。</p><ul><li>按需升降配，配置变更即时生效</li><li>支持快照与自动备份</li></ul>',
  dedicated:
    '<p>独享物理资源，CPU、内存与磁盘按机型固定，可加 IP 段与带宽。</p><ul><li>可选硬件 RAID</li><li>交付前完成硬件自检</li></ul>',
  traffic_package: '<p>当月有效的流量包，可叠加在任意云服务器实例上，用超不会停机。</p>',
};

/** 各族的可选配置范围；`parameter` 是「传参值|展示文案」的逗号串，与上游契约同形 */
const FAMILY_CONFIG = {
  cloud_server: {
    cpu: ['1|1核', '2|2核', '4|4核', '8|8核'],
    memory: ['1024|1G', '2048|2G', '4096|4G', '8192|8G'],
    disk: ['40|40G SSD', '60|60G SSD', '120|120G SSD'],
    bandwidth: ['5|5Mbps', '10|10Mbps', '30|30Mbps', '50|50Mbps'],
    traffic: ['500|500GB/月', '1000|1000GB/月', '2000|2000GB/月'],
    os: [
      'ubuntu-2404|Ubuntu^24.04 LTS',
      'ubuntu-2204|Ubuntu^22.04 LTS',
      'debian-12|Debian^12',
      'almalinux-9|AlmaLinux^9',
      'windows-2022|Windows^Server 2022',
    ],
  },
  dedicated: {
    cpu: ['e5-2650v4|E5-2650 v4（16 核）', 'gold-6133|Gold 6133（20 核）'],
    memory: ['32768|32G', '65536|64G'],
    disk: ['960|960G SSD', '2048|2T NVMe'],
    bandwidth: ['100|100Mbps 独享', '200|200Mbps 独享'],
    traffic: ['10000|10TB/月', '20000|20TB/月'],
    os: [
      'ubuntu-2204|Ubuntu^22.04 LTS',
      'debian-12|Debian^12',
      'almalinux-9|AlmaLinux^9',
      'esxi-8|VMware^ESXi 8',
    ],
  },
  traffic_package: {},
};

/** 区域配置项：详情页把它渲染成区域按钮，不参与其它选配分组 */
const REGION_OPTIONS = {
  香港: 'hk|香港',
  日本: 'jp|日本',
  新加坡: 'sg|新加坡',
  通用: 'global|通用',
};

/**
 * 配置项清单。
 *
 * 字段名沿用后端下发的形状：`field` 是「上游键|展示名」，`form_key` 是商品内唯一键，
 * `parameter` 是「值|文案」串。`option_mode: 'text'` 的项只展示文案、不参与计价。
 */
function configOptions({ family, region }) {
  const spec = FAMILY_CONFIG[family] || {};
  const options = [
    {
      field: 'area|区域',
      form_key: 'area',
      option_type: 20,
      parameter: REGION_OPTIONS[region] || REGION_OPTIONS['通用'],
      sort_order: 1,
    },
  ];

  const append = (label, formKey, parameter, sortOrder, extra = {}) => {
    if (!Array.isArray(parameter) || parameter.length === 0) {
      return;
    }

    options.push({
      field: `${formKey}|${label}`,
      form_key: formKey,
      option_type: 20,
      parameter: parameter.join(','),
      sort_order: sortOrder,
      ...extra,
    });
  };

  append('CPU', 'cpu', spec.cpu, 2);
  append('内存', 'memory', spec.memory, 3);
  append('系统盘', 'disk', spec.disk, 4);
  append('带宽', 'bandwidth', spec.bandwidth, 5, { unit: 'Mbps' });
  append('月流量', 'traffic', spec.traffic, 6);
  append('操作系统', 'os', spec.os, 7);

  options.push({
    field: 'notice|购买须知',
    form_key: 'notice',
    option_mode: 'text',
    text_content: '演示环境：下单不会真实扣款，也不会真的开通实例。',
    sort_order: 99,
  });

  return options;
}

/* ------------------------------------------------------------------ *
 * 组装：分组路径、二级分组、SKU 载荷
 * ------------------------------------------------------------------ */

/** 面包屑：一级 → 二级 →（三级） */
function groupPath({ type, group, child }) {
  const nodes = [
    { level: 1, level_label: '一级分组', id: type.id, code: type.code, name: type.name },
    { level: 2, level_label: '二级分组', id: group.id, code: group.slug, name: group.name },
  ];

  if (child) {
    nodes.push({ level: 3, level_label: '三级分组', id: child.id, code: '', name: child.name });
  }

  return nodes;
}

function groupPayload({ type, group, child = null }) {
  const level = child ? 3 : 2;
  const path = groupPath({ type, group, child });

  return {
    id: child ? child.id : group.id,
    parent_id: child ? group.id : null,
    name: child ? child.name : group.name,
    slogan: child ? '' : group.slogan,
    slug: child ? '' : group.slug,
    level,
    group_level_label: level === 3 ? '三级分组' : '二级分组',
    product_type: type.code,
    product_type_id: type.id,
    product_type_label: type.name,
    first_product_group_id: type.id,
    first_product_group_code: type.code,
    first_product_group_name: type.name,
    first_product_group_slug: type.slug,
    second_product_group_id: group.id,
    second_product_group_name: group.name,
    third_product_group_id: child ? child.id : null,
    third_product_group_name: child ? child.name : null,
    effective_product_group_id: child ? child.id : group.id,
    effective_product_group_level: level,
    group_path: path,
    group_path_text: path.map((node) => node.name).join(' / '),
    children_count: 0,
    direct_product_count: 0,
    product_count: 0,
    children: [],
  };
}

/** SKU 载荷：列表卡片与详情页共用同一份（详情多带 config_options） */
function productPayload(row) {
  const base = baseProduct(row.baseId);
  const { type, group, child } = locateSku(row.id);
  const name = row.name || base.display_name || base.name || `演示商品 ${row.id}`;
  const family = base.product_type || 'cloud_server';
  const path = groupPath({ type, group, child });
  const isOneTime = family === 'traffic_package';

  return {
    id: row.id,
    name,
    display_name: name,
    combined_display_name: name,
    product_display_name: name,
    instance_spec_text: row.spec,
    instance_spec_note: '演示数据：规格取当前机型，选配后按实际配置计价。',
    product_type: family,
    type: family,
    type_label: type.name,
    product_type_label: type.name,
    first_product_group_id: type.id,
    first_product_group_code: type.code,
    first_product_group_name: type.name,
    first_product_group_slug: type.slug,
    second_product_group_id: group.id,
    second_product_group_name: group.name,
    third_product_group_id: child.id,
    third_product_group_name: child.name,
    effective_product_group_id: child.id,
    effective_product_group_level: 3,
    group_path: path,
    group_path_text: path.map((node) => node.name).join(' / '),
    region: base.region || '通用',
    summary: base.summary || '',
    tags: Array.isArray(base.tags) ? base.tags : [],
    description_html: FAMILY_DESCRIPTION[family] || '',
    primary_price: money(row.price),
    agent_primary_price: money(row.price),
    has_agent_discount: false,
    agent_discount_percent: '',
    primary_cycle: isOneTime ? 'one_time' : 'monthly',
    setup_fee: '0.00',
    setup_fee_display: '0.00',
    pricing_entries: isOneTime ? oneTimePricing(row.price) : pricingEntries(row.price),
    pricing: isOneTime ? { one_time: money(row.price) } : { monthly: money(row.price) },
    stock: row.stock,
    config_options: configOptions({ family, region: base.region || '通用' }),
  };
}

const SKU_LIST = SKU_ROWS.map(productPayload);

/** 二级分组：children 挂上全部三级分组，商品数量由 SKU 反算 */
function buildSecondGroups() {
  const groups = [];

  for (const type of TYPES) {
    for (const group of type.groups) {
      const payload = groupPayload({ type, group });
      payload.children = group.children.map((child) => {
        const childPayload = groupPayload({ type, group, child });
        childPayload.product_count = child.products.length;
        return childPayload;
      });
      payload.children_count = payload.children.length;
      payload.product_count = group.children.reduce((sum, child) => sum + child.products.length, 0);
      groups.push(payload);
    }
  }

  return groups;
}

const SECOND_GROUPS = buildSecondGroups();

/** 类型列表：一级分组的商品数与分组数按目录反算 */
const PRODUCT_TYPES = TYPES.map((type) => {
  const productCount = type.groups.reduce(
    (sum, group) => sum + group.children.reduce((childSum, child) => childSum + child.products.length, 0),
    0,
  );

  return {
    id: type.id,
    value: type.code,
    label: type.name,
    slug: type.slug,
    product_type: type.code,
    product_type_label: type.name,
    product_type_id: type.id,
    first_product_group_id: type.id,
    first_product_group_code: type.code,
    first_product_group_name: type.name,
    group_count: type.groups.length,
    product_count: productCount,
  };
});

/** 找二级分组定义（含它的三级分组） */
function groupDefinition(groupId) {
  for (const type of TYPES) {
    const group = type.groups.find((item) => item.id === Number(groupId));

    if (group) {
      return { type, group };
    }
  }

  return null;
}

/**
 * 目录聚合：`children` 给侧栏，`items_by_group` 给商品区。
 *
 * `items_by_group` 的每一项都必须带 `effective_product_group_id` +
 * `effective_product_group_level`：前端用「层级:id」做分组键，裸 id 会让二级
 * 与三级分组互相覆盖。聚合响应本身还要带 `effective_product_group_id`，
 * 前端用它丢弃快速切组时晚到的旧响应。
 */
function catalogPayload(groupId) {
  const found = groupDefinition(groupId);

  if (!found) {
    return null;
  }

  const { type, group } = found;
  const secondGroup = SECOND_GROUPS.find((item) => item.id === group.id);
  const children = secondGroup ? secondGroup.children : [];

  return {
    effective_product_group_id: group.id,
    effective_product_group_level: 2,
    children,
    items_by_group: [
      { ...groupPayload({ type, group }), products: [] },
      ...children.map((child) => ({
        ...child,
        products: SKU_LIST.filter((sku) => sku.third_product_group_id === child.id),
      })),
    ],
  };
}

/** 某个分组下的全部商品（二级分组含各三级分组的商品） */
function productsUnderGroup(groupId) {
  const id = Number(groupId);

  if (SECOND_GROUPS.some((group) => group.id === id)) {
    const group = SECOND_GROUPS.find((item) => item.id === id);
    const childIds = (group.children || []).map((child) => child.id);
    return SKU_LIST.filter((sku) => childIds.includes(sku.third_product_group_id));
  }

  return SKU_LIST.filter((sku) => sku.third_product_group_id === id);
}

/* ------------------------------------------------------------------ *
 * 公告与帮助中心（列表 / 详情 / 概览共用同一批文章）
 * ------------------------------------------------------------------ */
const NOTICE_CATEGORIES = [
  { id: 1, name: '活动公告', slug: 'activity', description: '促销与限时活动', sort_order: 1, articles_count: 1 },
  { id: 2, name: '产品更新', slug: 'updates', description: '节点与功能更新', sort_order: 2, articles_count: 2 },
  { id: 3, name: '服务通知', slug: 'service', description: '计费与合规通知', sort_order: 3, articles_count: 2 },
];

const HELP_CATEGORIES = [
  { id: 11, name: '新手入门', slug: 'start', description: '第一次使用先看这里', sort_order: 1, articles_count: 2 },
  { id: 12, name: '支付账单', slug: 'billing', description: '充值、发票与账单', sort_order: 2, articles_count: 1 },
  { id: 13, name: '实例管理', slug: 'instance', description: '开通、重装与续费', sort_order: 3, articles_count: 2 },
];

const NOTICES = [
  {
    id: 6101,
    content_type: 'notice',
    title: '双 11 活动：新客首单 39 元/年起',
    slug: 'double-11-2026',
    summary: '2 核 2G 云服务器年付 39 元，香港与新加坡节点同价，活动截止 11 月 30 日。',
    category_id: 1,
    category_name: '活动公告',
    category_slug: 'activity',
    is_pinned: 1,
    is_recommended: 0,
    publish_at: '2026-11-01 10:00:00',
    updated_at: '2026-11-05 09:20:00',
    view_count: 1284,
    operator: '演示运营',
    content: [
      '## 活动时间',
      '2026 年 11 月 1 日 10:00 至 11 月 30 日 23:59。',
      '## 活动机型',
      '- 香港轻量云 2 核 2G：年付 39 元（原价 588 元）',
      '- 新加坡云服务器 1 核 2G：年付 39 元（原价 348 元）',
      '## 参与方式',
      '1. 注册并完成实名认证；',
      '2. 在商品页选择「年付」周期下单；',
      '3. 系统自动抵扣，无需领券。',
      '> 演示数据：本页数据为示例，下单不会真实扣款，也不会开通实例。',
    ].join('\n\n'),
  },
  {
    id: 6102,
    content_type: 'notice',
    title: '香港 CN2 线路升级完成',
    slug: 'hk-cn2-upgrade',
    summary: '三网 CN2 GIA 优化回落，跨境业务时延下降约 30%，已全量生效。',
    category_id: 2,
    category_name: '产品更新',
    category_slug: 'updates',
    is_pinned: 0,
    is_recommended: 1,
    publish_at: '2026-10-28 09:30:00',
    updated_at: '2026-10-28 09:30:00',
    view_count: 762,
    operator: '演示运营',
    content: [
      '## 升级内容',
      '香港节点回程由普通 BGP 切换为三网 CN2 GIA，电信、联通、移动分别优化。',
      '## 影响范围',
      '- 已开通实例无需迁移，重启一次网络栈即可生效；',
      '- 新建实例默认走新线路。',
      '## 实测数据',
      '| 线路 | 升级前 | 升级后 |',
      '| --- | --- | --- |',
      '| 电信 | 62ms | 41ms |',
      '| 联通 | 78ms | 52ms |',
      '| 移动 | 95ms | 63ms |',
    ].join('\n\n'),
  },
  {
    id: 6103,
    content_type: 'notice',
    title: '新加坡节点上线公告',
    slug: 'sg-node-launch',
    summary: '新加坡机房正式开放，1 核 2G 起售 29 元/月，支持月付与年付。',
    category_id: 2,
    category_name: '产品更新',
    category_slug: 'updates',
    is_pinned: 0,
    is_recommended: 1,
    publish_at: '2026-10-20 15:00:00',
    updated_at: '2026-10-22 11:10:00',
    view_count: 534,
    operator: '演示运营',
    content: [
      '## 节点信息',
      '新加坡机房，10Mbps 带宽起步，适合东南亚业务与测试环境。',
      '## 可选系统',
      'Ubuntu、Debian、AlmaLinux 与 Windows Server，重装不额外收费。',
    ].join('\n\n'),
  },
  {
    id: 6104,
    content_type: 'notice',
    title: '账单与发票规则调整说明',
    slug: 'billing-invoice-adjust',
    summary: '自 11 月起账期统一为自然月，发票申请改为订单完成后 7 天内提交。',
    category_id: 3,
    category_name: '服务通知',
    category_slug: 'service',
    is_pinned: 0,
    is_recommended: 0,
    publish_at: '2026-10-12 10:00:00',
    updated_at: '2026-10-12 10:00:00',
    view_count: 318,
    operator: '演示财务',
    content: [
      '## 账期',
      '续费账单统一在到期前 7 天生成，到期未续费进入 3 天宽限期。',
      '## 发票',
      '订单完成后 7 天内可在控制台提交开票申请，纸质发票不再提供。',
    ].join('\n\n'),
  },
  {
    id: 6105,
    content_type: 'notice',
    title: '服务等级协议（SLA）更新',
    slug: 'sla-update',
    summary: '可用性承诺提升到 99.9%，赔付改为按自然日折算到账户余额。',
    category_id: 3,
    category_name: '服务通知',
    category_slug: 'service',
    is_pinned: 0,
    is_recommended: 0,
    publish_at: '2026-09-30 09:00:00',
    updated_at: '2026-09-30 09:00:00',
    view_count: 205,
    operator: '演示运营',
    content: [
      '## 可用性承诺',
      '单实例月度可用性不低于 99.9%，低于该值时按缺口天数折算赔付。',
      '## 赔付方式',
      '赔付直接计入账户余额，可用于续费与购买其它商品。',
    ].join('\n\n'),
  },
];

const HELP_ARTICLES = [
  {
    id: 8101,
    content_type: 'help',
    title: '如何选购第一台云服务器',
    slug: 'first-server',
    summary: '从选节点、选配置到付款开通，一条完整的下单路径。',
    category_id: 11,
    category_name: '新手入门',
    category_slug: 'start',
    is_pinned: 1,
    is_recommended: 1,
    publish_at: '2026-10-08 10:00:00',
    updated_at: '2026-10-30 16:20:00',
    view_count: 3412,
    operator: '演示客服',
    content: [
      '## 第一步：选节点',
      '面向大陆用户优先选香港，东南亚业务选新加坡。',
      '## 第二步：选配置',
      '建站起步 2 核 4G，纯静态站 1 核 2G 够用。',
      '## 第三步：付款开通',
      '下单后由上游接口自动开通，通常在 1 分钟内完成。',
    ].join('\n\n'),
  },
  {
    id: 8102,
    content_type: 'help',
    title: '支持哪些支付方式',
    slug: 'payment-methods',
    summary: '余额、易支付、支付宝当面付都能用，到账后即时开通。',
    category_id: 12,
    category_name: '支付账单',
    category_slug: 'billing',
    is_pinned: 0,
    is_recommended: 1,
    publish_at: '2026-10-06 11:00:00',
    updated_at: '2026-10-26 09:40:00',
    view_count: 2260,
    operator: '演示财务',
    content: [
      '## 支付方式',
      '- 账户余额：下单直接扣减，到账最快；',
      '- 易支付：支持支付宝与微信；',
      '- 支付宝当面付：适合大额充值。',
      '## 到账时间',
      '第三方通道通常 5 秒内回调，回调成功后即时开通。',
    ].join('\n\n'),
  },
  {
    id: 8103,
    content_type: 'help',
    title: '如何重装系统与重置密码',
    slug: 'reinstall-password',
    summary: '控制台里两步完成，重装会清空数据盘，操作前先做快照。',
    category_id: 13,
    category_name: '实例管理',
    category_slug: 'instance',
    is_pinned: 0,
    is_recommended: 0,
    publish_at: '2026-10-05 09:30:00',
    updated_at: '2026-10-24 14:05:00',
    view_count: 1877,
    operator: '演示客服',
    content: [
      '## 重装系统',
      '进入实例详情，选择「重装系统」，挑选镜像并确认。重装会清空系统盘。',
      '## 重置密码',
      '实例处于运行中才能重置密码，重置后需要重启生效。',
    ].join('\n\n'),
  },
  {
    id: 8104,
    content_type: 'help',
    title: '续费与到期提醒说明',
    slug: 'renewal',
    summary: '到期前 7 天、3 天、1 天各提醒一次，逾期 3 天进入暂停。',
    category_id: 13,
    category_name: '实例管理',
    category_slug: 'instance',
    is_pinned: 0,
    is_recommended: 0,
    publish_at: '2026-10-04 08:00:00',
    updated_at: '2026-10-21 10:15:00',
    view_count: 1420,
    operator: '演示客服',
    content: [
      '## 提醒节奏',
      '到期前 7 天、3 天、1 天通过站内信与邮件提醒。',
      '## 逾期处理',
      '到期后进入 3 天宽限期，仍未续费则暂停并保留数据 7 天。',
    ].join('\n\n'),
  },
  {
    id: 8105,
    content_type: 'help',
    title: '实名认证要准备什么材料',
    slug: 'real-name',
    summary: '个人与企业两类，企业认证需要营业执照与授权书。',
    category_id: 11,
    category_name: '新手入门',
    category_slug: 'start',
    is_pinned: 0,
    is_recommended: 0,
    publish_at: '2026-10-02 15:00:00',
    updated_at: '2026-10-18 11:30:00',
    view_count: 986,
    operator: '演示客服',
    content: [
      '## 个人认证',
      '身份证正反面照片，四角完整、无遮挡。',
      '## 企业认证',
      '营业执照、法人身份证与授权书，授权书模板可在控制台下载。',
    ].join('\n\n'),
  },
];

/** 列表面板还要一个摘要字段：`excerpt` 与 `summary` 同源，避免两处文案分叉 */
const withExcerpt = (article) => ({ ...article, excerpt: article.summary });

/* ------------------------------------------------------------------ *
 * 首页聚合
 * ------------------------------------------------------------------ */

/** 轮播：五张卡片与前端内置默认轮播同构，文案换成演示站口径（不含真实客户与域名） */
const HERO = {
  slides: [
    {
      key: 'demo-cloud',
      rail_title: '云服务器',
      title: '弹性云服务器，1 分钟开通',
      desc: '香港、新加坡双节点，按需升降配，付款后由上游接口自动开通。',
      primary_text: '查看机型',
      primary_path: '/products',
      secondary_text: '帮助中心',
      secondary_path: '/help',
      shape: 'computer',
      ribbon: '热卖',
      ribbon_type: 'hot',
    },
    {
      key: 'demo-dedicated',
      rail_title: '独立服务器',
      title: '独享物理机，随需加大带宽',
      desc: '日本 E5 与 Gold 机型，可选硬件 RAID 与额外 IP 段。',
      primary_text: '查看机型',
      primary_path: '/products',
      secondary_text: '了解计费',
      secondary_path: '/help',
      shape: 'connection',
      ribbon: '',
      ribbon_type: 'new',
    },
    {
      key: 'demo-billing',
      rail_title: '计费与账单',
      title: '金额服务端重算，账单可对账',
      desc: '月付、季付、半年付、年付四档周期，升级按剩余价值折算。',
      primary_text: '查看机型',
      primary_path: '/products',
      secondary_text: '阅读文档',
      secondary_path: '/help',
      shape: 'value',
      ribbon: '',
      ribbon_type: 'warm',
    },
    {
      key: 'demo-support',
      rail_title: '服务支持',
      title: '工单直达上游，进度可追踪',
      desc: '开通、重装、续费都有对应工单与状态流转，超时自动升级。',
      primary_text: '帮助中心',
      primary_path: '/help',
      secondary_text: '公告通知',
      secondary_path: '/notices',
      shape: 'support',
      ribbon: '',
      ribbon_type: 'new',
    },
    {
      key: 'demo-activity',
      rail_title: '新客活动',
      title: '新客首单 39 元起',
      desc: '2 核 2G 年付 39 元，香港与新加坡同价，活动截止 11 月 30 日。',
      primary_text: '立即选购',
      primary_path: '/products',
      secondary_text: '活动详情',
      secondary_path: '/notices',
      shape: 'security',
      ribbon: '活动',
      ribbon_type: 'warm',
    },
  ],
  features: [
    { key: 'cn2', kicker: '网络', title: '三网 CN2 优化', desc: '香港回程走 CN2 GIA，跨境业务时延更低。', path: '/products' },
    { key: 'auto', kicker: '交付', title: '付款即开通', desc: '上游插件自动下发，失败自动重试。', path: '/products' },
    { key: 'billing', kicker: '计费', title: '四档计费周期', desc: '月付到年付，升级按剩余价值折算。', path: '/help' },
    { key: 'stock', kicker: '库存', title: '库存实时可查', desc: '商品页直接显示剩余库存与开通状态。', path: '/products' },
    { key: 'support', kicker: '服务', title: '工单与公告', desc: '公告、帮助文档与工单都在同一处。', path: '/notices' },
  ],
};

/** 首页卡片用的分组目录：`featured_product` 决定卡片文案，`preview_products` 是预览 */
const GROUP_CATALOG_MAP = Object.fromEntries(
  SECOND_GROUPS.map((group) => {
    const groupSkus = productsUnderGroup(group.id);

    return [
      String(group.id),
      {
        featured_product: groupSkus[0] || null,
        preview_products: groupSkus.slice(0, 3),
      },
    ];
  }),
);

const HOME = {
  site_config: SITE,
  hero: HERO,
  notices: NOTICES.map(withExcerpt),
  root_groups: SECOND_GROUPS,
  group_catalog_map: GROUP_CATALOG_MAP,
  // 只报目录规模，不宣称真实客户数或可用性
  stats: { products: SKU_LIST.length, groups: SECOND_GROUPS.length, notices: NOTICES.length },
};

/* ------------------------------------------------------------------ *
 * 报价与逐 id 命中接口
 * ------------------------------------------------------------------ */

/**
 * 报价：金额按商品首档周期的标价静态给出。
 *
 * 真实后端会按周期、配置项与数量重算，fixture 只能给静态值——够让价格区有数字，
 * 但切换周期时金额不会跟着变，属演示站已知取舍（角标已说明数据为示例）。
 */
function quotePayload(sku) {
  const entry = sku.pricing_entries[0];
  const amount = money(entry.amount);

  return {
    product_id: sku.id,
    billing_cycle: entry.cycle,
    quantity: 1,
    base_amount: amount,
    setup_fee: '0.00',
    discount_amount: '0.00',
    total_amount: amount,
    original_total_amount: amount,
    agent_amount: amount,
    agent_discount_amount: '0.00',
    agent_discount_rate: 100,
    agent_group_name: '',
    quote_token: `demo-quote-${sku.id}`,
    items: [{ name: sku.display_name, amount }],
    coupon: null,
    available_coupons: [],
  };
}

/** 分组的目录 / 子分组 / 商品：逐 id 写死，避免所有分组共用同一份数据 */
function explicitGroupEndpoints() {
  const entries = {};

  for (const group of SECOND_GROUPS) {
    const products = productsUnderGroup(group.id);
    entries[`GET /v2/site/product-groups/${group.id}/catalog`] = catalogPayload(group.id);
    entries[`GET /v2/site/product-groups/${group.id}/children`] = {
      list: group.children,
      total: group.children.length,
    };
    entries[`GET /v2/site/product-groups/${group.id}/products`] = {
      list: products,
      total: products.length,
    };

    for (const child of group.children) {
      const childProducts = productsUnderGroup(child.id);
      entries[`GET /v2/site/product-groups/${child.id}/products`] = {
        list: childProducts,
        total: childProducts.length,
      };
      entries[`GET /v2/site/product-groups/${child.id}/children`] = { list: [], total: 0 };
    }
  }

  return entries;
}

/** SKU 的详情 / 库存 / 报价：逐 id 写死，详情页才能拿到对应那一台商品的配置 */
function explicitProductEndpoints() {
  const entries = {};

  for (const sku of SKU_LIST) {
    entries[`GET /v2/site/products/${sku.id}`] = { product: sku };
    entries[`GET /v2/site/products/${sku.id}/stock`] = { stock: sku.stock };
    entries[`POST /v2/site/products/${sku.id}/quote`] = quotePayload(sku);
  }

  return entries;
}

/** 公告与帮助详情：逐 id 写死，文章内容随列表一起变 */
function explicitContentEndpoints() {
  const entries = {};

  for (const article of NOTICES) {
    entries[`GET /v2/site/notices/${article.id}`] = { article };
  }

  for (const article of HELP_ARTICLES) {
    entries[`GET /v2/site/help-articles/${article.id}`] = { article };
  }

  return entries;
}

export function userFixtures() {
  return {
    endpoints: {
      'GET /v2/site/config': SITE,

      // 首页聚合：轮播 + 分类页签 + 分组卡片 + 公告，首屏一次拿齐
      'GET /v2/site/home': HOME,

      'GET /v2/site/product-types': { list: PRODUCT_TYPES },

      // 选购页初始化：类型 + 二级分组 + 第一个分组的目录（后续切组按分组单独拉）
      'GET /v2/site/product-purchase-context': {
        types: PRODUCT_TYPES,
        root_groups: SECOND_GROUPS,
        catalog: catalogPayload(SECOND_GROUPS[0]?.id),
      },

      // 二级分组列表：查询里的 first_product_group_code 由前端本地过滤，这里给全量
      'GET /v2/site/product-groups': { list: SECOND_GROUPS, total: SECOND_GROUPS.length },

      // 扁平商品列表（卡片式「全部商品」与部分跳转入口用）
      'GET /v2/site/products': { list: SKU_LIST, total: SKU_LIST.length },

      // 逐 id 的目录与商品，必须排在通配键之前
      ...explicitGroupEndpoints(),
      ...explicitProductEndpoints(),
      ...explicitContentEndpoints(),

      // 公告与帮助中心：列表 / 详情 / 概览共用同一批文章
      'GET /v2/site/content/overview': {
        notices: NOTICES.slice(0, 4).map(withExcerpt),
        help_articles: HELP_ARTICLES.slice(0, 4).map(withExcerpt),
        notice_categories: NOTICE_CATEGORIES,
        help_categories: HELP_CATEGORIES,
      },
      'GET /v2/site/notices': {
        list: NOTICES.map(withExcerpt),
        categories: NOTICE_CATEGORIES,
        page: 1,
        page_size: 10,
        total: NOTICES.length,
      },
      'GET /v2/site/help-articles': {
        list: HELP_ARTICLES.map(withExcerpt),
        categories: HELP_CATEGORIES,
        page: 1,
        page_size: 10,
        total: HELP_ARTICLES.length,
      },

      // 站点根按登录态展示：演示 token 已在启动时写入，这里直接给演示客户，
      // 头部才会出现「控制台 / 我的实例」入口（与 /console 的登录态一致）
      'GET /v2/client/auth/info': {
        user: CUSTOMER,
        permissions: ['*'],
        features: { ai_assistant: false, auto_refund: false },
        account_policy: { required: false, missing_items: [] },
      },
      'GET /v2/client/tickets': { items: TICKETS, total: TICKETS.length },
    },
    refused: {
      'POST /v2/client/orders': { message: '演示环境不执行下单' },
    },
  };
}
