import { request } from '@/utils/request';

/**
 * 自助退款（`addons/auto_refund`）管理端接口。
 *
 * 契约唯一真源：`docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md`
 * 的「管理端 API」一节。插件未启用/已卸载时 `/api/v2/admin/auto-refund/*` 整体 404，
 * 入口由路由 `meta.requiresFeature: 'auto_refund'` 与侧栏分组收敛（与 AI 助手同款做法）。
 *
 * 归一化说明：契约钉死了「展示哪些字段」，但没有逐字段钉死 JSON 结构，且后端仍在并行落地。
 * 这里把常见别名收口成一套稳定视图模型，避免后端换个键名就让整页变成空白；
 * 后端定稿后如果字段完全一致，这些兼容分支自然失效，不需要页面跟着改。
 */

/** 归属用户/操作管理员的归一化形态（管理端不脱敏，字段原样展示） */
export interface AutoRefundActor {
  id: number | null;
  email: string;
  nickname: string;
  display_name: string;
  phone: string;
}

/** 台账列表/详情的一行（对应数据表 `auto_refund_applications`） */
export interface AutoRefundApplication {
  id: number;
  application_no: string;
  status: string;
  /** 商品退款类型快照：manual / auto / api_ticket / bridge（第二阶段按商品配置） */
  refund_type: string;
  /** 后端给的类型文案；为空时前端按 refundTypes 本地化兜底 */
  refund_type_label: string;
  /** 实际走的通道：bridge 上游接口 / api_ticket 上游工单 / local 仅本站 */
  channel: string;
  channel_label: string;
  /** `api_ticket` 通道生成的本站工单，其它通道为 null */
  ticket_id: number | null;
  /** 上游工单号：zjfm 协议只回 302 列表页，拿不到时为空 */
  upstream_ticket_id: string;
  /** 提交那一刻的商品策略快照（契约里是 JSON 字符串），页面格式化后展示 */
  policy_snapshot: unknown;
  user_id: number | null;
  user: AutoRefundActor | null;
  service_id: number | null;
  /** 实例名（优先本地实例名，退回产品名），列表「实例/产品」列直接用 */
  service_name: string;
  product_name: string;
  order_id: number | null;
  invoice_id: number | null;
  upstream_product_id: string;
  reason: string;
  /** 金额保留两位小数字符串，空值给空串，由页面决定显示 '-' */
  estimated_amount: string;
  settled_amount: string;
  active_service_id: number | null;
  upstream_code: number | null;
  upstream_msg: string;
  trace_id: string;
  /** 脱敏后的请求/响应摘要，键名不固定，页面按 JSON 原文展示 */
  response_meta: unknown;
  submitted_at: string;
  cancelled_at: string;
  settled_at: string;
  operator_id: number | null;
  operator: AutoRefundActor | null;
  settle_remark: string;
  created_at: string;
  updated_at: string;
}

export interface AutoRefundApplicationListParams {
  page?: number;
  page_size?: number;
  status?: string;
  /** 退款类型筛选：manual / auto / api_ticket / bridge */
  refund_type?: string;
  /** 通道筛选：bridge / api_ticket / local */
  channel?: string;
  /** 用户关键字（申请单号 / 用户邮箱 / 昵称由后端一并匹配） */
  keyword?: string;
  start_date?: string;
  end_date?: string;
}

export interface AutoRefundApplicationListResponse {
  list: AutoRefundApplication[];
  total: number;
  page: number;
  page_size: number;
}

/**
 * 商品退款策略的一行（对应 `auto_refund_product_rules`，一行一个商品）。
 *
 * 列表同时包含「已配置」与「未配置」的商品：未配置的商品也要能在这里就地建策略，
 * 否则运营得先记住商品 ID 才能配置。`configured=false` 时其余策略字段全是占位值。
 */
export interface AutoRefundProductRule {
  product_id: number;
  product_name: string;
  /** 一级商品分组名（契约里「同类产品首次」按它判定）；商品没有分组时为空串 */
  group_name: string;
  /** manual 人工审核 / auto 自动退款 / api_ticket API 工单退款 / bridge 插件间对接 */
  refund_type: string;
  /** duration 按时长 / month 按月 / full 全额 */
  refund_rule: string;
  /** product_first 产品首次 / category_first 同类产品首次 / within_hours 指定时间内 */
  requirement: string;
  /** 小时，仅 requirement=within_hours 生效；未配置时可能是 null */
  window_hours: number | null;
  enabled: boolean;
  /** 是否已有策略行 */
  configured: boolean;
  updated_at: string;
}

export interface AutoRefundProductRuleListParams {
  page?: number;
  page_size?: number;
  /** 商品 ID / 商品名 / 分组名关键字（后端一并匹配） */
  keyword?: string;
  /** 配置状态筛选：all / configured / unconfigured */
  status?: string;
}

export interface AutoRefundProductRuleListResponse {
  list: AutoRefundProductRule[];
  total: number;
  page: number;
  page_size: number;
}

/** `PUT /product-rules/{product}` 的请求体，键名由契约钉死，不要改名 */
export interface AutoRefundProductRulePayload {
  refund_type: string;
  refund_rule: string;
  requirement: string;
  window_hours: number;
  enabled: boolean;
}

export interface AutoRefundSettlePayload {
  amount: number;
  remark?: string;
}

export interface AutoRefundRemarkPayload {
  remark: string;
}

/** 「测试上游连接」结果：机器数 + 上游服务器时间与本地时间差 */
export interface AutoRefundUpstreamTestResult {
  ok: boolean;
  /** 后端结论文案原文（信封 message 或 data.message） */
  message: string;
  machine_count: number | null;
  /** 上游服务器时间原文，不做格式改写 */
  server_time: string;
  /** 上游服务器时间戳（Unix 秒），拿不到为 null */
  server_timestamp: number | null;
  /** 与本地时间的差值（秒）：正数表示上游比本地快 */
  time_offset_seconds: number | null;
  latency_ms: number | null;
}

/** 上游机器对账的一行：上游全量机器 + 本地绑定情况 */
export interface AutoRefundUpstreamProduct {
  hostid: string;
  product_name: string;
  domain: string;
  ip: string;
  next_due_date: string;
  service_id: number | null;
  service_name: string;
  user_id: number | null;
  user: AutoRefundActor | null;
  bound: boolean;
}

export interface AutoRefundUpstreamProducts {
  list: AutoRefundUpstreamProduct[];
  total: number;
  bound: number;
  unbound: number;
  /** 上游不可用时后端给的说明（契约里 `/candidates` 同款降级字段） */
  upstream_error: string;
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

function toText(value: unknown): string {
  if (value === null || value === undefined) return '';
  return typeof value === 'string' ? value : String(value);
}

/** 首个非空文本：契约没钉死字段位置，实例名可能是 service.name 也可能是平铺的 service_name */
function firstText(...values: unknown[]): string {
  for (const value of values) {
    const text = toText(value).trim();
    if (text !== '') return text;
  }

  return '';
}

function toNullableNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === '') return null;
  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : null;
}

function toNumber(value: unknown, fallback = 0): number {
  return toNullableNumber(value) ?? fallback;
}

/** 金额统一成两位小数字符串；非数字原样返回，交给页面显示原文 */
function toAmountText(value: unknown): string {
  if (value === null || value === undefined || value === '') return '';
  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed.toFixed(2) : toText(value);
}

function toBool(value: unknown): boolean {
  if (typeof value === 'boolean') return value;
  if (value === 1 || value === '1' || value === 'true') return true;

  return false;
}

function normalizeActor(value: unknown): AutoRefundActor | null {
  if (!value || typeof value !== 'object') return null;
  const record = toRecord(value);

  return {
    id: toNullableNumber(record.id),
    email: toText(record.email),
    nickname: toText(record.nickname),
    display_name: toText(record.display_name ?? record.displayName),
    phone: toText(record.phone),
  };
}

/** 列表接口可能给 {list}/{items}/{rows}，也可能直接给数组 */
function resolveRowList(value: unknown): Array<Record<string, unknown>> {
  if (Array.isArray(value)) return value as Array<Record<string, unknown>>;

  const record = toRecord(value);
  for (const key of ['list', 'items', 'rows', 'products', 'data']) {
    if (Array.isArray(record[key])) return record[key] as Array<Record<string, unknown>>;
  }

  return [];
}

export function normalizeApplication(raw: Record<string, unknown>): AutoRefundApplication {
  const service = toRecord(raw.service);
  const product = toRecord(raw.product);
  const operator = toRecord(raw.operator);

  return {
    id: toNumber(raw.id),
    application_no: toText(raw.application_no),
    status: toText(raw.status),
    refund_type: toText(raw.refund_type),
    refund_type_label: toText(raw.refund_type_label),
    channel: toText(raw.channel),
    channel_label: toText(raw.channel_label),
    ticket_id: toNullableNumber(raw.ticket_id),
    upstream_ticket_id: toText(raw.upstream_ticket_id),
    policy_snapshot: raw.policy_snapshot ?? null,
    user_id: toNullableNumber(raw.user_id ?? toRecord(raw.user).id),
    user: normalizeActor(raw.user),
    service_id: toNullableNumber(raw.service_id),
    service_name: firstText(
      service.service_name,
      service.name,
      raw.service_name,
      product.name,
      product.product_name,
      raw.product_name,
    ),
    product_name: firstText(
      product.name,
      product.product_name,
      raw.product_name,
      service.product_name,
      raw.service_name,
    ),
    order_id: toNullableNumber(raw.order_id),
    invoice_id: toNullableNumber(raw.invoice_id),
    upstream_product_id: toText(raw.upstream_product_id),
    reason: toText(raw.reason),
    estimated_amount: toAmountText(raw.estimated_amount),
    settled_amount: toAmountText(raw.settled_amount),
    active_service_id: toNullableNumber(raw.active_service_id),
    upstream_code: toNullableNumber(raw.upstream_code),
    upstream_msg: toText(raw.upstream_msg),
    trace_id: toText(raw.trace_id),
    response_meta: raw.response_meta ?? null,
    submitted_at: toText(raw.submitted_at),
    cancelled_at: toText(raw.cancelled_at),
    settled_at: toText(raw.settled_at),
    operator_id: toNullableNumber(raw.operator_id ?? operator.id),
    operator: normalizeActor(raw.operator),
    settle_remark: toText(raw.settle_remark),
    created_at: toText(raw.created_at),
    updated_at: toText(raw.updated_at),
  };
}

function normalizeApplicationPage(raw: unknown): AutoRefundApplicationListResponse {
  const record = toRecord(raw);
  const list = resolveRowList(raw).map(normalizeApplication);

  return {
    list,
    total: toNumber(record.total, list.length),
    page: toNumber(record.page, 1),
    page_size: toNumber(record.page_size, list.length),
  };
}

function normalizeApplicationDetail(raw: unknown): AutoRefundApplication {
  const envelope = toRecord(raw);
  // 后端可能包一层 {application}，也可能直接给申请行
  const source =
    envelope.application && typeof envelope.application === 'object' ? toRecord(envelope.application) : envelope;

  return normalizeApplication(source);
}

/**
 * 连通性测试走原始信封（isTransformResponse: false）：
 * 后端把中文结论放在信封 message 里，只取 data 会把它丢掉，
 * 而这段结论正是要原样展示给管理员的内容。
 */
function normalizeUpstreamTest(raw: unknown): AutoRefundUpstreamTestResult {
  const envelope = toRecord(raw);
  const payload = toRecord(envelope.data && typeof envelope.data === 'object' ? envelope.data : envelope);

  return {
    ok: toBool(payload.ok ?? payload.success ?? payload.connected),
    message: firstText(payload.message, envelope.message, payload.detail),
    machine_count: toNullableNumber(
      payload.host_count ??
        payload.machine_count ??
        payload.product_count ??
        payload.products_count ??
        payload.count ??
        payload.total,
    ),
    server_time: firstText(
      payload.upstream_server_time,
      payload.server_time,
      payload.upstream_time,
      payload.remote_time,
      payload.server_datetime,
    ),
    server_timestamp: toNullableNumber(payload.server_timestamp ?? payload.timestamp),
    time_offset_seconds: toNullableNumber(
      payload.skew_seconds ??
        payload.time_offset_seconds ??
        payload.time_offset ??
        payload.time_diff ??
        payload.timestamp_offset ??
        payload.server_time_offset,
    ),
    latency_ms: toNullableNumber(payload.latency_ms ?? payload.elapsed_ms ?? payload.duration_ms),
  };
}

function normalizeUpstreamProduct(raw: Record<string, unknown>): AutoRefundUpstreamProduct {
  const service = toRecord(raw.service);
  const localService = toRecord(raw.local_service);
  const product = toRecord(raw.product);
  const serviceId = toNullableNumber(
    raw.service_id ?? raw.local_service_id ?? service.id ?? localService.id ?? raw.bound_service_id,
  );

  return {
    hostid: firstText(raw.hostid, raw.upstream_product_id, raw.product_id, raw.id),
    product_name: firstText(raw.product_name, raw.product, product.name, raw.name, service.product_name),
    domain: firstText(raw.domain, raw.hostname, service.domain),
    ip: firstText(raw.dedicatedip, raw.dedicated_ip, raw.ip, raw.ip_address),
    next_due_date: firstText(raw.nextduedate, raw.next_due_date, raw.due_date),
    service_id: serviceId,
    service_name: firstText(
      raw.service_name,
      service.service_name,
      service.name,
      localService.service_name,
      localService.name,
    ),
    user_id: toNullableNumber(raw.user_id ?? toRecord(raw.user).id ?? toRecord(service.user).id),
    user: normalizeActor(raw.user ?? service.user),
    bound: Boolean(raw.bound ?? raw.is_bound ?? serviceId),
  };
}

function normalizeUpstreamProducts(raw: unknown): AutoRefundUpstreamProducts {
  const record = toRecord(raw);
  const list = resolveRowList(raw).map(normalizeUpstreamProduct);
  const bound = list.filter((item) => item.bound).length;
  const hasBound = record.bound !== undefined || record.bound_count !== undefined;

  return {
    list,
    total: toNumber(record.total, list.length),
    bound: toNumber(record.bound ?? record.bound_count, bound),
    unbound: hasBound ? toNumber(record.unbound ?? record.unbound_count, list.length - bound) : list.length - bound,
    upstream_error: firstText(record.upstream_error),
  };
}

/**
 * 商品策略里的「策略块」可能平铺在行上，也可能包一层 `policy` / `rule`。
 * 契约只钉了字段语义，没钉嵌套；这里统一收口，后端定稿后多余分支自然失效。
 */
function resolvePolicyRecord(raw: Record<string, unknown>): Record<string, unknown> {
  for (const key of ['policy', 'rule', 'product_rule', 'refund_policy']) {
    const value = raw[key];
    if (value && typeof value === 'object' && !Array.isArray(value)) return toRecord(value);
  }

  return {};
}

function normalizeProductRule(raw: Record<string, unknown>): AutoRefundProductRule {
  const product = toRecord(raw.product);
  const group = toRecord(raw.group ?? product.group);
  const policy = resolvePolicyRecord(raw);
  const hasConfiguredFlag =
    raw.configured !== undefined || raw.has_rule !== undefined || raw.policy_configured !== undefined;
  // 没有显式标志时以「有没有类型」判断：后端一行策略必然带 refund_type
  const configured = hasConfiguredFlag
    ? toBool(raw.configured ?? raw.has_rule ?? raw.policy_configured)
    : firstText(policy.refund_type, raw.refund_type) !== '';

  return {
    product_id: toNumber(raw.product_id ?? raw.id ?? product.id),
    product_name: firstText(raw.product_name, raw.name, product.product_name, product.name),
    group_name: firstText(
      raw.group_name,
      raw.product_group_name,
      raw.first_group_name,
      raw.category_name,
      group.name,
      product.group_name,
    ),
    refund_type: firstText(raw.refund_type, policy.refund_type),
    refund_rule: firstText(raw.refund_rule, policy.refund_rule),
    requirement: firstText(raw.requirement, raw.refund_requirement, policy.requirement),
    window_hours: toNullableNumber(raw.window_hours ?? policy.window_hours),
    // 建表默认 1：行上没给 enable 字段时按启用理解，避免未配置商品显示成「已停用」
    enabled: toBool(raw.enabled ?? policy.enabled ?? 1),
    configured,
    updated_at: firstText(raw.updated_at, raw.modified_at, raw.last_modified_at),
  };
}

function normalizeProductRulePage(raw: unknown): AutoRefundProductRuleListResponse {
  const record = toRecord(raw);
  const list = resolveRowList(raw).map(normalizeProductRule);

  return {
    list,
    total: toNumber(record.total, list.length),
    page: toNumber(record.page, 1),
    page_size: toNumber(record.page_size, list.length),
  };
}

export const autoRefundApi = {
  applications: (params: AutoRefundApplicationListParams) =>
    request.get<unknown>({ url: '/v2/admin/auto-refund/applications', params }).then(normalizeApplicationPage),

  application: (id: number | string) =>
    request.get<unknown>({ url: `/v2/admin/auto-refund/applications/${id}` }).then(normalizeApplicationDetail),

  productRules: (params: AutoRefundProductRuleListParams) =>
    request.get<unknown>({ url: '/v2/admin/auto-refund/product-rules', params }).then(normalizeProductRulePage),

  /** 保存单个商品策略：`enabled=false` 或不建行＝该商品不参与自助退款 */
  saveProductRule: (productId: number | string, payload: AutoRefundProductRulePayload) =>
    request.put<unknown>({ url: `/v2/admin/auto-refund/product-rules/${productId}`, data: payload }),

  settle: (id: number | string, payload: AutoRefundSettlePayload) =>
    request.post<unknown>({ url: `/v2/admin/auto-refund/applications/${id}/settle`, data: payload }),

  reject: (id: number | string, payload: AutoRefundRemarkPayload) =>
    request.post<unknown>({ url: `/v2/admin/auto-refund/applications/${id}/reject`, data: payload }),

  close: (id: number | string, payload: AutoRefundRemarkPayload) =>
    request.post<unknown>({ url: `/v2/admin/auto-refund/applications/${id}/close`, data: payload }),

  // 测试要真打一次上游（插件 request_timeout 默认 15s），比全局 10s 超时宽一点
  testUpstream: () =>
    request
      .post<unknown>({ url: '/v2/admin/auto-refund/upstream/test', timeout: 30000 }, { isTransformResponse: false })
      .then(normalizeUpstreamTest),

  upstreamProducts: () =>
    request.get<unknown>({ url: '/v2/admin/auto-refund/upstream/products' }).then(normalizeUpstreamProducts),
};
