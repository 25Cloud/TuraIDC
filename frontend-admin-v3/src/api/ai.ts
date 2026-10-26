/**
 * AI 相关接口（管理端）。
 *
 * 两条通道，刻意分开：
 * 1. 浮窗对话走 shared 的 createAiClient（原生 fetch + SSE），不能套 axios
 *    ——管理端 axios 有 10s 超时、{code,data} 信封与 3 次重试，套流式响应必挂；
 * 2. 设置 / 用量 / 会话审计是普通 JSON 接口，走项目既有 request，复用统一错误提示与 X-Locale。
 */
import type { AiConversation, AiConversationDetail, AiPermissionMode } from '@turaidc/shared/runtime';
import { createAiClient } from '@turaidc/shared/runtime';

import { getAdminToken } from '@/app/runtime/session';
import { i18n } from '@/locales';
import { request } from '@/utils/request';

/** 上游协议：三种形态对应三个不同端点 */
export type AiProtocol = 'chat_completions' | 'responses' | 'anthropic_messages';

export const AI_PROTOCOL_OPTIONS: Array<{ labelKey: string; value: AiProtocol; endpoint: string }> = [
  { labelKey: 'ai.settings.protocolChatCompletions', value: 'chat_completions', endpoint: '/v1/chat/completions' },
  { labelKey: 'ai.settings.protocolResponses', value: 'responses', endpoint: '/v1/responses' },
  { labelKey: 'ai.settings.protocolAnthropicMessages', value: 'anthropic_messages', endpoint: '/v1/messages' },
];

export const AI_PERMISSION_MODES: AiPermissionMode[] = ['read_only', 'confirm', 'auto_approve'];

/** 设置组 ai 的前端视图模型：字段与后端 settings 组一一对应 */
export interface AiAdminSettings {
  enabled: boolean;
  protocol: AiProtocol;
  base_url: string;
  model: string;
  temperature: number;
  max_tokens: number;
  console_enabled: boolean;
  site_enabled: boolean;
  admin_enabled: boolean;
  allow_balance_pay: boolean;
  user_daily_messages: number;
  user_daily_tokens: number;
  global_daily_tokens: number;
  global_daily_cost_cents: number;
  max_tool_rounds: number;
  request_timeout_seconds: number;
  history_message_limit: number;
  redact_pii: boolean;
  retention_days: number;
  default_permission_mode: AiPermissionMode;
  max_permission_mode: AiPermissionMode;
  /** 密钥只读状态：明文永远不出后端 */
  api_key_has_value: boolean;
  api_key_masked: string;
}

/** 保存载荷：api_key 为空表示保留旧值，所以只在填写时才带这个键 */
export type AiAdminSettingsPayload = Omit<AiAdminSettings, 'api_key_has_value' | 'api_key_masked'> & {
  api_key?: string;
};

export interface AiUsageRow {
  day: string;
  messages: number;
  prompt_tokens: number;
  completion_tokens: number;
  cost_cents: number;
}

export interface AiUsageTotals {
  messages: number;
  prompt_tokens: number;
  completion_tokens: number;
  cost_cents: number;
}

export interface AiUsageResponse {
  days: number;
  list: AiUsageRow[];
  totals: AiUsageTotals;
}

/** 审计列表项：会话本体外加归属用户信息（管理端不脱敏） */
export interface AiAdminConversation extends AiConversation {
  user_id?: number | string | null;
  user?: {
    id?: number | string;
    email?: string;
    nickname?: string;
    display_name?: string;
    phone?: string;
  } | null;
}

export interface AiConversationListParams {
  page?: number;
  page_size?: number;
  user_id?: number | string;
  channel?: string;
}

export interface AiConversationListResponse {
  list: AiAdminConversation[];
  total: number;
  page: number;
  page_size: number;
}

export interface AiSettingsTestResult {
  ok: boolean;
  message: string;
  model: string;
  latency_ms: number | null;
}

const DEFAULT_SETTINGS: AiAdminSettings = {
  enabled: false,
  protocol: 'chat_completions',
  base_url: '',
  model: '',
  temperature: 0.7,
  max_tokens: 2048,
  console_enabled: true,
  site_enabled: false,
  admin_enabled: false,
  allow_balance_pay: false,
  user_daily_messages: 0,
  user_daily_tokens: 0,
  global_daily_tokens: 0,
  global_daily_cost_cents: 0,
  max_tool_rounds: 6,
  request_timeout_seconds: 60,
  history_message_limit: 12,
  redact_pii: true,
  retention_days: 30,
  default_permission_mode: 'confirm',
  max_permission_mode: 'confirm',
  api_key_has_value: false,
  api_key_masked: '',
};

/**
 * AI 浮窗客户端（管理端）：prefix 走 /v2/admin/ai，token 取 admin_token。
 * locale 一并透传，让后端业务提示与管理端界面语言一致。
 */
const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '')
  .trim()
  .replace(/\/+$/, '');

export const aiClient = createAiClient({
  baseURL: apiBaseUrl,
  getToken: () => getAdminToken(),
  locale: () => i18n.global.locale.value,
  prefix: '/v2/admin/ai',
});

function toBool(value: unknown, fallback = false): boolean {
  if (typeof value === 'boolean') return value;
  if (value === 1 || value === '1' || value === 'true') return true;
  if (value === 0 || value === '0' || value === 'false') return false;
  return fallback;
}

function toNumber(value: unknown, fallback: number): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function toText(value: unknown, fallback = ''): string {
  if (value === null || value === undefined) return fallback;
  return typeof value === 'string' ? value : String(value);
}

function toPermissionMode(value: unknown, fallback: AiPermissionMode): AiPermissionMode {
  return AI_PERMISSION_MODES.includes(value as AiPermissionMode) ? (value as AiPermissionMode) : fallback;
}

function toProtocol(value: unknown): AiProtocol {
  const found = AI_PROTOCOL_OPTIONS.find((item) => String(item.value) === String(value));
  return found ? found.value : 'chat_completions';
}

/**
 * 密钥状态归一化。
 *
 * 后端可能把 api_key 直接给成对象（{has_value, masked_value}），也可能平铺成
 * has_value / api_key_has_value 这类标量。两种都认，但绝不把任何形态的明文当值回填到输入框。
 */
function resolveApiKeyState(raw: Record<string, unknown>): { has: boolean; masked: string } {
  const nested = raw.api_key;

  if (nested && typeof nested === 'object') {
    const record = nested as Record<string, unknown>;
    return {
      has: toBool(record.has_value ?? record.configured ?? record.has),
      masked: toText(record.masked_value ?? record.display_value),
    };
  }

  return {
    has: toBool(raw.api_key_has_value ?? raw.has_api_key ?? raw.has_value ?? raw.configured),
    masked: toText(raw.api_key_masked ?? raw.masked_api_key ?? (typeof nested === 'string' ? nested : '')),
  };
}

export function normalizeAiSettings(raw: Record<string, unknown> | null | undefined): AiAdminSettings {
  const data = raw || {};
  const apiKey = resolveApiKeyState(data);

  return {
    enabled: toBool(data.enabled, DEFAULT_SETTINGS.enabled),
    protocol: toProtocol(data.protocol),
    base_url: toText(data.base_url),
    model: toText(data.model),
    temperature: toNumber(data.temperature, DEFAULT_SETTINGS.temperature),
    max_tokens: toNumber(data.max_tokens, DEFAULT_SETTINGS.max_tokens),
    console_enabled: toBool(data.console_enabled, DEFAULT_SETTINGS.console_enabled),
    site_enabled: toBool(data.site_enabled, DEFAULT_SETTINGS.site_enabled),
    admin_enabled: toBool(data.admin_enabled, DEFAULT_SETTINGS.admin_enabled),
    allow_balance_pay: toBool(data.allow_balance_pay, DEFAULT_SETTINGS.allow_balance_pay),
    user_daily_messages: toNumber(data.user_daily_messages, DEFAULT_SETTINGS.user_daily_messages),
    user_daily_tokens: toNumber(data.user_daily_tokens, DEFAULT_SETTINGS.user_daily_tokens),
    global_daily_tokens: toNumber(data.global_daily_tokens, DEFAULT_SETTINGS.global_daily_tokens),
    global_daily_cost_cents: toNumber(data.global_daily_cost_cents, DEFAULT_SETTINGS.global_daily_cost_cents),
    max_tool_rounds: toNumber(data.max_tool_rounds, DEFAULT_SETTINGS.max_tool_rounds),
    request_timeout_seconds: toNumber(data.request_timeout_seconds, DEFAULT_SETTINGS.request_timeout_seconds),
    history_message_limit: toNumber(data.history_message_limit, DEFAULT_SETTINGS.history_message_limit),
    redact_pii: toBool(data.redact_pii, DEFAULT_SETTINGS.redact_pii),
    retention_days: toNumber(data.retention_days, DEFAULT_SETTINGS.retention_days),
    default_permission_mode: toPermissionMode(data.default_permission_mode, DEFAULT_SETTINGS.default_permission_mode),
    max_permission_mode: toPermissionMode(data.max_permission_mode, DEFAULT_SETTINGS.max_permission_mode),
    api_key_has_value: apiKey.has,
    api_key_masked: apiKey.masked,
  };
}

/** 取列表时后端可能给 {list}/{items}，也可能直接给数组，这里统一成行数组 */
function resolveRowList(value: unknown): Array<Record<string, unknown>> {
  if (Array.isArray(value)) return value as Array<Record<string, unknown>>;
  if (!value || typeof value !== 'object') return [];

  const record = value as Record<string, unknown>;
  for (const key of ['list', 'items', 'rows', 'data']) {
    if (Array.isArray(record[key])) return record[key] as Array<Record<string, unknown>>;
  }

  return [];
}

function normalizeUsage(raw: unknown, days: number): AiUsageResponse {
  const record = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const list = resolveRowList(raw).map<AiUsageRow>((row) => ({
    day: toText(row.day ?? row.date),
    messages: toNumber(row.messages ?? row.message_count, 0),
    prompt_tokens: toNumber(row.prompt_tokens, 0),
    completion_tokens: toNumber(row.completion_tokens, 0),
    cost_cents: toNumber(row.cost_cents, 0),
  }));

  const totalsSource = (record.totals ?? record.summary ?? {}) as Record<string, unknown>;

  return {
    days: toNumber(record.days, days),
    list,
    totals: {
      messages: toNumber(
        totalsSource.messages,
        list.reduce((sum, row) => sum + row.messages, 0),
      ),
      prompt_tokens: toNumber(
        totalsSource.prompt_tokens,
        list.reduce((sum, row) => sum + row.prompt_tokens, 0),
      ),
      completion_tokens: toNumber(
        totalsSource.completion_tokens,
        list.reduce((sum, row) => sum + row.completion_tokens, 0),
      ),
      cost_cents: toNumber(
        totalsSource.cost_cents,
        list.reduce((sum, row) => sum + row.cost_cents, 0),
      ),
    },
  };
}

function normalizeConversationPage(raw: unknown): AiConversationListResponse {
  const record = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;
  const list = resolveRowList(raw) as unknown as AiAdminConversation[];

  return {
    list,
    total: toNumber(record.total, list.length),
    page: toNumber(record.page, 1),
    page_size: toNumber(record.page_size, list.length),
  };
}

function normalizeTestResult(raw: unknown): AiSettingsTestResult {
  const record = (raw && typeof raw === 'object' ? raw : {}) as Record<string, unknown>;

  return {
    ok: toBool(record.ok ?? record.success ?? record.connected, false),
    message: toText(record.message ?? record.detail),
    model: toText(record.model),
    latency_ms: record.latency_ms === undefined || record.latency_ms === null ? null : toNumber(record.latency_ms, 0),
  };
}

export const aiAdminApi = {
  getSettings: () => request.get<Record<string, unknown>>({ url: '/v2/admin/ai/settings' }).then(normalizeAiSettings),

  saveSettings: (payload: AiAdminSettingsPayload) =>
    request.put<Record<string, unknown>>({ url: '/v2/admin/ai/settings', data: payload }),

  // 连通性自检在后端要真打一次上游（默认 15s 上限），比全局 10s 超时宽一点
  testSettings: () =>
    request
      .post<Record<string, unknown>>({ url: '/v2/admin/ai/settings/test', timeout: 30000 })
      .then(normalizeTestResult),

  usage: (days = 14) =>
    request
      .get<unknown>({ url: '/v2/admin/ai/usage', params: { days } })
      .then((response) => normalizeUsage(response, days)),

  conversations: (params: AiConversationListParams) =>
    request.get<unknown>({ url: '/v2/admin/ai/conversations', params }).then(normalizeConversationPage),

  conversation: (id: number | string) => request.get<AiConversationDetail>({ url: `/v2/admin/ai/conversations/${id}` }),
};
