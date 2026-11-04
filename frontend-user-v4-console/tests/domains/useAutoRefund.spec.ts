import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import ts from 'typescript';

/**
 * 退款中心领域逻辑（`src/domains/finance/useAutoRefund.ts`）。
 *
 * 该文件依赖 vue / tdesign / api / locales，本测试用最小桩替换导入，
 * 语言包直接读真实 `zh_CN.json`：这样既验证枚举→中文的映射，
 * 也验证四份 locale 里这些键真的存在（缺键会退回枚举码，断言立刻变红）。
 */

const require = createRequire(import.meta.url);
const sourcePath = new URL('../../src/domains/finance/useAutoRefund.ts', import.meta.url);
const localePath = new URL('../../src/locales/lang/zh_CN.json', import.meta.url);

const source = readFileSync(sourcePath, 'utf8');
const messages = JSON.parse(readFileSync(localePath, 'utf8'));

/** vue / tdesign / api / locales / format 的最小桩，名字与源文件导入保持一致 */
const stub = String.raw`
const computed = (getter) => ({ get value() { return getter(); } });
const onMounted = () => {};
const reactive = (value) => value;
const ref = (value) => ({ value });
const shallowRef = (value) => ({ value });
const useRouter = () => ({ push() {} });
const clientApi = {};
const DialogPlugin = { alert() {}, confirm() { return { hide() {}, setConfirmLoading() {} }; } };
const MessagePlugin = { error() {}, success() {}, warning() {} };
const formatMoney = (value) => {
  const amount = Number(value ?? 0);
  return Number.isFinite(amount) ? amount.toFixed(2) : '0.00';
};
const t = (key, params) => {
  const raw = String(key)
    .split('.')
    .reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), messages);
  if (typeof raw !== 'string') return key;
  return raw.replace(/\{(\w+)\}/g, (whole, name) =>
    params && Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : whole,
  );
};
`;

const body = source.replace(
  /^[\s\S]*?export const AUTO_REFUND_REASON_MAX_LENGTH/,
  'export const AUTO_REFUND_REASON_MAX_LENGTH',
);

const { outputText } = ts.transpileModule(`${stub}\n${body}`, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
});

const module = { exports: {} as Record<string, unknown> };
new Function('exports', 'require', 'module', 'messages', outputText)(module.exports, require, module, messages);

type Fn = (...args: unknown[]) => unknown;

const exported = module.exports as Record<string, Fn>;
const call = (fn: Fn, ...args: unknown[]): unknown => fn(...args);

const {
  autoRefundBlockedReasonLabel,
  autoRefundChannelLabel,
  autoRefundRequirementLabel,
  isAutoRefundCandidateBlocked,
  isAutoRefundPolicyConfigured,
  resolveAutoRefundAmountText,
  resolveAutoRefundBlockedReasonCodes,
  resolveAutoRefundPolicyLines,
  resolveAutoRefundPolicyText,
  resolveAutoRefundSubmitResultMessage,
  resolveAutoRefundTicketId,
  resolveAutoRefundUpstreamTicketText,
} = exported;

// blocked_reasons：原有枚举必须继续有中文，新增七个枚举逐个落到中文且不是枚举码
const blockedLabels: Record<string, string> = {};
for (const code of [
  'upstream_missing',
  'no_paid_invoice',
  'window_expired',
  'first_only_used',
  'pending_application',
  'amount_too_low',
  'upstream_unavailable',
  'rule_missing',
  'renewed',
  'upgraded',
  'free_order',
  'service_status',
  'expired',
  'unpaid',
]) {
  const label = String(call(autoRefundBlockedReasonLabel, code));
  blockedLabels[code] = label;
  assert.notEqual(label, code, `blocked_reasons.${code} 缺少中文文案`);
  assert.ok(label.length > 0, `blocked_reasons.${code} 文案为空`);
}

assert.equal(blockedLabels.rule_missing, '该商品未开放自助退款');
assert.equal(blockedLabels.renewed, '已续费的实例不支持自助退款');
assert.equal(blockedLabels.upgraded, '发生过升降级的实例不支持自助退款');
assert.equal(blockedLabels.free_order, '免费订单不支持自助退款');
assert.equal(blockedLabels.service_status, '当前服务状态不支持自助退款');
assert.equal(blockedLabels.expired, '实例已过期');
assert.equal(blockedLabels.unpaid, '订单尚未支付');
// 未知枚举退回枚举码本身，不显示 i18n key 路径
assert.equal(call(autoRefundBlockedReasonLabel, 'brand_new_reason'), 'brand_new_reason');
assert.equal(call(autoRefundBlockedReasonLabel, ''), '');

// policy_configured=false 时前端自己补出 rule_missing，且不与后端给的重复
assert.deepEqual(call(resolveAutoRefundBlockedReasonCodes, { policy_configured: false, blocked_reasons: [] }), [
  'rule_missing',
]);
assert.deepEqual(
  call(resolveAutoRefundBlockedReasonCodes, { policy_configured: false, blocked_reasons: ['renewed'] }),
  ['rule_missing', 'renewed'],
);
assert.deepEqual(
  call(resolveAutoRefundBlockedReasonCodes, { policy_configured: false, blocked_reasons: ['rule_missing'] }),
  ['rule_missing'],
);
// 后端没下发 policy_configured 时只认 blocked_reasons，不额外拦截
assert.deepEqual(call(resolveAutoRefundBlockedReasonCodes, { blocked_reasons: [] }), []);

assert.equal(call(isAutoRefundPolicyConfigured, { policy_configured: false }), false);
assert.equal(call(isAutoRefundPolicyConfigured, { policy_configured: 0 }), false);
assert.equal(call(isAutoRefundPolicyConfigured, { policy_configured: 1 }), true);
assert.equal(call(isAutoRefundPolicyConfigured, { policy_configured: true, blocked_reasons: ['rule_missing'] }), false);
assert.equal(call(isAutoRefundPolicyConfigured, null), true);

// 按钮禁用判定：refundable=false 或任一拦截原因都要禁用
assert.equal(call(isAutoRefundCandidateBlocked, { refundable: true, blocked_reasons: [] }), false);
assert.equal(call(isAutoRefundCandidateBlocked, { refundable: false, blocked_reasons: [] }), true);
assert.equal(call(isAutoRefundCandidateBlocked, { refundable: true, blocked_reasons: ['renewed'] }), true);
assert.equal(call(isAutoRefundCandidateBlocked, { refundable: true, policy_configured: false }), true);

// 候选行的策略摘要：后端给的 *_label 优先，缺了按枚举码本地化
const policyRow = {
  refund_type: 'bridge',
  refund_type_label: '插件间对接',
  refund_rule: 'duration',
  refund_rule_label: '按时长折算',
  requirement: 'product_first',
  requirement_label: '产品首次',
  window_hours: 0,
  policy_configured: true,
};
assert.equal(call(resolveAutoRefundPolicyText, policyRow), '插件间对接 · 按时长折算 · 产品首次');
assert.equal(
  call(resolveAutoRefundPolicyText, { refund_type: 'bridge', refund_rule: 'duration', requirement: 'product_first' }),
  '插件间对接 · 按时长折算 · 产品首次',
);
assert.equal(call(resolveAutoRefundPolicyText, { ...policyRow, policy_configured: false }), '该商品未开放自助退款');
assert.equal(
  call(resolveAutoRefundPolicyText, {
    refund_type: 'api_ticket',
    refund_rule: 'month',
    requirement: 'within_hours',
    window_hours: 72,
  }),
  'API 工单退款 · 按月折算 · 指定时间内（72 小时内）',
);
// 非 within_hours 场景后端会下发 0；within_hours 又是 0 表示不限，不能写成「（0 小时内）」
assert.equal(call(autoRefundRequirementLabel, 'within_hours', '', 0), '指定时间内（不限）');
assert.equal(call(autoRefundRequirementLabel, 'within_hours', '', null), '指定时间内（不限）');
assert.equal(call(autoRefundRequirementLabel, 'product_first', '', 0), '产品首次');
assert.equal(call(resolveAutoRefundPolicyText, { policy_configured: true }), '--');

// 实际通道只有三个取值：bridge 上游接口 / api_ticket 上游工单 / local 仅本站
assert.equal(call(autoRefundChannelLabel, 'bridge'), '上游接口');
assert.equal(call(autoRefundChannelLabel, 'api_ticket'), '上游工单');
assert.equal(call(autoRefundChannelLabel, 'local'), '仅本站');
assert.equal(call(autoRefundChannelLabel, 'bridge', '自定义通道'), '自定义通道');
assert.equal(call(autoRefundChannelLabel, ''), '--');

// 退款类型决定话术；`status=settled` 优先于类型（settled 就是钱已经到余额了）
assert.equal(call(resolveAutoRefundSubmitResultMessage, 'auto', 'settled'), '已退款到余额');
assert.equal(call(resolveAutoRefundSubmitResultMessage, 'manual', 'pending_review'), '已提交申请，等待管理员审核');
assert.equal(call(resolveAutoRefundSubmitResultMessage, 'manual', undefined), '已提交申请，等待管理员审核');
assert.equal(call(resolveAutoRefundSubmitResultMessage, 'api_ticket', 'pending_review'), '已向上游提交工单，等待处理');
assert.equal(call(resolveAutoRefundSubmitResultMessage, 'bridge', 'pending_review'), '已提交上游审核');
assert.equal(call(resolveAutoRefundSubmitResultMessage, 'bridge', 'settled'), '已退款到余额');
assert.equal(call(resolveAutoRefundSubmitResultMessage, '', 'settled'), '已退款到余额');
assert.equal(call(resolveAutoRefundSubmitResultMessage, '', ''), '退款申请已提交，请等待审核');
assert.equal(call(resolveAutoRefundSubmitResultMessage, 'unknown', 'pending_review'), '退款申请已提交，请等待审核');

// 工单两列：本站工单 id 可跳转，上游工单号为空显示 `-`
assert.equal(call(resolveAutoRefundTicketId, { ticket_id: 321 }), 321);
assert.equal(call(resolveAutoRefundTicketId, { ticket_id: '321' }), 321);
assert.equal(call(resolveAutoRefundTicketId, { ticket_id: null }), 0);
assert.equal(call(resolveAutoRefundTicketId, {}), 0);
assert.equal(call(resolveAutoRefundUpstreamTicketText, { upstream_ticket_id: 'UP-9' }), 'UP-9');
assert.equal(call(resolveAutoRefundUpstreamTicketText, { upstream_ticket_id: null }), '-');
assert.equal(call(resolveAutoRefundUpstreamTicketText, {}), '-');

// bootstrap 的 policy 只当说明用：天数是旧口径，必须被忽略
const policyLines = call(resolveAutoRefundPolicyLines, {
  policy: { mode: 'duration', mode_label: '按时长折算', days: 7, min_amount: '10.00', text: '通用说明' },
}) as string[];
assert.deepEqual(policyLines, ['通用说明', '最低可退金额：¥10.00']);
assert.ok(
  policyLines.every((line) => !line.includes('开通后')),
  'policy.days 不应再被渲染成「开通后 X 天内」',
);
assert.deepEqual(call(resolveAutoRefundPolicyLines, { policy: '纯文本说明' }), ['纯文本说明']);
assert.deepEqual(call(resolveAutoRefundPolicyLines, { policy: null }), []);

// 金额展示：空值与占位符不能显示成 `¥0.00`（未配策略的行本来就没有预估金额）
assert.equal(call(resolveAutoRefundAmountText, '45.20'), '¥45.20');
assert.equal(call(resolveAutoRefundAmountText, 45.2), '¥45.20');
assert.equal(call(resolveAutoRefundAmountText, null), '--');
assert.equal(call(resolveAutoRefundAmountText, ''), '--');
assert.equal(call(resolveAutoRefundAmountText, '--'), '--');

// 未被断言但由页面使用的导出必须存在，避免改名后页面静默白屏
for (const name of [
  'autoRefundStatusLabel',
  'autoRefundStatusTheme',
  'canCancelApplication',
  'isUncertainStatus',
  'resolveBlockedReasonLabels',
  'resolveAutoRefundServiceTitle',
  'resolveAutoRefundAddress',
  'resolveAutoRefundSubmittedAt',
  'resolveAutoRefundApplicationNo',
  'useAutoRefundCenter',
]) {
  assert.equal(typeof exported[name], 'function', `缺少导出 ${name}`);
}

console.log('useAutoRefund domain tests passed');
