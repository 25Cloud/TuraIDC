/**
 * 管理端（/admin）示例数据。
 *
 * 键是 `METHOD /path`（路径不含查询串，允许 `:id` 通配）；值是业务 `data`，
 * 传输层会自己套 `{code: 0, message: 'success', data}`。
 *
 * 补数据的三条口径：
 * 1. 路由**按声明顺序首次匹配**（shared/demo/transport.ts 的 routes.find），所以
 *    `products/summary`、`tickets/summary` 这类字面路径必须写在同一段数的 `:id` 之前。
 * 2. 一切从 `base.mjs` 派生：实例、账单、工单、商品说的是同一个客户同一台机器，
 *    金额一律字符串，状态用后端数字码（实例 1 已开通 / 2 已暂停；账单 0 待支付 / 1 已支付；
 *    订单 0 待付款 / 3 已完成；工单 0 开启 / 2 员工回复 / 3 已关闭）。
 * 3. 只铺读接口；写接口交给传输层回成功信封，危险动作在下面 `refused` 里拒绝。
 */
import { ADMIN_SESSION, CUSTOMER, INVOICES, PRODUCTS, SERVICES, SITE_CONFIG, TICKETS } from './base.mjs';

/** 实例状态码：0 开通中 / 1 已开通 / 2 已暂停 / 3 已到期 / 4 已取消 */
const SERVICE_STATUS_CODE = { pending: 0, active: 1, suspended: 2, expired: 3, cancelled: 4 };

/** 工单状态码：0 开启 / 1 客户回复 / 2 员工回复 / 3 已关闭 */
const TICKET_STATUS_CODE = { open: 0, user_reply: 1, staff_reply: 2, replied: 2, closed: 3 };

/** 账单状态：0 待支付 / 1 已支付 */
const INVOICE_STATUS_CODE = { unpaid: 0, paid: 1 };

/** 订单状态：0 待付款 / 3 已完成 */
const ORDER_STATUS_CODE = { unpaid: 0, completed: 3 };

const BILLING_CYCLE_LABEL = { monthly: '月付', quarterly: '季付', semiannually: '半年付', yearly: '年付' };

/** 金额统一两位小数字符串，与后端 decimal 序列化一致 */
function money(value) {
  return Number(value || 0).toFixed(2);
}

function serviceOf(id) {
  return SERVICES.find((item) => item.id === id) || SERVICES[0];
}

function productOfService(service) {
  return PRODUCTS.find((item) => item.name === service.product_name) || PRODUCTS[0];
}

function serviceStatusCode(service) {
  return SERVICE_STATUS_CODE[String(service.status || '').toLowerCase()] ?? 1;
}

function ticketStatusCode(ticket) {
  return TICKET_STATUS_CODE[String(ticket.status || '').toLowerCase()] ?? 0;
}

/** 客户列表：base 的演示客户 + 两个只用于「多行列表」的演示客户 */
const DEMO_USERS = [
  {
    ...CUSTOMER,
    status: 1,
    verification_status: 1,
    is_verified: 1,
    id_card_masked: 'DEMO**********0000',
    company: '',
    qq: '',
    admin_note: '演示站点：此客户名下的实例、账单、工单与控制台展示的是同一套数据。',
    member_level: { id: 2, name: '黄金会员', code: 'gold' },
    agent_group: { id: 1, name: '金牌代理', code: 'gold', status: 1 },
    agent_group_id: 1,
    referrer_user_id: null,
    opened_product_count: SERVICES.length,
    referral_withdrawn_balance: '0.00',
    last_login_at: '2026-11-09 08:41:20',
    last_login_ip: '203.0.113.20',
  },
  {
    id: 1002,
    nickname: '演示客户二',
    username: 'demo2',
    email: 'demo2@example.com',
    phone: '13800000002',
    real_name: '演示客户二',
    balance: '320.00',
    credit_limit: '0.00',
    coupon_count: 0,
    company: '演示科技有限公司',
    qq: '',
    admin_note: '',
    id_card_masked: 'DEMO**********0002',
    status: 1,
    verification_status: 0,
    is_verified: 0,
    member_level: { id: 1, name: '普通会员', code: 'basic' },
    agent_group: null,
    agent_group_id: null,
    referrer_user_id: 1001,
    opened_product_count: 1,
    referral_withdrawn_balance: '50.00',
    created_at: '2026-03-06 15:20:08',
    last_login_at: '2026-11-01 12:02:44',
    last_login_ip: '198.51.100.30',
  },
  {
    id: 1003,
    nickname: '演示客户三',
    username: 'demo3',
    email: 'demo3@example.com',
    phone: '13800000003',
    real_name: '演示客户三',
    balance: '0.00',
    credit_limit: '500.00',
    coupon_count: 1,
    company: '',
    qq: '10000003',
    admin_note: '演示用：状态为停用，登录会被拒绝。',
    id_card_masked: '',
    status: 0,
    verification_status: 0,
    is_verified: 0,
    member_level: { id: 1, name: '普通会员', code: 'basic' },
    agent_group: { id: 2, name: '银牌代理', code: 'silver', status: 1 },
    agent_group_id: 2,
    referrer_user_id: 1001,
    opened_product_count: 0,
    referral_withdrawn_balance: '0.00',
    created_at: '2026-05-21 16:03:27',
    last_login_at: '2026-09-18 09:15:02',
    last_login_ip: '192.0.2.88',
  },
];

function userOf(id) {
  const user = DEMO_USERS.find((item) => item.id === id) || DEMO_USERS[0];

  return user;
}

/** 列表里的用户摘要：只留展示字段，避免每行都塞一整份客户资料 */
function userSummary(id) {
  const user = userOf(id);

  return {
    id: user.id,
    nickname: user.nickname,
    display_name: user.display_name,
    email: user.email,
    phone: user.phone,
    company: user.company || '',
  };
}

const USER_OWNERS = { 5101: 1001, 5102: 1001, 5103: 1001 };

function ownerOfService(service) {
  return USER_OWNERS[service.id] || 1001;
}

/** 商品分组树：云服务器 / 独立服务器 / 增值服务，与 base 商品目录的 group_name 对得上 */
const PRODUCT_GROUPS = [
  {
    id: 4101,
    name: '云服务器',
    code: 'cloud',
    level: 1,
    parent_id: null,
    product_type: 'cloud_server',
    product_type_label: '云服务器',
    sort_order: 0,
    status: 1,
    children: [
      { id: 4111, name: '香港', code: 'cloud-hk', level: 2, parent_id: 4101, sort_order: 0, status: 1, children: [] },
      { id: 4112, name: '新加坡', code: 'cloud-sg', level: 2, parent_id: 4101, sort_order: 1, status: 1, children: [] },
    ],
  },
  {
    id: 4102,
    name: '独立服务器',
    code: 'dedicated',
    level: 1,
    parent_id: null,
    product_type: 'dedicated',
    product_type_label: '独立服务器',
    sort_order: 1,
    status: 1,
    children: [
      { id: 4121, name: '日本', code: 'dedicated-jp', level: 2, parent_id: 4102, sort_order: 0, status: 1, children: [] },
    ],
  },
  {
    id: 4103,
    name: '增值服务',
    code: 'value-added',
    level: 1,
    parent_id: null,
    product_type: 'traffic_package',
    product_type_label: '流量包',
    sort_order: 2,
    status: 1,
    children: [],
  },
];

/** 商品列表行：V2 摘要形状，管理端列表页读的就是这些键 */
const DEMO_PRODUCTS = PRODUCTS.map((product, index) => ({
  id: product.id,
  name: product.name,
  display_name: product.display_name,
  product_display_name: product.display_name,
  product_spec_display: product.summary,
  product_type: product.product_type,
  product_type_label:
    product.product_type === 'dedicated' ? '独立服务器' : product.product_type === 'traffic_package' ? '流量包' : '云服务器',
  first_product_group_id: product.product_type === 'dedicated' ? 4102 : product.product_type === 'traffic_package' ? 4103 : 4101,
  first_product_group_name:
    product.product_type === 'dedicated' ? '独立服务器' : product.product_type === 'traffic_package' ? '增值服务' : '云服务器',
  second_product_group_id: product.product_type === 'cloud_server' ? (product.region === '新加坡' ? 4112 : 4111) : null,
  second_product_group_name: product.product_type === 'cloud_server' ? product.region : null,
  effective_product_group_full_name:
    product.product_type === 'dedicated'
      ? '独立服务器 / 日本'
      : product.product_type === 'traffic_package'
        ? '增值服务'
        : `云服务器 / ${product.region}`,
  status: 1,
  status_label: '上架',
  is_deleted: false,
  lifecycle_status: 'active',
  stock: product.stock,
  monthly_price: product.price,
  primary_price: { cycle: 'monthly', amount: product.price },
  primary_cycle: 'monthly',
  pricing: { monthly: product.price, quarterly: money(Number(product.price) * 3), yearly: money(Number(product.price) * 12) },
  services_count: SERVICES.filter((service) => service.product_name === product.name).length,
  active_services_count: SERVICES.filter(
    (service) => service.product_name === product.name && serviceStatusCode(service) === 1,
  ).length,
  console_template: 'compute',
  auto_setup: 1,
  cpu_model: product.product_type === 'dedicated' ? 'Intel Xeon E5-2650' : 'AMD EPYC 7002',
  sort_order: index,
  description: product.summary,
  upstream_binding: { provider_key: 'manual', provider_label: '手动开通', supplier_id: null, upstream_product_id: null },
  created_at: '2026-01-18 10:24:11',
  updated_at: '2026-11-02 17:30:00',
}));

/** 账单列表行：base 两张 + 两位其它客户的账单，保证筛选与分页有内容 */
const DEMO_INVOICES = [
  { ...INVOICES[0], user_id: 1001, user: userSummary(1001), order_no: 'DEMOORD202611090001', paid_amount: '0.00' },
  { ...INVOICES[1], user_id: 1001, user: userSummary(1001), order_no: '', paid_amount: '500.00' },
  {
    id: 9103,
    invoice_no: 'DEMO202610300003',
    type: 'new',
    type_label: '新购',
    status: INVOICE_STATUS_CODE.paid,
    status_label: '已支付',
    amount: '29.00',
    paid_amount: '29.00',
    due_date: '2026-10-30 00:00:00',
    paid_at: '2026-10-30 10:12:36',
    created_at: '2026-10-30 10:10:02',
    user_id: 1002,
    user: userSummary(1002),
    order_no: 'DEMOORD202610300002',
    items: [{ description: '新加坡云服务器 1 核 2G 新购 1 个月', amount: '29.00' }],
  },
  {
    id: 9104,
    invoice_no: 'DEMO202611050004',
    type: 'renew',
    type_label: '续费',
    status: INVOICE_STATUS_CODE.unpaid,
    status_label: '待支付',
    amount: '68.00',
    paid_amount: '0.00',
    due_date: '2026-11-25 00:00:00',
    paid_at: null,
    created_at: '2026-11-05 08:03:19',
    user_id: 1003,
    user: userSummary(1003),
    order_no: 'DEMOORD202611050003',
    items: [{ description: '演示香港-轻量云 续费 1 个月', amount: '68.00' }],
  },
];

/** 订单列表行：续费待付款 + 两笔已完成，金额与账单、实例对得上 */
const DEMO_ORDERS = [
  {
    id: 8101,
    order_no: 'DEMOORD202611090001',
    type: 'renew',
    type_label: '续费',
    amount: '68.00',
    quantity: 1,
    status: ORDER_STATUS_CODE.unpaid,
    status_label: '待付款',
    user_id: 1001,
    user: userSummary(1001),
    product_name: '香港轻量云 2 核 4G',
    product_full_path: '云服务器 / 香港 / 轻量云',
    service: { id: 5101, name: '演示香港-轻量云' },
    invoice: { id: 9101, invoice_no: 'DEMO202611090001', status: INVOICE_STATUS_CODE.unpaid },
    created_at: '2026-11-09 09:12:00',
  },
  {
    id: 8102,
    order_no: 'DEMOORD202610300002',
    type: 'new',
    type_label: '新购',
    amount: '29.00',
    quantity: 1,
    status: ORDER_STATUS_CODE.completed,
    status_label: '已完成',
    user_id: 1002,
    user: userSummary(1002),
    product_name: '新加坡云服务器 1 核 2G',
    product_full_path: '云服务器 / 新加坡',
    service: { id: 5103, name: '演示新加坡-测试机' },
    invoice: { id: 9103, invoice_no: 'DEMO202610300003', status: INVOICE_STATUS_CODE.paid },
    created_at: '2026-10-30 10:10:02',
  },
  {
    id: 8103,
    order_no: 'DEMOORD202610120004',
    type: 'upgrade',
    type_label: '变更',
    upgrade_kind: 'disk',
    upgrade_kind_label: '磁盘扩容',
    upgrade_target_label: '数据盘 60G → 100G',
    upgrade_mode: 'manual',
    amount: '40.00',
    quantity: 1,
    status: ORDER_STATUS_CODE.completed,
    status_label: '已完成',
    user_id: 1001,
    user: userSummary(1001),
    product_name: '香港轻量云 2 核 4G',
    product_full_path: '云服务器 / 香港 / 轻量云',
    service: { id: 5101, name: '演示香港-轻量云' },
    invoice: null,
    created_at: '2026-10-12 14:22:41',
  },
];

/** 支付流水（充值记录）：来自已支付账单，网关与三方单号都是演示值 */
const DEMO_RECHARGES = [
  {
    id: 6101,
    payment_no: 'DEMOPAY202610250001',
    invoice_no: 'DEMO202610250002',
    invoice_id: 9102,
    gateway: 'alipay',
    trade_no: 'DEMO-TRADE-20261025-0001',
    user_id: 1001,
    user: userSummary(1001),
    amount: '500.00',
    paid_amount: '500.00',
    status: INVOICE_STATUS_CODE.paid,
    created_at: '2026-10-25 21:01:52',
    paid_at: '2026-10-25 21:03:11',
  },
  {
    id: 6102,
    payment_no: 'DEMOPAY202610300002',
    invoice_no: 'DEMO202610300003',
    invoice_id: 9103,
    gateway: 'alipay',
    trade_no: 'DEMO-TRADE-20261030-0002',
    user_id: 1002,
    user: userSummary(1002),
    amount: '29.00',
    paid_amount: '29.00',
    status: INVOICE_STATUS_CODE.paid,
    created_at: '2026-10-30 10:10:30',
    paid_at: '2026-10-30 10:12:36',
  },
];

/** 实例列表行：管理端「实例管理」表格 */
const DEMO_SERVICES = SERVICES.map((service) => {
  const product = productOfService(service);
  const userId = ownerOfService(service);
  const statusCode = serviceStatusCode(service);

  return {
    id: service.id,
    service_id: service.id,
    name: service.name,
    domain: service.hostname,
    custom_hostname: service.hostname,
    product_id: product.id,
    product_display_name: product.display_name,
    product_full_path: `${product.group_name} / ${product.region}`,
    product: { id: product.id, name: product.display_name, full_path: `${product.group_name} / ${product.region}` },
    user_id: userId,
    user: userSummary(userId),
    status: statusCode,
    status_label: service.status_label,
    amount: service.monthly_price,
    billing_cycle: service.billing_cycle,
    billing_cycle_label: BILLING_CYCLE_LABEL[service.billing_cycle] || '月付',
    expires_at: service.expires_at,
    created_at: '2026-01-18 10:30:00',
    host_ips: [service.ip],
    upstream_host_id: 9000 + service.id,
    upstream_host_id_text: String(9000 + service.id),
    hostname: service.hostname,
    host_username: 'root',
  };
});

/** 服务详情（用户维度）：控制台页读的是 `{service: {...}}`，连接信息单独一个接口 */
function serviceDetailOf(userId, serviceId) {
  const service = serviceOf(Number(serviceId));
  const product = productOfService(service);
  const statusCode = serviceStatusCode(service);

  return {
    id: service.id,
    name: service.name,
    custom_service_name: service.name,
    domain: service.hostname,
    status: statusCode,
    status_label: service.status_label,
    billing_cycle: service.billing_cycle,
    billing_cycle_label: BILLING_CYCLE_LABEL[service.billing_cycle] || '月付',
    amount: service.monthly_price,
    expires_at: service.expires_at,
    created_at: '2026-01-18 10:30:00',
    console_template: 'compute',
    console_mode: 'default',
    product: {
      id: product.id,
      name: product.display_name,
      display_name: product.display_name,
      full_path: `${product.group_name} / ${product.region}`,
      type: product.product_type,
      type_label: product.product_type_label || '云服务器',
    },
    product_display_name: product.display_name,
    product_full_path: `${product.group_name} / ${product.region}`,
    user: userSummary(userId),
    user_id: userId,
    upstream: { provider_key: 'manual', provider_label: '手动开通', host_id: 9000 + service.id, status: 'active' },
    runtime: { power_state: statusCode === 1 ? 'running' : 'stopped', power_state_label: statusCode === 1 ? '运行中' : '已关机' },
    specs: [
      { label: 'CPU', value: `${service.cpu} 核` },
      { label: '内存', value: `${Math.round(service.memory / 1024)} GB` },
      { label: '磁盘', value: `${service.disk} GB` },
      { label: '带宽', value: `${service.bandwidth} Mbps` },
      { label: '操作系统', value: service.os },
    ],
    actions: {
      refresh: true,
      power: true,
      module_status: true,
      password_reset: statusCode === 1,
      reinstall: statusCode === 1,
      rescue: statusCode === 1,
      traffic_package: true,
      available: ['power:on', 'power:off', 'power:reboot'],
    },
    connection: {
      hostname: service.hostname,
      username: 'root',
      has_password: true,
      port: 22,
      dedicated_ip: service.ip,
      internal_ip: '',
      assigned_ips: [service.ip],
    },
  };
}

/** 工单列表行：base 两条 + 一条已关闭，状态码与 base 的状态文案一致 */
const DEMO_TICKETS = [
  ...TICKETS.map((ticket, index) => {
    const service = SERVICES[index] || SERVICES[0];

    return {
      id: ticket.id,
      subject: ticket.title,
      status: ticketStatusCode(ticket),
      status_label: ticket.status_label,
      priority: ticket.priority === 'low' ? 1 : 2,
      priority_label: ticket.priority === 'low' ? '低' : '普通',
      department: ticket.department,
      content: `${ticket.title}（演示数据：这条工单只用于展示列表与详情排版，不对应真实业务。）`,
      user_id: 1001,
      user: userSummary(1001),
      service_id: service.id,
      service: { id: service.id, name: service.name, display_name: service.name },
      assignee: { id: 1, nickname: '演示管理员' },
      assignee_id: 1,
      replies: ticket.replies,
      created_at: ticket.created_at,
      updated_at: ticket.updated_at,
    };
  }),
  {
    id: 7103,
    subject: '演示工单：续费账单金额疑问',
    status: TICKET_STATUS_CODE.closed,
    status_label: '已关闭',
    priority: 1,
    priority_label: '低',
    department: '财务',
    content: '演示数据：客户询问续费账单金额，客服已解释并关闭工单。',
    user_id: 1002,
    user: userSummary(1002),
    service_id: 5103,
    service: { id: 5103, name: '演示新加坡-测试机', display_name: '演示新加坡-测试机' },
    assignee: { id: 1, nickname: '演示管理员' },
    assignee_id: 1,
    close_reason: 'resolved',
    close_reason_label: '已解决',
    replies: 1,
    created_at: '2026-10-31 11:40:00',
    updated_at: '2026-11-01 09:05:00',
  },
];

/** 工单回复：详情页依赖 `/replies` 列表，两条演示回复够把对话样式撑起来 */
const DEMO_TICKET_REPLIES = {
  7101: [
    {
      id: 7201,
      ticket_id: 7101,
      user_id: 1001,
      content: '我想把实例带宽从 30Mbps 调到 50Mbps，需要换套餐吗？',
      is_staff: 0,
      sender_type: 'user',
      sender_name: '演示客户',
      attachments: [],
      created_at: '2026-11-08 14:20:00',
    },
  ],
  7102: [
    {
      id: 7202,
      ticket_id: 7102,
      user_id: 1001,
      content: '发票抬头需要改成公司全称，麻烦帮忙处理。',
      is_staff: 0,
      sender_type: 'user',
      sender_name: '演示客户',
      attachments: [],
      created_at: '2026-11-03 10:02:00',
    },
    {
      id: 7203,
      ticket_id: 7102,
      user_id: 1,
      content: '已按您提供的抬头重新开具，请到「账单管理」下载。',
      is_staff: 1,
      sender_type: 'staff',
      sender_name: '演示管理员',
      attachments: [],
      created_at: '2026-11-04 09:31:00',
    },
  ],
  7103: [
    {
      id: 7204,
      ticket_id: 7103,
      user_id: 1,
      content: '续费金额按当前配置计算，已为您说明并关闭工单。',
      is_staff: 1,
      sender_type: 'staff',
      sender_name: '演示管理员',
      attachments: [],
      created_at: '2026-11-01 09:05:00',
    },
  ],
};

/** 站点内容：公告 / 帮助 / 单页，与官网内容栏目同构 */
const DEMO_CONTENT_CATEGORIES = [
  { id: 1401, name: '官方公告', slug: 'notice', type: 'notice', status: 1, sort_order: 0, articles_count: 2 },
  { id: 1402, name: '帮助中心', slug: 'help', type: 'help', status: 1, sort_order: 1, articles_count: 1 },
  { id: 1403, name: '单页', slug: 'page', type: 'page', status: 1, sort_order: 2, articles_count: 0 },
];

const DEMO_ARTICLES = [
  {
    id: 1501,
    category_id: 1401,
    category: { id: 1401, name: '官方公告', slug: 'notice' },
    title: '演示公告：控制台新增实例改配入口',
    slug: 'demo-notice-host-config',
    type: 'notice',
    status: 1,
    status_label: '已发布',
    summary: '管理端服务详情新增「改配」入口，运营可手工调整 CPU、内存、带宽与磁盘。',
    is_pinned: 1,
    sort_order: 0,
    published_at: '2026-11-09 10:00:00',
    created_at: '2026-11-09 09:40:00',
    updated_at: '2026-11-09 10:00:00',
  },
  {
    id: 1502,
    category_id: 1401,
    category: { id: 1401, name: '官方公告', slug: 'notice' },
    title: '演示公告：双十一续费优惠说明',
    slug: 'demo-notice-renewal',
    type: 'notice',
    status: 1,
    status_label: '已发布',
    summary: '活动期间续费满 200 减 20，优惠券可在「营销推广 - 优惠券」中查看。',
    is_pinned: 0,
    sort_order: 1,
    published_at: '2026-11-01 09:00:00',
    created_at: '2026-11-01 08:30:00',
    updated_at: '2026-11-01 09:00:00',
  },
  {
    id: 1503,
    category_id: 1402,
    category: { id: 1402, name: '帮助中心', slug: 'help' },
    title: '演示帮助：如何重置实例密码',
    slug: 'demo-help-reset-password',
    type: 'help',
    status: 1,
    status_label: '已发布',
    summary: '在控制台实例详情页点击「重置密码」，按提示输入新密码即可。',
    is_pinned: 0,
    sort_order: 0,
    published_at: '2026-10-20 15:00:00',
    created_at: '2026-10-20 14:20:00',
    updated_at: '2026-10-20 15:00:00',
  },
];

/** 会员等级：与客户身上的 member_level 对得上 */
const MEMBER_LEVELS = [
  {
    id: 1,
    name: '普通会员',
    code: 'basic',
    sales_amount_min: '0.00',
    sales_amount_max: '999.99',
    reward_rate: '0.00',
    status: 1,
    sort_order: 0,
    remark: '默认等级',
    created_at: '2026-01-18 10:24:11',
    updated_at: '2026-01-18 10:24:11',
  },
  {
    id: 2,
    name: '黄金会员',
    code: 'gold',
    sales_amount_min: '1000.00',
    sales_amount_max: '9999.99',
    reward_rate: '2.00',
    status: 1,
    sort_order: 1,
    remark: '累计消费满 1000 元',
    created_at: '2026-01-18 10:24:11',
    updated_at: '2026-01-18 10:24:11',
  },
  {
    id: 3,
    name: '钻石会员',
    code: 'diamond',
    sales_amount_min: '10000.00',
    sales_amount_max: null,
    reward_rate: '5.00',
    status: 1,
    sort_order: 2,
    remark: '累计消费满 10000 元',
    created_at: '2026-01-18 10:24:11',
    updated_at: '2026-01-18 10:24:11',
  },
];

/** 优惠券：一张满减、一张折扣，金额口径与订单一致 */
const DEMO_COUPONS = [
  {
    id: 2101,
    code: 'DEMO20',
    name: '满 200 减 20',
    type: 'fixed',
    type_label: '满减',
    value: '20.00',
    min_amount: '200.00',
    total: 200,
    used: 12,
    received: 48,
    enabled: true,
    status: 1,
    status_label: '启用',
    start_at: '2026-11-01 00:00:00',
    end_at: '2026-11-30 23:59:59',
    created_at: '2026-10-28 11:00:00',
    updated_at: '2026-10-28 11:00:00',
  },
  {
    id: 2102,
    code: 'DEMO9',
    name: '新购 9 折',
    type: 'discount',
    type_label: '折扣',
    value: '90',
    min_amount: '0.00',
    total: 100,
    used: 3,
    received: 20,
    enabled: false,
    status: 0,
    status_label: '停用',
    start_at: '2026-09-01 00:00:00',
    end_at: '2026-09-30 23:59:59',
    created_at: '2026-08-25 10:10:10',
    updated_at: '2026-09-30 23:59:59',
  },
];

const DEMO_COUPON_CAMPAIGNS = [
  {
    id: 2201,
    name: '双十一续费回馈',
    type: 'auto',
    type_label: '自动发放',
    enabled: true,
    status: 1,
    status_label: '运行中',
    coupon_id: 2101,
    coupon_name: '满 200 减 20',
    sent_count: 18,
    used_count: 6,
    start_at: '2026-11-01 00:00:00',
    end_at: '2026-11-30 23:59:59',
    created_at: '2026-10-28 11:20:00',
    updated_at: '2026-11-09 09:00:00',
  },
];

const AGENT_GROUPS = [
  { id: 1, name: '金牌代理', code: 'gold', status: 1, default_discount_rate: '95.00', sort_order: 0, remark: '一级代理', created_at: '2026-01-18 10:24:11', updated_at: '2026-01-18 10:24:11' },
  { id: 2, name: '银牌代理', code: 'silver', status: 1, default_discount_rate: '98.00', sort_order: 1, remark: '二级代理', created_at: '2026-01-18 10:24:11', updated_at: '2026-01-18 10:24:11' },
];

const PRODUCT_DISCOUNT_GROUPS = [
  { id: 11, name: '云服务器', code: 'cloud', min_discount_rate: '90.00', cost_rate: '80.00', status: 1, sort_order: 0, remark: '' },
  { id: 12, name: '独立服务器', code: 'dedicated', min_discount_rate: '95.00', cost_rate: '88.00', status: 1, sort_order: 1, remark: '' },
];

const AGENT_GROUP_DISCOUNTS = [  {
    id: 11,
    name: '云服务器',
    code: 'cloud',
    min_discount_rate: '90.00',
    discounts: [
      { agent_group_id: 1, discount_rate: '95.00' },
      { agent_group_id: 2, discount_rate: null },
    ],
  },
  {
    id: 12,
    name: '独立服务器',
    code: 'dedicated',
    min_discount_rate: '95.00',
    discounts: [
      { agent_group_id: 1, discount_rate: '97.00' },
      { agent_group_id: 2, discount_rate: '99.00' },
    ],
  },
];

/**
 * 流量包目录：商品配置 → 流量包页面读的是设置项 `traffic_package_catalog`，
 * 设置接口按 group 返回 `items` / `groups` 两行（值都是 JSON 字符串）。
 */
const TRAFFIC_PACKAGE_GROUPS = [
  {
    id: 'tg-cloud',
    name: '云服务器',
    product_type: 'cloud_server',
    effective_product_group_id: 4101,
    first_product_group_id: 4101,
    first_product_group_name: '云服务器',
    product_group_label: '云服务器',
    markup_type: 'none',
    markup_value: 0,
    product_ids: [3201, 3203],
  },
];

const TRAFFIC_PACKAGE_ITEMS = [
  {
    traffic_group_id: 'tg-cloud',
    group_name: '云服务器',
    effective_product_group_id: 4101,
    first_product_group_id: 4101,
    product_group_label: '云服务器',
    product_type: 'cloud_server',
    label: '流量包 100GB',
    target_value: 100,
    cost_price: 12,
    markup_type: 'fixed',
    markup_value: 3,
    price: 15,
    sort_order: 0,
    product_ids: [3201],
  },
  {
    traffic_group_id: 'tg-cloud',
    group_name: '云服务器',
    effective_product_group_id: 4101,
    first_product_group_id: 4101,
    product_group_label: '云服务器',
    product_type: 'cloud_server',
    label: '流量包 500GB',
    target_value: 500,
    cost_price: 55,
    markup_type: 'fixed',
    markup_value: 10,
    price: 65,
    sort_order: 1,
    product_ids: [3201, 3203],
  },
];

export function adminFixtures() {
  return {
    endpoints: {
      'GET /v2/site/config': SITE_CONFIG,

      // 登录：演示环境任意账号密码都能进
      'POST /v2/admin/login': {
        token: 'demo-admin-token',
        ...ADMIN_SESSION,
      },

      'GET /v2/admin/auth/info': ADMIN_SESSION,

      'GET /v2/admin/dashboard/stats': {
        today: { income: 1288.5, new_invoices: 6, new_users: 3 },
        month: { income: 42880.36, new_invoices: 128, new_users: 41 },
        counts: { active_services: 286, total_invoices: 1420, open_tickets: 4, total_users: 512 },
      },

      'GET /v2/admin/dashboard/monthly-revenue': {
        month_label: '2026-11',
        revenue_by_product: [
          { label: '香港轻量云', income: 18240.5 },
          { label: '日本独立服务器', income: 13420 },
          { label: '新加坡云服务器', income: 6320.86 },
          { label: '流量包', income: 4899 },
        ],
        daily_trend: [
          { date: '2026-11-03', income: 980 },
          { date: '2026-11-04', income: 1460 },
          { date: '2026-11-05', income: 1120 },
          { date: '2026-11-06', income: 2380 },
          { date: '2026-11-07', income: 1890 },
          { date: '2026-11-08', income: 2640 },
          { date: '2026-11-09', income: 1288.5 },
        ],
      },

      'GET /v2/admin/dashboard/recent-invoices': {
        recent_invoices: INVOICES.map((invoice) => ({
          id: invoice.id,
          invoice_no: invoice.invoice_no,
          amount: invoice.amount,
          status: invoice.status,
          created_at: invoice.created_at,
        })),
      },

      // ─── 商品配置 ─────────────────────────────────────────────
      // 字面路径必须排在 `products/:id` 之前，否则会被通配吃掉
      'GET /v2/admin/products/summary': {
        total: DEMO_PRODUCTS.length,
        active: DEMO_PRODUCTS.length,
        inactive: 0,
        deleted: 0,
        groups_total: PRODUCT_GROUPS.length,
        products_total: DEMO_PRODUCTS.length,
        by_type: { cloud_server: 2, dedicated: 1, traffic_package: 1 },
      },

      'GET /v2/admin/products': {
        list: DEMO_PRODUCTS,
        total: DEMO_PRODUCTS.length,
        page: 1,
        page_size: 20,
      },

      'GET /v2/admin/products/:id': (() => {
        const product = DEMO_PRODUCTS[0];

        return {
          product: {
            basic: {
              id: product.id,
              name: product.name,
              product_type: product.product_type,
              product_type_label: product.product_type_label,
              status: product.status,
              sort_order: product.sort_order,
            },
            display: {
              display_name: product.display_name,
              product_display_name: product.product_display_name,
              description: product.description,
              console_template: product.console_template,
            },
            pricing: { ...product.pricing, primary_cycle: 'monthly' },
            configuration: { auto_setup: 1, stock: product.stock, cpu_model: product.cpu_model },
            upstream_binding: product.upstream_binding,
            groups: {
              first_product_group_id: product.first_product_group_id,
              first_product_group_name: product.first_product_group_name,
              second_product_group_id: product.second_product_group_id,
              second_product_group_name: product.second_product_group_name,
            },
            timestamps: { created_at: product.created_at, updated_at: product.updated_at },
          },
        };
      })(),

      'GET /v2/admin/product-groups/tree': {
        tree: PRODUCT_GROUPS,
        list: PRODUCT_GROUPS,
        total: PRODUCT_GROUPS.length,
      },

      'GET /v2/admin/product-groups': {
        list: PRODUCT_GROUPS,
        total: PRODUCT_GROUPS.length,
        page: 1,
        page_size: 20,
      },

      'GET /v2/admin/product-types': [
        { value: 'cloud_server', label: '云服务器', slug: 'cloud-server', product_type: 'cloud_server', product_type_label: '云服务器', usage_count: 2, sort_order: 0 },
        { value: 'dedicated', label: '独立服务器', slug: 'dedicated', product_type: 'dedicated', product_type_label: '独立服务器', usage_count: 1, sort_order: 1 },
        { value: 'traffic_package', label: '流量包', slug: 'traffic-package', product_type: 'traffic_package', product_type_label: '流量包', usage_count: 1, sort_order: 2 },
      ],

      'GET /v2/admin/instance-spec-catalog': {
        list: [
          { id: 1, name: '2 核 4G', cpu: 2, memory: 4096, disk: 60, bandwidth: 30, code: 'c2m4', status: 1, sort_order: 0 },
          { id: 2, name: '4 核 8G', cpu: 4, memory: 8192, disk: 100, bandwidth: 50, code: 'c4m8', status: 1, sort_order: 1 },
          { id: 3, name: '16 核 32G', cpu: 16, memory: 32768, disk: 960, bandwidth: 100, code: 'c16m32', status: 1, sort_order: 2 },
        ],
      },

      'GET /v2/admin/cpu-model-catalog': {
        list: [
          { id: 1, name: 'AMD EPYC 7002', sort_order: 0, groups: ['云服务器'], status: 1 },
          { id: 2, name: 'Intel Xeon E5-2650', sort_order: 1, groups: ['独立服务器'], status: 1 },
        ],
      },

      // 上游供应
      'GET /v2/admin/suppliers/summary': { total: 1, active: 1, inactive: 0 },
      'GET /v2/admin/suppliers/provider-types': [
        { key: 'manual', label: '手动开通' },
        { key: 'zjmf', label: '智简魔方' },
      ],
      'GET /v2/admin/suppliers': {
        list: [
          {
            id: 1,
            name: '演示供应商（手动开通）',
            provider_key: 'manual',
            provider_label: '手动开通',
            status: 1,
            status_label: '启用',
            remote_balance: '0.00',
            remote_balance_status: 'unsupported',
            low_balance_threshold: 20,
            low_balance_alert_enabled: false,
            upstream_binding: null,
            created_at: '2026-01-18 10:24:11',
            updated_at: '2026-01-18 10:24:11',
          },
        ],
        total: 1,
        page: 1,
        page_size: 20,
      },

      // ─── 用户管理 ─────────────────────────────────────────────
      'GET /v2/admin/users': {
        list: DEMO_USERS,
        total: DEMO_USERS.length,
        page: 1,
        page_size: 20,
      },

      'GET /v2/admin/users/:id': (() => {
        const user = DEMO_USERS[0];

        return {
          user,
          stats: {
            service_count: SERVICES.length,
            active_service_count: SERVICES.length,
            invoice_count: DEMO_INVOICES.filter((invoice) => invoice.user_id === user.id).length,
            unpaid_invoice_count: 1,
            order_count: DEMO_ORDERS.filter((order) => order.user_id === user.id).length,
            ticket_count: DEMO_TICKETS.filter((ticket) => ticket.user_id === user.id).length,
            total_paid: '1596.00',
            cash_balance: user.balance,
          },
          referral: {
            invited_count: 2,
            reward_total: '66.00',
            pending_withdrawal: '0.00',
            withdrawn: '0.00',
            code: 'DEMO1001',
          },
        };
      })(),

      'GET /v2/admin/users/:id/services': {
        list: DEMO_SERVICES.filter((service) => service.user_id === 1001),
        total: DEMO_SERVICES.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/users/:id/orders': {
        list: DEMO_ORDERS.filter((order) => order.user_id === 1001),
        total: 2,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/users/:id/invoices': {
        list: DEMO_INVOICES.filter((invoice) => invoice.user_id === 1001),
        total: 2,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/users/:id/recharges': {
        list: DEMO_RECHARGES.filter((recharge) => recharge.user_id === 1001),
        total: 1,
        page: 1,
        page_size: 20,
      },

      'GET /v2/admin/verifications/summary': {
        stats: { total: 2, pending: 1, verified: 1, rejected: 0 },
        config: { enabled: true, require_phone: true, methods: ['manual'] },
      },
      'GET /v2/admin/verifications': {
        list: [
          {
            id: 1,
            display_name: '演示客户',
            email: 'demo@example.com',
            phone: '13800000000',
            real_name: '演示客户',
            id_card_masked: 'DEMO**********0000',
            verification_status: 1,
            verification_message: '实名信息与证件一致',
            verification_method_label: '人工审核',
            verification_type_label: '个人实名',
            document_type_label: '身份证',
            identity_region_label: '中国大陆',
            created_at: '2026-02-11 09:20:00',
            submitted_at: '2026-02-11 09:20:00',
            verified_at: '2026-02-11 15:02:00',
            updated_at: '2026-02-11 15:02:00',
          },
          {
            id: 2,
            display_name: '演示客户二',
            email: 'demo2@example.com',
            phone: '13800000002',
            real_name: '演示客户二',
            id_card_masked: 'DEMO**********0002',
            verification_status: 0,
            verification_message: '待人工审核',
            verification_method_label: '人工审核',
            verification_type_label: '企业实名',
            document_type_label: '营业执照',
            identity_region_label: '中国大陆',
            created_at: '2026-11-08 16:40:00',
            submitted_at: '2026-11-08 16:40:00',
            verified_at: null,
            updated_at: '2026-11-08 16:40:00',
          },
        ],
        total: 2,
        page: 1,
        page_size: 20,
      },

      'GET /v2/admin/member-levels': { list: MEMBER_LEVELS, total: MEMBER_LEVELS.length },

      // ─── 实例管理 ─────────────────────────────────────────────
      'GET /v2/admin/services': {
        list: DEMO_SERVICES,
        total: DEMO_SERVICES.length,
        page: 1,
        page_size: 20,
      },

      'GET /v2/admin/users/:id/services/:serviceId': {
        service: serviceDetailOf(1001, 5101),
      },
      'GET /v2/admin/users/:id/services/:serviceId/connection': {
        connection: serviceDetailOf(1001, 5101).connection,
      },
      'GET /v2/admin/users/:id/services/:serviceId/remote-status': {},
      // 控制台形态判定读 config；续费弹窗读 renewals 的 preview.cycles
      'GET /v2/admin/users/:id/services/:serviceId/config': {
        console_template: 'compute',
        machine_category: 'cloud',
        product_type: 'cloud_server',
        service_type_code: 'cloud_server',
        module: 'default',
      },
      'GET /v2/admin/users/:id/services/:serviceId/renewals': {
        preview: {
          cycles: [
            { billing_cycle: 'monthly', billing_cycle_label: '月付', amount: '68.00' },
            { billing_cycle: 'quarterly', billing_cycle_label: '季付', amount: '204.00' },
            { billing_cycle: 'yearly', billing_cycle_label: '年付', amount: '816.00' },
          ],
        },
      },
      'GET /v2/admin/users/:id/services/:serviceId/module-status': {
        module_status: 'active',
        module_status_label: '正常',
      },
      'GET /v2/admin/users/:id/services/:serviceId/console/capabilities': {
        supported: false,
        fetchable: false,
        error: '',
        areas: [],
        nat_supported: false,
        monitor_supported: false,
      },
      'GET /v2/admin/users/:id/services/:serviceId/console/tickets': { ticket: 'demo-console-ticket' },
      'GET /v2/admin/users/:id/services/:serviceId/operation-logs': {
        list: [
          { id: 1, action: 'service.console.power.on', summary: '开机', operator: '演示管理员', created_at: '2026-11-08 09:12:00' },
          { id: 2, action: 'service.console.power.reboot', summary: '重启', operator: '演示管理员', created_at: '2026-11-06 20:31:00' },
        ],
        total: 2,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/os-options': {
        groups: [
          { label: 'Debian', options: [{ value: 'debian-12', label: 'Debian 12' }] },
          { label: 'Ubuntu', options: [{ value: 'ubuntu-2404', label: 'Ubuntu 24.04' }] },
          { label: 'AlmaLinux', options: [{ value: 'almalinux-9', label: 'AlmaLinux 9' }] },
        ],
      },

      // ─── 工单处理 ─────────────────────────────────────────────
      'GET /v2/admin/tickets/summary': { open: 1, staff_reply: 1, user_reply: 0, closed: 1, total: DEMO_TICKETS.length },
      'GET /v2/admin/tickets/admin-users': {
        list: [{ id: 1, username: 'admin', nickname: '演示管理员', email: 'admin@example.com' }],
      },
      'GET /v2/admin/tickets': {
        list: DEMO_TICKETS,
        total: DEMO_TICKETS.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/tickets/:id': (() => {
        const ticket = DEMO_TICKETS[0];

        return {
          ticket: {
            ...ticket,
            upstream_delivery: {
              configured: false,
              status: 'not_configured',
              status_label: '未配置',
              provider_key: null,
              supplier_id: null,
              attempts: 0,
              last_attempt_at: null,
              delivered_at: null,
              last_error: null,
              last_event: null,
            },
          },
        };
      })(),
      'GET /v2/admin/tickets/:id/replies': (() => {
        const replies = DEMO_TICKET_REPLIES[7101];

        return { list: replies, total: replies.length, page: 1, page_size: 100 };
      })(),
      'GET /v2/admin/tickets/:id/upstream-delivery/logs': { list: [], total: 0, page: 1, page_size: 20 },

      'GET /v2/admin/ticket-delivery-rules': {
        list: [
          {
            id: 1,
            name: '技术支持工单转上游',
            enabled: false,
            department: '技术支持',
            upstream_department_id: '',
            keywords: ['无法连接', '重装系统'],
            match_mode: 'any',
            created_at: '2026-09-01 10:00:00',
            updated_at: '2026-09-01 10:00:00',
          },
        ],
        total: 1,
      },
      'GET /v2/admin/ticket-delivery-departments': {
        list: [{ id: 1, name: '技术支持', label: '技术支持' }],
        total: 1,
      },
      'GET /v2/admin/ticket-pre-reply-settings': {
        enabled: false,
        content: '',
        delay_minutes: 10,
        updated_at: '2026-09-01 10:00:00',
      },
      'GET /v2/admin/ticket-delivery-upload-guard': {
        enabled: true,
        max_size_mb: 10,
        allowed_extensions: ['png', 'jpg', 'pdf'],
      },

      // ─── 财务管理 ─────────────────────────────────────────────
      'GET /v2/admin/invoices': {
        list: DEMO_INVOICES,
        total: DEMO_INVOICES.length,
        page: 1,
        page_size: 20,
      },
      // 详情接口是 V2 嵌套形状（basic/display/financial/...），由 finance.ts 归一化
      'GET /v2/admin/invoices/:id': (() => {
        const invoice = DEMO_INVOICES[0];

        return {
          invoice: {
            id: invoice.id,
            user: invoice.user,
            basic: {
              invoice_no: invoice.invoice_no,
              type: invoice.type,
              type_label: invoice.type_label,
              status: invoice.status,
              status_label: invoice.status_label,
              raw_status: invoice.status_label,
              raw_status_label: invoice.status_label,
              billing_cycle: 'monthly',
              quantity: 1,
              due_date: invoice.due_date,
            },
            display: {
              product_spec_snapshot: '',
              product_spec_display: '2 核 4G / 60G / 30Mbps',
              product_display_name: '香港轻量云 2 核 4G',
              combined_display_name: '香港轻量云 2 核 4G',
              summary: { highlight: '演示香港-轻量云 续费 1 个月' },
            },
            financial: {
              amount: invoice.amount,
              discount: '0.00',
              paid_amount: invoice.paid_amount,
              payable_amount: invoice.paid_amount,
              paid_at: invoice.paid_at,
            },
            order: { id: 8101, order_no: invoice.order_no },
            product: { id: 3201, name: '香港轻量云 2 核 4G' },
            service: { id: 5101, name: '演示香港-轻量云' },
            configuration: { config_snapshot: {}, config_pricing_snapshot: {}, coupon_snapshot: null },
            payment_chain: {
              payment_summary: { gateway: 'alipay', gateway_label: '支付宝', paid_at: invoice.paid_at },
              payments: [],
            },
            audit: { trace_id: 'demo-trace-invoice-9101', refund_trace_id: null },
            actions: { can_cancel: invoice.status === INVOICE_STATUS_CODE.unpaid },
            timestamps: { created_at: invoice.created_at, updated_at: invoice.created_at },
            items: invoice.items || [],
            logs: [
              { id: 1, action: 'invoice.created', description: '账单已创建', created_at: invoice.created_at },
            ],
          },
          payments: [],
        };
      })(),

      'GET /v2/admin/orders': {
        list: DEMO_ORDERS,
        total: DEMO_ORDERS.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/orders/:id': (() => {
        const order = DEMO_ORDERS[0];

        return {
          order: {
            id: order.id,
            user: order.user,
            basic: {
              order_no: order.order_no,
              type: order.type,
              type_label: order.type_label,
              status: order.status,
              status_label: order.status_label,
              billing_cycle: 'monthly',
              quantity: order.quantity,
              remark: '',
            },
            financial: { amount: order.amount, discount: '0.00', paid_amount: order.status === 3 ? order.amount : '0.00', paid_at: null },
            invoice: order.invoice,
            product: { id: 3201, name: order.product_name, full_path: order.product_full_path },
            service: order.service,
            coupon: null,
            configuration: { config_snapshot: {}, config_pricing_snapshot: {}, service_snapshot: order.service },
            payment_chain: { payments: [] },
            audit: { trace_id: 'demo-trace-order-8101' },
            timestamps: { created_at: order.created_at, updated_at: order.created_at },
          },
        };
      })(),

      'GET /v2/admin/finance/recharges': {
        list: DEMO_RECHARGES,
        total: DEMO_RECHARGES.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/finance/renewal-orders': {
        list: DEMO_ORDERS.filter((order) => order.type === 'renew'),
        total: 1,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/finance/upgrade-orders': {
        list: DEMO_ORDERS.filter((order) => order.type === 'upgrade'),
        total: 1,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/finance/new-customer-daily-summary': {
        summary: {
          new_customers: 6,
          new_orders: 9,
          completed_orders: 7,
          new_tickets: 3,
          ticket_replies: 5,
          cancel_requests: 0,
        },
        list: [
          { date: '2026-11-03', new_customers: 1, new_orders: 2, completed_orders: 2, new_tickets: 1, ticket_replies: 1, cancel_requests: 0 },
          { date: '2026-11-04', new_customers: 2, new_orders: 3, completed_orders: 2, new_tickets: 0, ticket_replies: 2, cancel_requests: 0 },
          { date: '2026-11-05', new_customers: 0, new_orders: 1, completed_orders: 1, new_tickets: 1, ticket_replies: 1, cancel_requests: 0 },
          { date: '2026-11-06', new_customers: 1, new_orders: 1, completed_orders: 1, new_tickets: 0, ticket_replies: 0, cancel_requests: 0 },
          { date: '2026-11-07', new_customers: 1, new_orders: 1, completed_orders: 1, new_tickets: 1, ticket_replies: 1, cancel_requests: 0 },
          { date: '2026-11-08', new_customers: 1, new_orders: 1, completed_orders: 0, new_tickets: 0, ticket_replies: 0, cancel_requests: 0 },
          { date: '2026-11-09', new_customers: 0, new_orders: 0, completed_orders: 0, new_tickets: 0, ticket_replies: 0, cancel_requests: 0 },
        ],
      },

      // ─── 营销推广 ─────────────────────────────────────────────
      'GET /v2/admin/coupons/summary': { total: DEMO_COUPONS.length, enabled: 1, disabled: 1, used: 15 },
      'GET /v2/admin/coupons': {
        list: DEMO_COUPONS,
        total: DEMO_COUPONS.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/coupon-campaigns/summary': { total: 1, running: 1, finished: 0 },
      'GET /v2/admin/coupon-campaigns/tasks': { list: [], total: 0 },
      'GET /v2/admin/coupon-campaigns': {
        list: DEMO_COUPON_CAMPAIGNS,
        total: DEMO_COUPON_CAMPAIGNS.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/coupon-product-groups/tree': { tree: PRODUCT_GROUPS, list: PRODUCT_GROUPS },
      'GET /v2/admin/coupon-product-groups': {
        list: PRODUCT_GROUPS,
        total: PRODUCT_GROUPS.length,
        page: 1,
        page_size: 20,
      },

      'GET /v2/admin/agent-groups': { list: AGENT_GROUPS },
      'GET /v2/admin/product-discount-groups': { list: PRODUCT_DISCOUNT_GROUPS },
      'GET /v2/admin/agent-group-discounts': { rows: AGENT_GROUP_DISCOUNTS },

      'GET /v2/admin/referral/overview': {
        invited_users: 3,
        registered_users: 2,
        rewarded_orders: 4,
        reward_total: '86.00',
        pending_withdrawal: '50.00',
        withdrawn: '50.00',
        rate: '5.00',
      },
      'GET /v2/admin/referral/rewards': {
        list: [
          {
            id: 3101,
            user_id: 1001,
            user: userSummary(1001),
            invited_user_id: 1002,
            invited_user: userSummary(1002),
            order_no: 'DEMOORD202610300002',
            amount: '6.45',
            rate: '5.00',
            status: 1,
            status_label: '已发放',
            created_at: '2026-11-02 10:12:36',
          },
          {
            id: 3102,
            user_id: 1001,
            user: userSummary(1001),
            invited_user_id: 1003,
            invited_user: userSummary(1003),
            order_no: 'DEMOORD202610120004',
            amount: '2.00',
            rate: '5.00',
            status: 1,
            status_label: '已发放',
            created_at: '2026-11-06 14:22:41',
          },
        ],
        total: 2,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/referral-withdrawals': {
        list: [
          {
            id: 3201,
            user_id: 1002,
            user: userSummary(1002),
            amount: '50.00',
            status: 1,
            status_label: '已打款',
            method: 'alipay',
            method_label: '支付宝',
            account: 'demo2@example.com',
            created_at: '2026-11-05 11:00:00',
            processed_at: '2026-11-06 09:30:00',
          },
        ],
        total: 1,
        page: 1,
        page_size: 20,
      },

      // ─── 站点内容 ─────────────────────────────────────────────
      'GET /v2/admin/content/summary': {
        articles: DEMO_ARTICLES.length,
        published: DEMO_ARTICLES.length,
        categories: DEMO_CONTENT_CATEGORIES.length,
        media_files: 2,
      },
      'GET /v2/admin/content/categories': {
        list: DEMO_CONTENT_CATEGORIES,
        total: DEMO_CONTENT_CATEGORIES.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/content/articles': {
        list: DEMO_ARTICLES,
        total: DEMO_ARTICLES.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/content/articles/:id': {
        article: {
          ...DEMO_ARTICLES[0],
          content: '<p>演示公告正文：这里展示的是富文本渲染效果，内容为示例数据。</p>',
        },
      },
      'GET /v2/admin/media-files': {
        list: [
          { id: 1, name: 'demo-banner.png', url: '/demo/media/demo-banner.png', mime: 'image/png', size: 48210, created_at: '2026-10-01 10:00:00' },
          { id: 2, name: 'demo-logo.svg', url: '/demo/media/demo-logo.svg', mime: 'image/svg+xml', size: 3120, created_at: '2026-10-01 10:02:00' },
        ],
        total: 2,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/site/home-hero': {
        enabled: true,
        title: 'TuraIDC 演示站',
        subtitle: '稳定、安全、高性价比的云服务器与 IDC 服务平台',
        video_url: '',
        image_url: '',
        updated_at: '2026-10-01 10:00:00',
      },

      // ─── 系统设置 ─────────────────────────────────────────────
      'GET /v2/admin/settings': {
        list: [
          { key: 'site_name', value: 'TuraIDC 演示站', is_secret: false, has_value: true, masked_value: 'TuraIDC 演示站' },
          { key: 'site_url', value: 'https://demo.example.com', is_secret: false, has_value: true, masked_value: 'https://demo.example.com' },
          { key: 'register_enabled', value: 'true', is_secret: false, has_value: true, masked_value: 'true' },
          { key: 'captcha_enabled', value: 'false', is_secret: false, has_value: true, masked_value: 'false' },
          { key: 'mail_host', value: 'smtp.example.com', is_secret: false, has_value: true, masked_value: 'smtp.example.com' },
          { key: 'mail_username', value: 'noreply@example.com', is_secret: false, has_value: true, masked_value: 'noreply@example.com' },
          { key: 'mail_password', value: '', is_secret: true, has_value: true, masked_value: '••••••••' },
          { key: 'alipay_app_id', value: 'demo-app-id', is_secret: false, has_value: true, masked_value: 'demo-app-id' },
          { key: 'alipay_private_key', value: '', is_secret: true, has_value: true, masked_value: '••••••••' },
          { key: 'ticket_auto_close_hours', value: '72', is_secret: false, has_value: true, masked_value: '72' },
          // 商品配置 → 流量包：页面按 group=traffic_package_catalog 取 items / groups
          { key: 'items', value: JSON.stringify(TRAFFIC_PACKAGE_ITEMS), is_secret: false, has_value: true, masked_value: '' },
          { key: 'groups', value: JSON.stringify(TRAFFIC_PACKAGE_GROUPS), is_secret: false, has_value: true, masked_value: '' },
          { key: 'traffic_package_catalog.items', value: JSON.stringify(TRAFFIC_PACKAGE_ITEMS), is_secret: false, has_value: true, masked_value: '' },
          { key: 'traffic_package_catalog.groups', value: JSON.stringify(TRAFFIC_PACKAGE_GROUPS), is_secret: false, has_value: true, masked_value: '' },
        ],
        total: 14,
        page: 1,
        page_size: 200,
      },
      'GET /v2/admin/notification-templates': {
        list: [
          {
            channel: 'email',
            code: 'invoice_paid',
            name: '账单支付成功',
            description: '客户支付账单后发送',
            audience: 'user',
            subject: '您的账单已支付',
            content: '您好，账单 {{invoice_no}} 已支付成功，金额 {{amount}} 元。',
            is_enabled: true,
            variables: ['invoice_no', 'amount'],
          },
          {
            channel: 'sms',
            code: 'service_suspended',
            name: '实例暂停通知',
            description: '实例被上游暂停后发送短信',
            audience: 'user',
            subject: null,
            content: '您的实例 {{service_name}} 已暂停，请及时处理。',
            is_enabled: false,
            variables: ['service_name'],
          },
        ],
        total: 2,
      },
      'GET /v2/admin/integration-plugins': {
        list: [
          {
            id: 1,
            domain: 'captcha',
            slug: 'geetest',
            key: 'geetest',
            name: '极验行为验证',
            version: '1.2.0',
            is_installed: true,
            is_enabled: false,
            capabilities: ['captcha'],
            status: 1,
            updated_at: '2026-09-01 10:00:00',
            latest_runtime_log: null,
          },
          {
            id: 2,
            domain: 'payment',
            slug: 'alipay',
            key: 'alipay',
            name: '支付宝当面付',
            version: '1.0.3',
            is_installed: true,
            is_enabled: true,
            capabilities: ['payment'],
            status: 1,
            updated_at: '2026-09-12 14:20:00',
            latest_runtime_log: { id: 1, action: 'pay', status: 'success', created_at: '2026-11-09 08:41:00' },
          },
          {
            id: 3,
            domain: 'upstream',
            slug: 'zjmf',
            key: 'zjmf',
            name: '智简魔方财务对接',
            version: '2.1.0',
            is_installed: true,
            is_enabled: false,
            capabilities: ['provision', 'console'],
            status: 1,
            updated_at: '2026-09-20 09:00:00',
            latest_runtime_log: null,
          },
        ],
        total: 3,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/schedules/overview': {
        environment: { php_version: '8.3', timezone: 'Asia/Shanghai', driver: 'database', queue: 'sync' },
        tasks: [
          {
            key: 'invoice:generate',
            name: '账单生成',
            cron: '0 1 * * *',
            enabled: true,
            last_run_at: '2026-11-09 01:00:02',
            next_run_at: '2026-11-10 01:00:00',
            status: 'success',
          },
          {
            key: 'service:sync-status',
            name: '实例状态同步',
            cron: '*/10 * * * *',
            enabled: true,
            last_run_at: '2026-11-09 09:40:01',
            next_run_at: '2026-11-09 09:50:00',
            status: 'success',
          },
        ],
        recent_logs: [
          { id: 1, task_key: 'invoice:generate', status: 'success', message: '生成账单 6 张', created_at: '2026-11-09 01:00:02' },
          { id: 2, task_key: 'service:sync-status', status: 'success', message: '同步实例 3 台', created_at: '2026-11-09 09:40:01' },
        ],
      },
      'GET /v2/admin/staff': {
        list: [
          {
            id: 1,
            username: 'admin',
            nickname: '演示管理员',
            email: 'admin@example.com',
            phone: '',
            status: 1,
            is_super: true,
            role: { id: 1, name: 'super_admin', label: '超级管理员' },
            roles: [{ id: 1, name: 'super_admin', label: '超级管理员' }],
            last_login_at: '2026-11-09 08:30:00',
            created_at: '2026-01-18 10:24:11',
          },
        ],
        total: 1,
        page: 1,
        page_size: 20,
      },
      // 员工表单的角色下拉（字面路径，必须在任何 `staff/:id` 之前）
      'GET /v2/admin/staff/roles': {
        list: [
          { id: 1, name: 'super_admin', label: '超级管理员', value: 'super_admin' },
          { id: 2, name: 'admin', label: '普通管理员', value: 'admin' },
          { id: 3, name: 'visitor', label: '访客', value: 'visitor' },
        ],
      },
      'GET /v2/admin/roles': {
        list: [
          { id: 1, name: 'super_admin', label: '超级管理员', permissions: ['*'], admin_count: 1, is_builtin: true, updated_at: '2026-01-18 10:24:11' },
          { id: 2, name: 'admin', label: '普通管理员', permissions: ['dashboard.view', 'user.list', 'invoice.list', 'ticket.list'], admin_count: 0, is_builtin: true, updated_at: '2026-01-18 10:24:11' },
          { id: 3, name: 'visitor', label: '访客', permissions: ['dashboard.view'], admin_count: 0, is_builtin: true, updated_at: '2026-01-18 10:24:11' },
        ],
        total: 3,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/permissions': [
        { key: 'dashboard.view', name: '查看看板', module: 'dashboard', module_label: '数据看板', group: 'dashboard', group_label: '数据看板', action_label: '查看' },
        { key: 'user.list', name: '客户列表', module: 'user', module_label: '用户管理', group: 'user', group_label: '客户管理', action_label: '查看' },
        { key: 'user.manage', name: '客户管理', module: 'user', module_label: '用户管理', group: 'user', group_label: '客户管理', action_label: '管理' },
        { key: 'invoice.list', name: '账单列表', module: 'finance', module_label: '财务管理', group: 'finance', group_label: '账务管理', action_label: '查看' },
        { key: 'invoice.manage', name: '账单管理', module: 'finance', module_label: '财务管理', group: 'finance', group_label: '账务管理', action_label: '管理' },
        { key: 'ticket.list', name: '工单列表', module: 'ticket', module_label: '工单处理', group: 'ticket', group_label: '工单管理', action_label: '查看' },
        { key: 'settings.view', name: '查看设置', module: 'settings', module_label: '系统设置', group: 'settings', group_label: '系统设置', action_label: '查看' },
      ],
      'GET /v2/admin/database/status': {
        driver: 'mysql',
        version: '8.0.36',
        connection: 'mysql',
        database: 'turaidc_demo',
        total_size: '18.4 MB',
        tables: [
          { name: 'users', rows: 512, data_size: '2.1 MB', index_size: '0.4 MB' },
          { name: 'services', rows: 286, data_size: '1.8 MB', index_size: '0.3 MB' },
          { name: 'invoices', rows: 1420, data_size: '3.6 MB', index_size: '0.9 MB' },
        ],
      },
      'GET /v2/admin/redis/status': {
        connected: true,
        driver: 'redis',
        version: '7.2.4',
        used_memory: '12.5 MB',
        keys: 128,
        hit_rate: '98.2%',
        uptime: '6 天 04:12:31',
      },
      'GET /v2/admin/open-api/config': {
        enabled: false,
        require_phone: 0,
        require_verified: 0,
        max_keys_per_user: 10,
        rate_limit: 60,
        write_rate_limit: 30,
      },
      'GET /v2/admin/open-api/keys': {
        list: [
          {
            id: 1,
            user_id: 1001,
            user: userSummary(1001),
            name: '演示密钥',
            key: 'demo-key-prefix',
            key_prefix: 'demo',
            status: 1,
            status_label: '启用',
            request_count: 128,
            created_at: '2026-09-01 10:00:00',
            last_used_at: '2026-11-08 20:11:00',
          },
        ],
        total: 1,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/open-api/upstream-usage-logs': {
        list: [
          {
            id: 1,
            key_id: 1,
            key_name: '演示密钥',
            user_id: 1001,
            user: userSummary(1001),
            method: 'POST',
            path: '/api/v2/open/services',
            status_code: 200,
            duration_ms: 186,
            ip: '198.51.100.24',
            created_at: '2026-11-08 20:11:00',
          },
        ],
        total: 1,
        page: 1,
        page_size: 20,
      },
      'GET /v2/admin/log-cleanups/overview': {
        channels: [
          { channel: 'runtime', label: '运行日志', rows: 1240, archived: 3 },
          { channel: 'api', label: '接口日志', rows: 862, archived: 2 },
          { channel: 'tasks', label: '任务日志', rows: 210, archived: 1 },
        ],
        last_cleanup_at: '2026-11-01 03:00:00',
      },

      // ─── 日志中心 ─────────────────────────────────────────────
      'GET /v2/admin/logs/system': logPage('system', '系统日志', [
        ['系统完成每日账单生成', '生成账单 6 张，跳过 0 张'],
        ['系统完成实例状态同步', '同步实例 3 台，状态变化 0 台'],
      ]),
      'GET /v2/admin/log-summaries/system': { total: 2, levels: { info: 2 } },
      'GET /v2/admin/logs/runtime': logPage('runtime', '运行日志', [
        ['队列任务 queue:drain 执行完成', '处理任务 4 个，失败 0 个'],
        ['计划任务 service:sync-status 执行完成', '耗时 812ms'],
      ]),
      'GET /v2/admin/log-summaries/runtime': { total: 2, levels: { info: 2 } },
      'GET /v2/admin/logs/admin-logins': logPage('admin-logins', '后台登录日志', [
        ['管理员登录成功', 'IP 203.0.113.20'],
        ['管理员修改密码', '演示环境不校验原密码'],
      ]),
      'GET /v2/admin/logs/api': logPage('api', '接口日志', [
        ['GET /v2/admin/users 200', '耗时 42ms'],
        ['POST /v2/admin/tickets 200', '耗时 68ms'],
      ]),
      'GET /v2/admin/logs/sms': logPage('sms', '短信日志', [
        ['实例暂停通知发送成功', '演示环境不外发，仅记录'],
      ]),
      'GET /v2/admin/log-summaries/sms': { total: 1 },
      'GET /v2/admin/logs/email': logPage('email', '邮件日志', [
        ['账单支付成功通知发送成功', '收件人 demo@example.com（示例地址）'],
      ]),
      'GET /v2/admin/logs/tasks': logPage('tasks', '计划任务日志', [
        ['invoice:generate 执行成功', '生成账单 6 张'],
        ['service:sync-status 执行成功', '同步实例 3 台'],
      ]),
      'GET /v2/admin/log-summaries/tasks': { total: 2 },
      'GET /v2/admin/logs/gateway': logPage('gateway', '支付网关日志', [
        ['支付宝当面付回调成功', '账单 DEMO202610300003 已支付'],
      ]),
      'GET /v2/admin/logs/activity': logPage('activity', '操作日志', [
        ['管理员修改实例规格', '演示香港-轻量云：CPU 2 核 → 4 核'],
      ]),
      'GET /v2/admin/logs/upstream': logPage('upstream', '上游对接日志', [
        ['手动开通：创建实例成功', '上游实例 ID 9001'],
      ]),
      'GET /v2/admin/log-summaries/upstream': { total: 1 },
      'GET /v2/admin/logs/upstream-uploads': logPage('upstream-uploads', '上游上传日志', [
        ['镜像上传成功', 'debian-12-demo.qcow2'],
      ]),
      'GET /v2/admin/logs/:channel/:id': (() => {
        const row = logRow('system', 'system', '系统完成每日账单生成', '生成账单 6 张，跳过 0 张', '2026-11-09 01:00:02');

        return {
          log: {
            id: row.id,
            channel: 'system',
            source: 'scheduler',
            fields: row,
            message: row.message,
            context: { summary: row.summary, params: {}, request_data: {}, response_data: {} },
            created_at: row.created_at,
          },
        };
      })(),
    },
    refused: {
      'DELETE /v2/admin/users/:id': { message: '演示环境不执行删除用户' },
      'POST /v2/admin/services/:id/refunds': { message: '演示环境不执行退款' },
      'PUT /v2/admin/settings': { message: '演示环境不保存系统设置' },
    },
  };
}

/** 日志行：字段名对齐 logs.ts 的 V2 行（页面会把 message_excerpt 映射成 message） */
function logRow(channel, source, message, summary, createdAt) {
  return {
    id: `${channel}-${createdAt}`,
    channel,
    source,
    level: 'info',
    message_excerpt: message,
    context_excerpt: summary,
    error_excerpt: '',
    summary,
    created_at: createdAt,
  };
}

function logPage(channel, source, rows) {
  const list = rows.map((row, index) => {
    const [message, summary] = row;
    const day = String(9 - index).padStart(2, '0');

    return logRow(channel, source, message, summary, `2026-11-${day} 09:${String(10 + index * 7).padStart(2, '0')}:00`);
  });

  return { list, total: list.length, page: 1, page_size: 20, summary: { total: list.length, levels: { info: list.length } } };
}
