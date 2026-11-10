/**
 * 控制台（/console）示例数据。
 *
 * 键是 `METHOD /path`（路径不含查询串，允许 `:id` 通配）；值是业务 `data`，
 * 传输层会自己套 `{code: 0, message: 'success', data}`。
 *
 * 补数据的口径：先让页面有内容，再让内容自洽——实例、账单、工单都从 `base.mjs` 派生。
 *
 * 两条容易踩的规则：
 * 1. 路由是**按声明顺序**首次匹配（shared/demo/transport.ts 的 `routes.find`），所以
 *    `services/grouped-overview` 这类字面路径必须写在同一段数的 `services/:id` 之前，
 *    否则会被通配吃掉。
 * 2. 金额一律字符串、账单状态用数字（0 待支付 / 1 已支付），与后端类型定义保持一致
 *    （shared/statusConfig.js 的 INVOICE_STATUS / SERVICE_STATUS）。
 */
import { CUSTOMER, INVOICES, PRODUCTS, SERVICES, SITE_CONFIG, TICKETS } from './base.mjs';

/** 服务状态码：0 开通中 / 1 已开通 / 2 已暂停 / 3 已到期 / 4 已取消 */
const SERVICE_STATUS_CODE = { pending: 0, active: 1, suspended: 2, expired: 3, cancelled: 4 };

/** 工单状态码：0 开启 / 1 客户回复 / 2 员工回复 / 3 已关闭 */
const TICKET_STATUS_CODE = { open: 0, user_reply: 1, staff_reply: 2, replied: 2, closed: 3 };

const BILLING_CYCLE_LABEL = { monthly: '月付', quarterly: '季付', semiannually: '半年付', yearly: '年付' };

/** 演示用固定时间，避免每次生成 fixture 都变（--check 才稳定） */
const DEMO_CREATED_AT = ['2026-01-18 10:24:11', '2026-03-02 09:11:40', '2026-05-21 16:03:27'];

/** 演示环境不写真实证件号：只留掩码占位 */
const DEMO_ID_CARD_MASKED = 'DEMO**********0000';

/** GB 数字 → 展示文案（base.mjs 里 traffic 用的是 GB 数） */
function gb(value) {
  return `${Number(value || 0)}G`;
}

/** 按名字从 base 商品目录里取商品，保证实例与商品对得上 */
function productOf(service) {
  return PRODUCTS.find((item) => item.name === service.product_name) || PRODUCTS[0];
}

/** 区域名取自商品目录（商品没写就用「通用」） */
function regionOf(service) {
  return productOf(service).region || '通用';
}

function statusCode(service) {
  return SERVICE_STATUS_CODE[String(service.status || '').toLowerCase()] ?? 1;
}

function statusTone(code) {
  if (code === 1) return 'success';
  if (code === 0) return 'warning';
  if (code === 2) return 'purple';
  return 'default';
}

/** 实例流量：base 里 traffic_used / traffic_limit 都是 GB 数 */
function trafficOf(service) {
  const used = Number(service.traffic_used || 0);
  const limit = Number(service.traffic_limit || 0);
  const percent = limit > 0 ? Math.round((used / limit) * 1000) / 10 : 0;

  return {
    usage: String(used),
    limit,
    remaining: String(Math.max(limit - used, 0)),
    usage_label: gb(used),
    limit_label: gb(limit),
    remaining_label: gb(Math.max(limit - used, 0)),
    usage_percent: percent,
    limited: limit > 0,
    button_text: '购买流量包',
    purchase_enabled: true,
  };
}

/**
 * 列表 / 分组概览用的实例摘要（`ServiceInstance`）。
 *
 * 字段按 frontend-user-v4-console/src/types/client.ts 的 `ServiceInstance` 取，
 * 页面真正渲染的（名称 / 规格 / 状态 / 周期 / 到期 / 备注 / 账单入口）都保证非空。
 */
function serviceSummary(service, index) {
  const code = statusCode(service);
  const product = productOf(service);

  return {
    id: service.id,
    name: service.name,
    custom_service_name: service.name,
    combined_display_name: service.product_name,
    product_display_name: service.product_name,
    product_spec_display: `${service.cpu} 核 ${service.memory / 1024}G / ${service.disk}G`,
    remark: '',
    status: code,
    status_label: service.status_label,
    status_tone: statusTone(code),
    billing_cycle: service.billing_cycle,
    billing_cycle_label: BILLING_CYCLE_LABEL[service.billing_cycle] || '月付',
    amount: service.monthly_price,
    expires_at: service.expires_at,
    created_at: DEMO_CREATED_AT[index % DEMO_CREATED_AT.length],
    auto_renew: 1,
    domain: service.hostname,
    specs: [
      { key: 'os', label: '操作系统', value: service.os },
      { key: 'area', label: '区域', value: regionOf(service) },
      { key: 'cpu', label: 'CPU', value: `${service.cpu} 核` },
      { key: 'memory', label: '内存', value: `${service.memory / 1024} G` },
      { key: 'system_disk_size', label: '系统盘', value: `${service.disk} G` },
      { key: 'bw', label: '带宽', value: `${service.bandwidth} Mbps` },
      { key: 'ip_num', label: 'IP数量', value: '1 个' },
    ],
    product: {
      id: product.id,
      display_name: service.product_name,
      group_name: product.group_name,
      type_label: product.group_name,
      catalog_type: product.product_type,
    },
    upstream: {
      provider_key: 'demo',
      host_id: service.id + 30000000,
      status: service.status,
      remote_error: '',
      os: service.os,
      dedicated_ip: service.ip,
    },
    invoice: {
      id: 9101,
      invoice_no: INVOICES[0].invoice_no,
      order_no: 'DEMOORDER202611090001',
      status: INVOICES[0].status,
    },
    traffic: trafficOf(service),
  };
}

/** 控制台详情（`ConsoleServiceDetail`）：实例摘要 + 运行态 / 连接 / 能力开关 */
function serviceDetail(service, index) {
  const summary = serviceSummary(service, index);

  return {
    ...summary,
    can_manage: true,
    console_template: '',
    console_mode: 'standard',
    machine_category: { key: 'cloud_server', label: '云服务器' },
    runtime: {
      power_state: 'on',
      power_label: service.status_label,
      description: '演示实例，操作不会真正下发到上游',
    },
    connection: {
      hostname: service.hostname,
      username: 'root',
      has_password: true,
      password: 'Demo@123456',
      port: 22,
      dedicated_ip: service.ip,
      internal_ip: `10.0.0.${index + 11}`,
      assigned_ips: [service.ip],
      nat_remote_address: '',
      nat_remote_host: '',
      nat_remote_port: 0,
    },
    actions: {
      refresh: true,
      power: true,
      module_status: false,
      reinstall: true,
      password_reset: true,
      rescue: false,
      vnc: false,
      traffic_package: true,
      upgrade: false,
      snapshot: false,
      iso: false,
    },
    _sync: { at: '2026-11-09 09:00:00', source: 'demo' },
  };
}

/** 账单：把 base 的 INVOICES 摊平成 `InvoiceRecord`，并挂上对应的商品 / 实例 */
function invoiceRecord(invoice, index) {
  const service = SERVICES[index] || SERVICES[0];
  const product = productOf(service);

  return {
    ...invoice,
    // 账单状态保持数字（0 待支付 / 1 已支付）
    status: Number(invoice.status),
    payable_amount: invoice.status === 0 ? invoice.amount : '0.00',
    paid_amount: invoice.status === 1 ? invoice.amount : '0.00',
    discount: '0.00',
    billing_cycle: service.billing_cycle,
    billing_cycle_label: BILLING_CYCLE_LABEL[service.billing_cycle] || '月付',
    combined_display_name: service.name,
    product_display_name: service.product_name,
    product_spec_display: `${service.cpu} 核 ${service.memory / 1024}G`,
    service_id: service.id,
    product_id: product.id,
    product: { id: product.id, display_name: service.product_name, group_name: product.group_name },
    service: { id: service.id, name: service.name },
    items: (invoice.items || []).map((item, itemIndex) => ({
      id: invoice.id * 10 + itemIndex,
      description: item.description,
      quantity: 1,
      unit_price: item.amount,
      amount: item.amount,
    })),
  };
}

/** 公告 / 帮助文章共用的内容记录（`ContentArticleRecord`） */
function contentArticle(id, title, summary, categoryName, publishAt, content) {
  return {
    id,
    content_type: 'notice',
    type: 'notice',
    title,
    slug: `demo-${id}`,
    summary,
    excerpt: summary,
    content,
    category_id: 1,
    content_category_id: 1,
    category_name: categoryName,
    category: categoryName,
    category_slug: 'demo',
    status: 1,
    is_pinned: 0,
    is_recommended: 0,
    view_count: 128,
    publish_at: publishAt,
    last_published_at: publishAt,
    created_at: publishAt,
    updated_at: publishAt,
  };
}

export function consoleFixtures(base = {}) {
  // base 目前只带一个 accounts 标记（见 generate.mjs），保留形参方便以后按账号切数据集
  const accountTag = String(base?.accounts ?? 'demo');

  const serviceSummaries = SERVICES.map(serviceSummary);
  const serviceDetails = SERVICES.map(serviceDetail);

  /** 分组概览：按商品的一级分组汇总实例，dashboard 的「产品概览」与服务页的 tab 都吃这份 */
  const groupNames = [...new Set(SERVICES.map((service) => productOf(service).group_name))];
  const overviewGroups = groupNames.map((groupName, index) => {
    const members = SERVICES.filter((service) => productOf(service).group_name === groupName);
    const activeCount = members.filter((service) => statusCode(service) === 1).length;
    const pendingCount = members.filter((service) => statusCode(service) === 0).length;
    const group = {
      key: `group-${index + 1}`,
      id: index + 1,
      group_level: 1,
      group_level_label: '一级分组',
      product_type: productOf(members[0]).product_type,
      product_type_label: groupName,
      first_product_group_name: groupName,
      name: groupName,
      title: groupName,
      description: `${groupName}下的演示实例`,
      count: members.length,
      active_count: activeCount,
      pending_count: pendingCount,
      // 到期在 30 天内的算「即将到期」：base 里 5102 / 5103 都是 11 月底到期
      expiring_count: members.filter((service) => /2026-11-(2|3)\d/.test(service.expires_at)).length,
      primary_service_id: members[0].id,
      console_mode: 'standard',
      is_nat_console: false,
      icon_resolved: { mode: 'text', text: groupName.slice(0, 1), icon_name: null, abbr: groupName },
      children: members.map((service) => ({
        key: `service-${service.id}`,
        id: service.id,
        name: service.name,
        title: service.name,
        group_level: 2,
        group_path_text: `${groupName} / ${service.name}`,
        count: 1,
        active_count: statusCode(service) === 1 ? 1 : 0,
        pending_count: statusCode(service) === 0 ? 1 : 0,
        expiring_count: 0,
        status_label: service.status_label,
        status_tone: statusTone(statusCode(service)),
        primary_service_id: service.id,
        preview_names: [service.name],
        console_mode: 'standard',
        is_nat_console: false,
      })),
      items: members.map((service) => ({
        id: service.id,
        name: service.name,
        product_name: service.product_name,
        group_name: groupName,
        root_group_name: groupName,
        group_path_text: groupName,
        status: statusCode(service),
        status_label: service.status_label,
        status_tone: statusTone(statusCode(service)),
        billing_cycle_label: BILLING_CYCLE_LABEL[service.billing_cycle] || '月付',
        expires_at: service.expires_at,
        amount: service.monthly_price,
        console_mode: 'standard',
        is_nat_console: false,
      })),
    };

    return group;
  });

  /** 余额流水：与 CUSTOMER.balance（1286.40）对得上——充 500 再扣 68 */
  const balanceLogs = [
    {
      id: 6101,
      event_type: 'recharge',
      change_amount: '500.00',
      balance_after: '1354.40',
      remark: '账户充值',
      created_at: '2026-10-25 21:03:11',
      invoice_id: 9102,
    },
    {
      id: 6102,
      event_type: 'invoice_payment',
      change_amount: '-68.00',
      balance_after: '1286.40',
      remark: '演示香港-轻量云 续费 1 个月',
      created_at: '2026-10-25 21:05:02',
      invoice_id: 9103,
    },
  ];

  /** 追加一条上月的已支付续费账单：让「本月消费 / 余额流水 / 已支付账单」三处对得上 */
  const paidRenewInvoice = {
    id: 9103,
    invoice_no: 'DEMO202610250003',
    type: 'renew',
    type_label: '续费',
    status: 1,
    status_label: '已支付',
    amount: '68.00',
    paid_at: '2026-10-25 21:05:02',
    due_date: '2026-10-25 00:00:00',
    created_at: '2026-10-24 20:11:30',
    items: [{ description: '演示香港-轻量云 续费 1 个月', amount: '68.00' }],
  };

  const invoiceRecords = [...INVOICES, paidRenewInvoice].map(invoiceRecord);

  /** 我的优惠券：1 张可用 + 1 张已过期（CUSTOMER.coupon_count = 2） */
  const couponRecords = [
    {
      id: 7201,
      coupon_code: 'DEMO10',
      coupon_id: 9001,
      name: '满 100 减 10',
      description: '适用于云服务器续费',
      status: 'available',
      status_label: '可使用',
      status_reason: '',
      discount_type: 'fixed',
      discount_type_label: '满减',
      discount_value: '10.00',
      discount_label: '减 ¥10',
      max_discount_amount: null,
      min_amount: '100.00',
      can_claim: false,
      product_scope_text: '全部云服务器',
      validity_text: '2026-12-31 23:59:59 到期',
      starts_at: '2026-10-01 00:00:00',
      expires_at: '2026-12-31 23:59:59',
      receive_type: 'manual',
      receive_type_label: '手动领取',
      per_user_limit: 1,
      used_times: 0,
      remaining_times: 1,
      first_order_only: false,
      remaining_stock: 100,
      used_at: null,
      revoked_at: null,
      billing_cycle_text: '不限周期',
      products: [],
    },
    {
      id: 7202,
      coupon_code: 'DEMO20',
      coupon_id: 9002,
      name: '9 折优惠券',
      description: '适用于独立服务器',
      status: 'expired',
      status_label: '已过期',
      status_reason: '已超过有效期',
      discount_type: 'percentage',
      discount_type_label: '折扣',
      discount_value: '0.90',
      discount_label: '9 折',
      max_discount_amount: '200.00',
      min_amount: '0.00',
      can_claim: false,
      product_scope_text: '全部独立服务器',
      validity_text: '已于 2026-10-31 23:59:59 过期',
      starts_at: '2026-09-01 00:00:00',
      expires_at: '2026-10-31 23:59:59',
      receive_type: 'manual',
      receive_type_label: '手动领取',
      per_user_limit: 1,
      used_times: 0,
      remaining_times: 0,
      first_order_only: false,
      remaining_stock: 0,
      used_at: null,
      revoked_at: null,
      billing_cycle_text: '不限周期',
      products: [],
    },
  ];

  /**
   * 支付记录：与 balance-logs 一一对应
   * - 9102 充值 500.00 → 余额流水 6101（+500，21:03:11）
   * - 9103 续费 68.00 走余额 → 余额流水 6102（-68，21:05:02）
   */
  const paymentRecords = [
    {
      id: 9201,
      payment_no: 'DEMOPAY202610250001',
      trade_no: 'DEMO-ALIPAY-202610250001',
      gateway: 'alipay',
      gateway_key: 'alipay',
      gateway_label: '支付宝',
      amount: '500.00',
      status: 1,
      status_label: '成功',
      paid_at: '2026-10-25 21:03:11',
      created_at: '2026-10-25 21:01:52',
      invoice_id: 9102,
      invoice_no: 'DEMO202610250002',
      invoice_type: 'recharge',
      invoice_status: 1,
      invoice: {
        id: 9102,
        invoice_no: 'DEMO202610250002',
        status: 1,
        amount: '500.00',
        paid_amount: '500.00',
        type: 'recharge',
      },
    },
    {
      id: 9202,
      payment_no: 'DEMOPAY202610250002',
      trade_no: 'DEMO-BALANCE-202610250002',
      gateway: 'balance',
      gateway_key: 'balance',
      gateway_label: '余额支付',
      amount: '68.00',
      status: 1,
      status_label: '成功',
      paid_at: '2026-10-25 21:05:02',
      created_at: '2026-10-25 21:04:40',
      invoice_id: 9103,
      invoice_no: 'DEMO202610250003',
      invoice_type: 'renew',
      invoice_status: 1,
      invoice: {
        id: 9103,
        invoice_no: 'DEMO202610250003',
        status: 1,
        amount: '68.00',
        paid_amount: '68.00',
        type: 'renew',
      },
    },
    {
      id: 9203,
      payment_no: 'DEMOPAY202611090003',
      trade_no: '',
      gateway: 'alipay',
      gateway_key: 'alipay',
      gateway_label: '支付宝',
      amount: '68.00',
      status: 0,
      status_label: '待支付',
      paid_at: '',
      created_at: '2026-11-09 09:12:00',
      invoice_id: 9101,
      invoice_no: 'DEMO202611090001',
      invoice_type: 'renew',
      invoice_status: 0,
      invoice: {
        id: 9101,
        invoice_no: 'DEMO202611090001',
        status: 0,
        amount: '68.00',
        paid_amount: '0.00',
        type: 'renew',
      },
    },
  ];

  /**
   * 订单：只给与实例相关的两张续费单（充值账单不产生订单）。
   * 状态用数字：0 待付款 / 3 已完成。
   */
  const orderRecords = [
    {
      id: 8501,
      order_no: 'DEMOORDER202611090001',
      type: 'renew',
      type_label: '续费',
      status: 0,
      status_label: '待付款',
      amount: '68.00',
      paid_amount: '0.00',
      discount: '0.00',
      billing_cycle: SERVICES[0].billing_cycle,
      quantity: 1,
      product_name: SERVICES[0].product_name,
      product_full_path: `云服务器 / ${SERVICES[0].product_name}`,
      product_path_segments: ['云服务器', SERVICES[0].product_name],
      service_name: SERVICES[0].name,
      coupon_code: '',
      remark: '演示订单，未支付',
      paid_at: '',
      created_at: '2026-11-09 09:12:00',
      invoice: {
        id: 9101,
        invoice_no: 'DEMO202611090001',
        type: 'renew',
        status: 0,
        amount: '68.00',
        paid_amount: '0.00',
        paid_at: '',
        due_date: '2026-11-20 00:00:00',
        created_at: '2026-11-09 09:12:00',
        payments: [],
      },
      service: {
        id: SERVICES[0].id,
        name: SERVICES[0].name,
        domain: SERVICES[0].hostname,
        status: 1,
        expires_at: SERVICES[0].expires_at,
      },
      coupon: null,
    },
    {
      id: 8502,
      order_no: 'DEMOORDER202610250002',
      type: 'renew',
      type_label: '续费',
      status: 3,
      status_label: '已完成',
      amount: '68.00',
      paid_amount: '68.00',
      discount: '0.00',
      billing_cycle: SERVICES[0].billing_cycle,
      quantity: 1,
      product_name: SERVICES[0].product_name,
      product_full_path: `云服务器 / ${SERVICES[0].product_name}`,
      product_path_segments: ['云服务器', SERVICES[0].product_name],
      service_name: SERVICES[0].name,
      coupon_code: '',
      remark: '演示订单，余额支付完成',
      paid_at: '2026-10-25 21:05:02',
      created_at: '2026-10-24 20:11:30',
      invoice: {
        id: 9103,
        invoice_no: 'DEMO202610250003',
        type: 'renew',
        status: 1,
        amount: '68.00',
        paid_amount: '68.00',
        paid_at: '2026-10-25 21:05:02',
        due_date: '2026-10-25 00:00:00',
        created_at: '2026-10-24 20:11:30',
        payments: [
          {
            id: 9202,
            payment_no: 'DEMOPAY202610250002',
            gateway: 'balance',
            trade_no: 'DEMO-BALANCE-202610250002',
            amount: '68.00',
            status: 1,
            paid_at: '2026-10-25 21:05:02',
          },
        ],
      },
      service: {
        id: SERVICES[0].id,
        name: SERVICES[0].name,
        domain: SERVICES[0].hostname,
        status: 1,
        expires_at: SERVICES[0].expires_at,
      },
      coupon: null,
    },
  ];

  const noticeArticles = [
    contentArticle(
      8101,
      '演示公告：控制台已切换为示例数据',
      '当前站点处于演示模式，所有实例、账单与工单均为示例数据，任何操作都不会真正执行。',
      '站点公告',
      '2026-11-08 10:00:00',
      '<p>欢迎体验 TuraIDC 演示站。</p><p>本页数据由 <code>demo/fixtures/console.json</code> 生成，是只读示例。</p>',
    ),
    contentArticle(
      8102,
      '演示公告：香港节点维护演练',
      '这是演示用维护通知，用于展示控制台公告列表的排版效果。',
      '维护通知',
      '2026-11-05 18:30:00',
      '<p>本条为演示公告，不涉及任何真实维护。</p>',
    ),
  ];

  const helpArticles = [
    contentArticle(
      8201,
      '如何重置实例的登录密码',
      '在控制台「实例概览」→「登录凭据」里点「重置密码」，按提示输入新密码即可。',
      '常见问题',
      '2026-10-20 09:00:00',
      '<p>演示环境不会真的改密码，只展示交互流程。</p>',
    ),
    contentArticle(
      8202,
      '如何购买流量包',
      '在「实例概览」→「配置信息」右上角点「购买流量包」，选择档位后生成账单。',
      '计费与账单',
      '2026-10-12 14:20:00',
      '<p>演示环境的账单不会真正扣费。</p>',
    ),
  ];

  const ticketRecords = TICKETS.map((ticket, index) => {
    const service = SERVICES[index] || SERVICES[0];

    return {
      id: ticket.id,
      subject: ticket.title,
      status: TICKET_STATUS_CODE[String(ticket.status).toLowerCase()] ?? 0,
      priority: ticket.priority,
      department: ticket.department,
      content: `${ticket.title}（演示工单，内容为示例文本）`,
      close_reason: null,
      close_reason_label: null,
      created_at: ticket.created_at,
      updated_at: ticket.updated_at,
      user_id: CUSTOMER.id,
      service_id: service.id,
      user: { id: CUSTOMER.id, display_name: CUSTOMER.nickname, email: CUSTOMER.email },
      service: { id: service.id, name: service.name, display_name: service.name },
      replies: [],
      attachments: [],
      attachment_urls: [],
    };
  });

  /**
   * 每个实例的详情三类只读接口。
   *
   * 这些**字面**路径必须排在 `services/:id` 通配之前（传输层按声明顺序首次匹配），
   * 否则点开哪台实例拿到的都是第一台的详情。
   */
  const serviceDetailEndpoints = Object.fromEntries(
    SERVICES.flatMap((service, index) => {
      const detail = serviceDetails[index];

      return [
        [`GET /v2/client/services/${service.id}`, { service: detail }],
        [`GET /v2/client/services/${service.id}/connection`, { connection: detail.connection }],
        [`GET /v2/client/services/${service.id}/runtime`, { runtime: detail.runtime }],
      ];
    }),
  );

  return {
    endpoints: {
      'GET /v2/site/config': SITE_CONFIG,

      // 登录：演示环境任意账号密码都能进，凭据在 /start/ 与登录页上都写着
      'POST /v2/client/login': {
        token: `demo-client-token-${accountTag}`,
        user: {
          ...CUSTOMER,
          name: CUSTOMER.nickname,
          display_name: CUSTOMER.nickname,
          cash_balance: CUSTOMER.balance,
        },
      },

      // 控制台首屏：谁登录了、余额多少
      // 注意后端契约是「用户对象平铺」，不是 { user: {...} }，否则导航栏与总览页都读不到名字
      'GET /v2/client/auth/info': {
        ...CUSTOMER,
        name: CUSTOMER.nickname,
        display_name: CUSTOMER.nickname,
        status: 1,
        cash_balance: CUSTOMER.balance,
        referral_code: 'DEMO1001',
        referral_frozen_balance: '0.00',
        referral_available_balance: '128.60',
        referral_pending_withdrawal_balance: '0.00',
        referral_withdrawn_balance: '0.00',
        total_sales_amount: '1286.00',
        is_verified: 1,
        verification_status: 1,
        verification_message: '',
        real_name: '演示客户',
        id_card_masked: DEMO_ID_CARD_MASKED,
        qq: '',
        avatar: '',
        login_email_alert: 0,
        login_notify: 1,
        login_location_alert: 0,
        password_change_alert: 1,
        phone_change_alert: 1,
        email_change_alert: 1,
        marketing_alert: 0,
        last_login_at: '2026-11-09 08:41:22',
        last_login_ip: '198.51.100.1',
        verified_at: CUSTOMER.created_at,
        roles: ['client'],
        features: { ai_assistant: false, auto_refund: false },
        // 账户策略：演示站不拦任何写操作，提示条与 40301 兜底都不会出现
        account_policy: {
          require_verification: false,
          require_phone: false,
          require_email: false,
          missing_items: [],
          message: '',
        },
      },

      // 账户设置：支付宝账号 / 通知偏好 / 验证码
      // 验证码返回「不需要」——演示站不弹极验，登录与注册都不该被拦住
      'GET /v2/client/auth/captcha-config': {
        enabled: false,
        captcha_id: '',
        provider: '',
        scenes: {},
        script_url: '',
      },
      'GET /v2/client/auth/alipay-account': {
        real_name: '演示客户',
        account: 'demo-pay@example.com',
        is_bound: true,
      },
      'GET /v2/client/auth/notification-preferences': {
        login_notify: 1,
        login_location_alert: 0,
        password_change_alert: 1,
        phone_change_alert: 1,
        email_change_alert: 1,
        marketing_alert: 0,
      },

      // 实例列表 + 分组概览（字面路径必须排在 services/:id 之前）
      'GET /v2/client/services': {
        list: serviceSummaries,
        total: serviceSummaries.length,
        page: 1,
        page_size: 10,
      },
      'GET /v2/client/services/grouped-overview': {
        total: serviceSummaries.length,
        category_total: overviewGroups.length,
        list: overviewGroups,
        catalog_types: overviewGroups.map((group) => ({
          label: group.name,
          value: group.key,
          count: group.count,
          icon_resolved: group.icon_resolved,
        })),
      },

      // 实例详情与它下面几个只读面板（字面路径在前，通配在后）
      ...serviceDetailEndpoints,
      'GET /v2/client/services/:id': { service: serviceDetails[0] },
      'GET /v2/client/services/:id/connection': {
        connection: serviceDetails[0].connection,
      },
      'GET /v2/client/services/:id/runtime': {
        runtime: serviceDetails[0].runtime,
      },
      'GET /v2/client/services/:id/console/capabilities': {
        supported: true,
        // 演示站不模拟自定义区域与上游强能力，控制台走静态 tab 集合
        fetchable: false,
        nat_supported: false,
        monitor_supported: false,
        snapshot_supported: false,
        iso_supported: false,
        area_preload_all: false,
        areas: [],
      },

      'GET /v2/client/recharge/gateways': {
        list: [
          { key: 'alipay', option_key: 'alipay', name: '支付宝', label: '支付宝', payment_type: 'alipay' },
          { key: 'wechat', option_key: 'wechat', name: '微信支付', label: '微信支付', payment_type: 'wechat' },
          { key: 'epay', option_key: 'epay', name: '易支付', label: '易支付', payment_type: 'epay' },
        ],
      },

      // 财务
      'GET /v2/client/finance/ledger/summary': {
        cash_balance: CUSTOMER.balance,
        total_in: '500.00',
        total_out: '68.00',
        total_count: 2,
        recharge_in: '500.00',
        invoice_payment_out: '68.00',
        refund_in: '0.00',
        manual_adjust_out: '0.00',
        unpaid_count: 1,
        unpaid_amount: '68.00',
        total_invoices: invoiceRecords.length,
        recent_30d_recharge: '500.00',
        recent_30d_refund: '0.00',
      },
      'GET /v2/client/invoices': {
        list: invoiceRecords,
        total: invoiceRecords.length,
        page: 1,
        page_size: 10,
      },
      // 账单列表页的表头汇总（useInvoices 会单独拉一次）
      'GET /v2/client/invoices/summary': {
        total_count: invoiceRecords.length,
        unpaid_count: invoiceRecords.filter((invoice) => Number(invoice.status) === 0).length,
        unpaid_amount: '68.00',
        paid_count: invoiceRecords.filter((invoice) => Number(invoice.status) === 1).length,
        paid_amount: '568.00',
        paid_total: '568.00',
        total_amount: '636.00',
      },
      // 支付记录：两条已支付分别对应余额流水里的「充值 +500」与「续费 -68」，金额与时点一致
      'GET /v2/client/payments': {
        list: paymentRecords,
        total: paymentRecords.length,
        page: 1,
        page_size: 10,
      },
      // 订单：只给两张有服务关联的续费单（充值单不产生订单），与账单 9101 / 9103 一一对应
      'GET /v2/client/orders': {
        list: orderRecords,
        total: orderRecords.length,
        page: 1,
        page_size: 10,
      },
      'GET /v2/client/orders/summary': {
        total: orderRecords.length,
        pending: 1,
        paid: 1,
        processing: 0,
        completed: 1,
        cancelled: 0,
        refunded: 0,
        unpaid_amount: '68.00',
        month_amount: '136.00',
      },
      'GET /v2/client/balance-logs': {
        list: balanceLogs,
        total: balanceLogs.length,
        page: 1,
        page_size: 20,
      },
      'GET /v2/client/coupons/summary': {
        total: CUSTOMER.coupon_count,
        available: CUSTOMER.coupon_count,
        used_up: 0,
        expired: 0,
      },
      // 我的优惠券：1 张可用 + 1 张已过期，合计与 coupon_count（2 张）对得上
      'GET /v2/client/coupons': {
        list: couponRecords,
        total: couponRecords.length,
        page: 1,
        page_size: 10,
      },
      // 领券广场（演示站给同一批券，can_claim=true 表示还能领）
      'GET /v2/client/coupons/public': {
        list: couponRecords.map((coupon) => ({ ...coupon, can_claim: true })),
        total: couponRecords.length,
        page: 1,
        page_size: 10,
      },

      // 内容：公告 / 帮助文章（ContentListPayload：list + categories + total）
      'GET /v2/client/notices': {
        list: noticeArticles,
        categories: [
          { id: 1, name: '站点公告', slug: 'notice', count: 1 },
          { id: 2, name: '维护通知', slug: 'maintenance', count: 1 },
        ],
        page: 1,
        page_size: 5,
        total: noticeArticles.length,
      },
      'GET /v2/client/help-articles': {
        list: helpArticles,
        categories: [
          { id: 1, name: '常见问题', slug: 'faq', count: 1 },
          { id: 2, name: '计费与账单', slug: 'billing', count: 1 },
        ],
        page: 1,
        page_size: 10,
        total: helpArticles.length,
      },
      'GET /v2/client/notices/unread-count': { count: 1 },
      'GET /v2/client/notifications/unread-count': { count: 2 },

      // 内容中心：概览 + 详情（`notices/:id` 必须排在 `notices/unread-count` 之后，
      // 否则 unread-count 会被通配吃掉）
      'GET /v2/client/content/overview': {
        notices: noticeArticles,
        help_articles: helpArticles,
        notice_categories: [
          { id: 1, name: '站点公告', slug: 'notice', count: 1 },
          { id: 2, name: '维护通知', slug: 'maintenance', count: 1 },
        ],
        help_categories: [
          { id: 1, name: '常见问题', slug: 'faq', count: 1 },
          { id: 2, name: '计费与账单', slug: 'billing', count: 1 },
        ],
      },
      'GET /v2/client/notices/:id': noticeArticles[0],
      'GET /v2/client/help-articles/:id': helpArticles[0],

      // 工单
      'GET /v2/client/tickets': {
        list: ticketRecords,
        total: ticketRecords.length,
        page: 1,
        page_size: 10,
      },

      // 推荐奖励
      'GET /v2/client/referral/overview': {
        referral_code: 'DEMO1001',
        register_path: '/register?ref=DEMO1001',
        referral_link: '/register?ref=DEMO1001',
        available_coupons: CUSTOMER.coupon_count,
        reward_rate: '10.00',
        reward_freeze_days: 7,
        withdraw_min_amount: '50.00',
        total_sales_amount: '1286.00',
        referral_frozen_amount: '0.00',
        referral_available_amount: '128.60',
        referral_withdrawing_amount: '0.00',
        referral_withdrawn_amount: '0.00',
        direct_referral_count: 2,
        rewarded_orders_count: 2,
        total_reward_amount: '128.60',
        current_member_level: {
          id: 1,
          name: '普通会员',
          code: 'basic',
          reward_rate: '10.00',
          sales_amount_min: '0.00',
          sales_amount_max: '5000.00',
          distance_amount: '3714.00',
        },
        next_member_level: {
          id: 2,
          name: '白银会员',
          code: 'silver',
          reward_rate: '15.00',
          sales_amount_min: '5000.00',
          sales_amount_max: '20000.00',
          distance_amount: '0.00',
        },
        member_levels: [
          { id: 1, name: '普通会员', code: 'basic', reward_rate: '10.00' },
          { id: 2, name: '白银会员', code: 'silver', reward_rate: '15.00' },
        ],
        recent_referrals: [
          { id: 2001, nickname: '演示客户 A', email: 'demo-a@example.com', referred_at: '2026-10-02 11:20:00' },
          { id: 2002, nickname: '演示客户 B', email: 'demo-b@example.com', referred_at: '2026-10-18 15:45:00' },
        ],
      },

      // 推荐奖励：三张表都从上面 overview 的金额派生，合计都是 128.60
      'GET /v2/client/referral/rewards': {
        list: [
          {
            id: 6201,
            reward_amount: '115.74',
            reward_rate: '10.00',
            order_amount: '1157.40',
            order_type: 'renew',
            status: 1,
            rewarded_at: '2026-10-02 11:20:00',
            available_at: '2026-10-09 11:20:00',
            released_at: '2026-10-09 11:20:00',
            remark: '演示客户 A 续费奖励',
            referred_user: { id: 2001, nickname: '演示客户 A', email: 'demo-a@example.com' },
            invoice: { id: 9103, invoice_no: 'DEMO202610250003', product_display_name: '香港轻量云 2 核 4G' },
          },
          {
            id: 6202,
            reward_amount: '12.86',
            reward_rate: '10.00',
            order_amount: '128.60',
            order_type: 'new',
            status: 1,
            rewarded_at: '2026-10-18 15:45:00',
            available_at: '2026-10-25 15:45:00',
            released_at: '2026-10-25 15:45:00',
            remark: '演示客户 B 新购奖励',
            referred_user: { id: 2002, nickname: '演示客户 B', email: 'demo-b@example.com' },
            invoice: { id: 9102, invoice_no: 'DEMO202610250002', product_display_name: '流量包 100GB' },
          },
        ],
        total: 2,
        page: 1,
        page_size: 10,
      },
      'GET /v2/client/referral/account-logs': {
        list: [
          {
            id: 6301,
            event_type: 'reward_released',
            type: 'reward_released',
            change_amount: '115.74',
            amount: '115.74',
            frozen_balance: '0.00',
            frozen_amount: '0.00',
            available_balance: '115.74',
            available_amount: '115.74',
            pending_withdrawal_balance: '0.00',
            withdrawing_amount: '0.00',
            withdrawn_balance: '0.00',
            withdrawn_amount: '0.00',
            remark: '邀请奖励释放',
            operator: '系统',
            created_at: '2026-10-09 11:20:00',
            user: { id: 2001, nickname: '演示客户 A' },
          },
          {
            id: 6302,
            event_type: 'reward_released',
            type: 'reward_released',
            change_amount: '12.86',
            amount: '12.86',
            frozen_balance: '0.00',
            frozen_amount: '0.00',
            available_balance: '128.60',
            available_amount: '128.60',
            pending_withdrawal_balance: '0.00',
            withdrawing_amount: '0.00',
            withdrawn_balance: '0.00',
            withdrawn_amount: '0.00',
            remark: '邀请奖励释放',
            operator: '系统',
            created_at: '2026-10-25 15:45:00',
            user: { id: 2002, nickname: '演示客户 B' },
          },
        ],
        total: 2,
        page: 1,
        page_size: 10,
      },
      // 一笔被驳回的提现：不影响 overview 的「待提现 / 已提现」金额（都为 0.00）
      'GET /v2/client/referral/withdrawals': {
        list: [
          {
            id: 6401,
            amount: '100.00',
            method: 'alipay',
            account_name: '演示客户',
            account_no: 'demo-pay@example.com',
            status: 2,
            remark: '演示数据：支付宝账号信息不完整，已驳回',
            created_at: '2026-10-26 10:02:00',
            processed_at: '2026-10-27 09:30:00',
          },
        ],
        total: 1,
        page: 1,
        page_size: 10,
      },
      'GET /v2/client/referral/direct-referrals': {
        list: [
          {
            id: 2001,
            nickname: '演示客户 A',
            email: 'demo-a@example.com',
            created_at: '2026-09-30 11:20:00',
            referred_at: '2026-10-02 11:20:00',
          },
          {
            id: 2002,
            nickname: '演示客户 B',
            email: 'demo-b@example.com',
            created_at: '2026-10-16 15:45:00',
            referred_at: '2026-10-18 15:45:00',
          },
        ],
        total: 2,
        page: 1,
        page_size: 10,
      },

      // 开发者：开放接口密钥（演示站只读展示，不提供真实密钥）
      'GET /v2/client/api-keys': {
        list: [
          {
            id: 7001,
            name: '演示密钥',
            key_prefix: 'demo_key_',
            secret_last4: 'a1b2',
            scopes: { services: 'read', invoices: 'read' },
            expires_at: null,
            ip_allowlist: [],
            status: 'enabled',
            last_used_at: '2026-11-08 20:14:03',
            created_at: '2026-09-12 10:00:00',
          },
        ],
      },

      // AI 助手：演示站直接关掉（走原生 fetch，transport 的 fetch 桥接也会命中这条）
      'GET /v2/client/ai/bootstrap': {
        enabled: false,
        quota: null,
        tools: [],
        allowed_permission_modes: ['read_only', 'confirm'],
      },
    },
    refused: {
      'DELETE /v2/client/services/:id': { message: '演示环境不执行删除实例' },
      'POST /v2/client/services/:id/reinstalls': { message: '演示环境不执行重装系统' },
      'POST /v2/client/services/:id/password': { message: '演示环境不执行重置密码' },
      'POST /v2/client/auto-refund/applications': { message: '演示环境不执行退款申请' },
    },
  };
}
