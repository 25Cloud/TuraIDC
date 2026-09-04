import type { Ref } from 'vue';

import type {
  ConsoleMachineCategory,
  ConsoleServiceDetail,
  ServiceConsoleCapabilities,
  ServiceSpecItem,
} from '@/types/client';

type ConsoleDetailPatch = Partial<ConsoleServiceDetail>;

export const DEFAULT_TAB = 'overview';
export const CLOUD_TABS = ['overview', 'monitor', 'security', 'logs', 'finance', 'vnc'];
export const NAT_TABS = ['overview', 'monitor', 'security', 'nat', 'logs', 'finance', 'vnc'];
/** 内置控制台 tab 展示顺序（自定义区域插入到 overview 之后） */
export const BUILTIN_TABS_ORDER = ['overview', 'monitor', 'security', 'nat', 'logs', 'finance', 'vnc'];
export const VNC_CREDENTIAL_STORAGE_PREFIX = 'turaidc:vnc-credentials:';

export function emptyDetail(): ConsoleServiceDetail {
  return {
    id: 0,
    name: '',
    custom_service_name: '',
    combined_display_name: '',
    product_display_name: '',
    remark: '',
    domain: '',
    status: 0,
    status_tone: 'info',
    billing_cycle: '',
    billing_cycle_label: '',
    amount: '0.00',
    expires_at: '',
    created_at: '',
    auto_renew: 0,
    console_template: '',
    console_mode: '',
    can_manage: false,
    machine_category: { key: '', label: '' },
    product: { id: 0, name: '', type: '', type_label: '', display_name: '', catalog_type: '' },
    invoice: { id: 0, invoice_no: '', order_no: '', status: 0 },
    upstream: { provider_key: '', host_id: 0, status: '', remote_error: '', os: '', dedicated_ip: '' },
    runtime: { power_state: '', power_label: '', description: '' },
    traffic: {
      usage: '0',
      limit: 0,
      remaining: '',
      usage_label: '0G',
      limit_label: '不限',
      remaining_label: '不限',
      usage_percent: null,
      limited: false,
      button_text: '购买流量包',
      purchase_enabled: false,
    },
    connection: {
      hostname: '',
      username: '',
      has_password: false,
      port: 0,
      dedicated_ip: '',
      internal_ip: '',
      assigned_ips: [],
      nat_remote_address: '',
      nat_remote_host: '',
      nat_remote_port: 0,
    },
    specs: [],
    actions: {
      refresh: true,
      power: false,
      module_status: false,
      password_reset: false,
      reinstall: false,
      rescue: false,
      traffic_package: false,
      available: [],
    },
    _sync: null,
  };
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}

export function normalizeMachineCategory(value: unknown): ConsoleMachineCategory {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    const source = value as ConsoleMachineCategory;
    return { key: String(source.key || '').trim(), label: String(source.label || '').trim() };
  }
  return { key: '', label: String(value || '').trim() };
}

export function normalizeConsoleDetail(payload: ConsoleDetailPatch = {}): ConsoleServiceDetail {
  const base = emptyDetail();
  const { status_label: _statusLabel, ...detailPayload } = payload as ConsoleDetailPatch & { status_label?: unknown };
  const invoicePayload = toRecord(payload.invoice);
  const upstreamPayload = toRecord(payload.upstream);

  return {
    ...base,
    ...detailPayload,
    machine_category: normalizeMachineCategory(payload.machine_category),
    product: { ...base.product, ...(payload.product || {}) },
    invoice: { ...base.invoice, ...invoicePayload },
    upstream: { ...base.upstream, ...upstreamPayload },
    runtime: { ...base.runtime, ...(payload.runtime || {}) },
    traffic: { ...base.traffic, ...(payload.traffic || {}) },
    connection: { ...base.connection, ...(payload.connection || {}) },
    actions: { ...base.actions, ...(payload.actions || {}) },
    specs: Array.isArray(payload.specs) ? (payload.specs as ServiceSpecItem[]) : [],
  };
}

export function mergeConsoleDetail(
  current: ConsoleServiceDetail,
  patch: ConsoleDetailPatch = {},
): ConsoleServiceDetail {
  return normalizeConsoleDetail({
    ...current,
    ...patch,
    product: { ...(current.product || {}), ...(patch.product || {}) },
    invoice: { ...(current.invoice || {}), ...(patch.invoice || {}) },
    upstream: { ...(current.upstream || {}), ...(patch.upstream || {}) },
    runtime: { ...(current.runtime || {}), ...(patch.runtime || {}) },
    traffic: { ...(current.traffic || {}), ...(patch.traffic || {}) },
    connection: { ...(current.connection || {}), ...(patch.connection || {}) },
    actions: { ...(current.actions || {}), ...(patch.actions || {}) },
  });
}

export function normalizeToken(value: unknown): string {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[\s_-]+/g, '');
}

export function resolveErrorMessage(error: unknown, fallback: string): string {
  if (typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string') {
    return error.message.trim() || fallback;
  }
  return fallback;
}

export function isNatConsole(detail: ConsoleServiceDetail): boolean {
  const consoleTemplate = String(detail.console_template || detail.product?.console_template || '')
    .trim()
    .toLowerCase();

  return consoleTemplate === 'port_mapping';
}

export interface ResolvedConsoleTabs {
  keys: string[];
  /** 自定义区域 tab 的中文名（key -> name），供侧边栏与页签标题展示 */
  areaLabels: Record<string, string>;
}

/**
 * 依据上游能力下发结果组装可用控制台 tab。
 *
 * - 上游为「智简魔方类」（supported && fetchable）时动态组装：
 *   overview -> 自定义区域(areas) -> 内置能力 tab（monitor/nat 按能力裁剪）；
 * - 其余场景回退到原有 NAT / 云服务器静态集合，保证非自定义产品行为不变。
 */
export function resolveAvailableTabs(
  detail: ConsoleServiceDetail,
  capabilities?: ServiceConsoleCapabilities | null,
): ResolvedConsoleTabs {
  const dynamicReady = Boolean(capabilities?.supported && capabilities?.fetchable);

  if (!dynamicReady) {
    const fallbackKeys = isNatConsole(detail) ? NAT_TABS : CLOUD_TABS;
    return { keys: [...fallbackKeys], areaLabels: {} };
  }

  const keys: string[] = ['overview'];
  const seen = new Set<string>(keys);
  const areaLabels: Record<string, string> = {};

  const areas = Array.isArray(capabilities?.areas) ? capabilities.areas : [];
  for (const area of areas) {
    const key = String(area?.key || '').trim();
    const name = String(area?.name || '').trim();
    if (!key || key === 'overview' || seen.has(key)) continue;
    seen.add(key);
    keys.push(key);
    if (name && name !== key) areaLabels[key] = name;
  }

  const natSupported = capabilities?.nat_supported === true;

  for (const key of BUILTIN_TABS_ORDER) {
    if (key === 'overview' || seen.has(key)) continue;
    // NAT 能力以动态 tab 交付；无该能力时与云服务器集合保持一致
    if (key === 'nat' && !natSupported) continue;
    seen.add(key);
    keys.push(key);
  }

  return { keys, areaLabels };
}

export function findSpecValue(detail: Ref<ConsoleServiceDetail>, aliases: string[], fallback = '--'): string {
  const specs = Array.isArray(detail.value.specs) ? detail.value.specs : [];
  for (const alias of aliases) {
    const token = normalizeToken(alias);
    const matched = specs.find((spec) => {
      const keys = [spec.key, spec.label].map(normalizeToken);
      return keys.some((item) => item.includes(token) || token.includes(item));
    });
    const value = String(matched?.value || '').trim();
    if (value) return value;
  }
  return fallback;
}

export { copyText } from '@/utils/format';
