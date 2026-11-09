#!/usr/bin/env node
/**
 * 演示站示例数据生成器。
 *
 * 为什么不手写散装 JSON：示例数据必须内部自洽——同一个客户名要同时出现在实例、账单、
 * 工单里，到期时间要跟账期对得上，金额要跟订单明细合得上。手写三份 JSON 一分钟就漂移，
 * 所以这里用一套基础数据（客户 / 产品 / 实例 / 账单 / 工单）派生出三端各自的 fixture。
 *
 * 产物：demo/fixtures/{console,admin,user}.json
 * 键是 `METHOD /path`，路径允许 `:id` 通配；值是业务 `data`（传输层自己套 {code,message,data}）。
 *
 * 用法：node demo/fixtures/generate.mjs [--check]
 *   --check  只校验产物是否与生成结果一致（CI 用），不写文件
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const checkOnly = process.argv.includes('--check');

/* ------------------------------------------------------------------ *
 * 基础数据：所有示例值都从这里派生，避免三端对不上
 * ------------------------------------------------------------------ */

const CUSTOMER = {
  id: 1001,
  nickname: '演示客户',
  email: 'demo@example.com',
  phone: '13800000000',
  real_name: '演示客户',
  balance: '1286.40',
  credit_limit: '0.00',
  created_at: '2025-11-18 10:24:11',
};

const SERVICES = [
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

const INVOICES = [
  {
    id: 9101,
    invoice_no: 'DEMO202611010001',
    type: 'renew',
    type_label: '续费',
    status: 'unpaid',
    status_label: '未支付',
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
    status: 'paid',
    status_label: '已支付',
    amount: '500.00',
    paid_at: '2026-10-25 21:03:11',
    created_at: '2026-10-25 21:01:52',
    items: [{ description: '账户充值', amount: '500.00' }],
  },
];

const TICKETS = [
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

/* ------------------------------------------------------------------ *
 * 派生：三端 fixture
 * ------------------------------------------------------------------ */

/** 控制台（用户中心） */
function consoleFixtures() {
  return {
    endpoints: {},
    /**
     * 演示站不执行的动作：命中即返回业务错误，让页面走自己的错误提示链路，
     * 比「假装成功」更接近真实系统，也避免访客以为真删掉了什么。
     */
    refused: {
      'DELETE /v2/client/services/:id': { message: '演示环境不执行删除实例' },
      'POST /v2/client/services/:id/reinstalls': { message: '演示环境不执行重装系统' },
      'POST /v2/client/services/:id/password': { message: '演示环境不执行重置密码' },
      'POST /v2/client/auto-refund/applications': { message: '演示环境不执行退款申请' },
    },
  };
}

/** 管理端 */
function adminFixtures() {
  return {
    endpoints: {},
    refused: {
      'DELETE /v2/admin/users/:id': { message: '演示环境不执行删除用户' },
      'POST /v2/admin/services/:id/refunds': { message: '演示环境不执行退款' },
    },
  };
}

/** 用户端（官网 / 下单） */
function userFixtures() {
  return {
    endpoints: {},
    refused: {
      'POST /v2/client/orders': { message: '演示环境不执行下单' },
    },
  };
}

/* ------------------------------------------------------------------ *
 * 写盘
 * ------------------------------------------------------------------ */

const outputs = [
  { file: 'console.json', payload: consoleFixtures() },
  { file: 'admin.json', payload: adminFixtures() },
  { file: 'user.json', payload: userFixtures() },
];

let drifted = 0;

for (const output of outputs) {
  const target = resolve(here, output.file);
  const serialized = `${JSON.stringify(output.payload, null, 2)}\n`;

  if (checkOnly) {
    const current = (() => {
      try {
        return readFileSync(target, 'utf8');
      } catch {
        return '';
      }
    })();

    if (current !== serialized) {
      console.error(`fixture 与生成器不一致：${output.file}`);
      drifted += 1;
    }
    continue;
  }

  writeFileSync(target, serialized, 'utf8');
}

if (checkOnly) {
  if (drifted > 0) {
    console.error(`共 ${drifted} 个 fixture 需要重新生成：node demo/fixtures/generate.mjs`);
    process.exit(1);
  }

  console.log('演示站 fixture 与生成器一致。');
  process.exit(0);
}

console.log(`演示站 fixture 已生成：${outputs.map((item) => item.file).join(', ')}`);
console.log(`基础数据：${SERVICES.length} 台实例 / ${INVOICES.length} 张账单 / ${TICKETS.length} 个工单 / 客户 ${CUSTOMER.nickname}`);
