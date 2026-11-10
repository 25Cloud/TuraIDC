/**
 * 演示站基础数据：三端共享同一套「客户 / 站点 / 实例 / 账单 / 工单」。
 *
 * 所有示例值只在这里定义一次，各端 fixture 从它派生——这样同一个客户名、同一张账单
 * 在用户端、控制台、管理端里说的是同一件事，而不是三份互相对不上的假数据。
 */

export const CUSTOMER = {
  id: 1001,
  nickname: '演示客户',
  username: 'demo',
  email: 'demo@example.com',
  phone: '13800000000',
  real_name: '演示客户',
  balance: '1286.40',
  credit_limit: '0.00',
  coupon_count: 2,
  created_at: '2025-11-18 10:24:11',
};

/** 站点公开配置：三端首屏都会拉，品牌色与站点名统一走这里 */
export const SITE_CONFIG = {
  site_name: 'TuraIDC 演示站',
  site_logo: '',
  site_favicon: '',
  brand_primary_color: '#0052d9',
  brand_radius: '6px',
  captcha_enabled: 'false',
  register_enabled: 'true',
};

/** 管理端会话：全权限（`*`），可选功能（AI / 自助退款）关掉，免得演示站里点进空页面 */
export const ADMIN_SESSION = {
  admin: {
    id: 1,
    username: 'admin',
    nickname: '演示管理员',
    email: 'admin@example.com',
    role: '超级管理员',
    permissions: ['*'],
    avatar: '',
  },
  features: {
    ai_assistant: false,
    auto_refund: false,
  },
};

/** 演示环境的两套登录凭据，登录页与 /start/ 说明页共用这一份 */
export const DEMO_ACCOUNTS = {
  client: { username: 'demo', password: 'demo123456' },
  admin: { username: 'admin', password: 'demo123456' },
};

export const SERVICES = [
  {
    id: 5101,
    name: '演示香港-轻量云',
    hostname: 'demo-hk-01.example.com',
    product_name: '香港轻量云 2 核 4G',
    status: 'active',
    status_label: '运行中',
    os: 'Debian 12',
    ip: '203.0.113.10',
    cpu: 2,
    memory: 4096,
    disk: 60,
    bandwidth: 30,
    traffic_used: 182,
    traffic_limit: 1000,
    monthly_price: '68.00',
    billing_cycle: 'monthly',
    expires_at: '2026-12-18 00:00:00',
  },
  {
    id: 5102,
    name: '演示日本-独服',
    hostname: 'demo-jp-01.example.com',
    product_name: '日本独立服务器 E5-2650',
    status: 'active',
    status_label: '运行中',
    os: 'Ubuntu 24.04',
    ip: '198.51.100.24',
    cpu: 16,
    memory: 32768,
    disk: 960,
    bandwidth: 100,
    traffic_used: 3120,
    traffic_limit: 10000,
    monthly_price: '899.00',
    billing_cycle: 'monthly',
    expires_at: '2026-11-30 00:00:00',
  },
  {
    id: 5103,
    name: '演示新加坡-测试机',
    hostname: 'demo-sg-01.example.com',
    product_name: '新加坡云服务器 1 核 2G',
    status: 'suspended',
    status_label: '已暂停',
    os: 'AlmaLinux 9',
    ip: '192.0.2.66',
    cpu: 1,
    memory: 2048,
    disk: 40,
    bandwidth: 10,
    traffic_used: 96,
    traffic_limit: 500,
    monthly_price: '29.00',
    billing_cycle: 'monthly',
    expires_at: '2026-11-25 00:00:00',
  },
];

/** 首页 / 商品页的商品目录：与实例一一对应，客户看到的就是自己买过的那几款 */
export const PRODUCTS = [
  {
    id: 3201,
    name: '香港轻量云 2 核 4G',
    display_name: '香港轻量云 2 核 4G',
    product_type: 'cloud_server',
    group_name: '云服务器',
    region: '香港',
    price: '68.00',
    price_unit: '月起',
    stock: -1,
    summary: '三网 CN2 优化线路，30Mbps 峰值带宽，适合建站与跨境业务',
    tags: ['CN2', '建站首选'],
  },
  {
    id: 3202,
    name: '日本独立服务器 E5-2650',
    display_name: '日本独立服务器 E5-2650',
    product_type: 'dedicated',
    group_name: '独立服务器',
    region: '日本',
    price: '899.00',
    price_unit: '月起',
    stock: 6,
    summary: '16 核 32G / 960G SSD / 100Mbps 独享，可加 IP 段',
    tags: ['独服', '大带宽'],
  },
  {
    id: 3203,
    name: '新加坡云服务器 1 核 2G',
    display_name: '新加坡云服务器 1 核 2G',
    product_type: 'cloud_server',
    group_name: '云服务器',
    region: '新加坡',
    price: '29.00',
    price_unit: '月起',
    stock: -1,
    summary: '东南亚节点，10Mbps 带宽，适合测试与轻量业务',
    tags: ['东南亚', '测试机'],
  },
  {
    id: 3204,
    name: '流量包 100GB',
    display_name: '流量包 100GB',
    product_type: 'traffic_package',
    group_name: '增值服务',
    region: '通用',
    price: '15.00',
    price_unit: '个',
    stock: -1,
    summary: '当月有效，可用于任意云服务器实例',
    tags: ['流量包'],
  },
];

export const INVOICES = [
  {
    id: 9101,
    invoice_no: 'DEMO202611090001',
    type: 'renew',
    type_label: '续费',
    status: 0,
    status_label: '待支付',
    amount: '68.00',
    due_date: '2026-11-20 00:00:00',
    created_at: '2026-11-09 09:12:00',
    items: [{ description: '演示香港-轻量云 续费 1 个月', amount: '68.00' }],
  },
  {
    id: 9102,
    invoice_no: 'DEMO202610250002',
    type: 'recharge',
    type_label: '充值',
    status: 1,
    status_label: '已支付',
    amount: '500.00',
    paid_at: '2026-10-25 21:03:11',
    created_at: '2026-10-25 21:01:52',
    items: [{ description: '账户充值', amount: '500.00' }],
  },
];

export const TICKETS = [
  {
    id: 7101,
    title: '演示工单：如何调整实例带宽',
    status: 'open',
    status_label: '待处理',
    priority: 'normal',
    department: '技术支持',
    created_at: '2026-11-08 14:20:00',
    updated_at: '2026-11-08 14:20:00',
    replies: 0,
  },
  {
    id: 7102,
    title: '演示工单：发票抬头修改',
    status: 'replied',
    status_label: '已回复',
    priority: 'low',
    department: '财务',
    created_at: '2026-11-03 10:02:00',
    updated_at: '2026-11-04 09:31:00',
    replies: 2,
  },
];
