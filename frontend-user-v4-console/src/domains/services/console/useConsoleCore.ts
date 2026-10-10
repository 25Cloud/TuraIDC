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
/**
 * 面板型产品（CDN / 虚拟主机）的基础 tab：控制能力全部来自上游自定义区域，
 * 不具备云服务器的监控/安全组/VNC 等能力，因此兜底时不套用云主机集合。
 */
export const PANEL_TABS = ['overview', 'finance'];
const PANEL_CATEGORY_KEYS = new Set(['cdn', 'web_hosting']);
/** 虚拟主机：后台「控制台面板」可选值与兜底识别的机器分类 / 产品类型 */
const VIRTUAL_HOST_TEMPLATE_KEYS = new Set(['virtualhost', 'virtual_host', 'web_hosting', 'hosting']);
const VIRTUAL_HOST_CATEGORY_KEYS = new Set(['virtualhost', 'virtual_host', 'web_hosting', 'hosting']);
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

/**
 * CDN 专属控制台判定。
 *
 * 优先读产品上配置的「控制台面板」（console_template），这是运营在后台手动选的；
 * 没配时才按机器分类 / 产品类型兜底，避免历史数据（console_template 为空）切不到 CDN 控制台。
 */
export function isCdnConsole(detail: ConsoleServiceDetail): boolean {
  const consoleTemplate = String(detail.console_template || detail.product?.console_template || '')
    .trim()
    .toLowerCase();
  if (consoleTemplate === 'cdn') return true;
  if (consoleTemplate === 'compute' || consoleTemplate === 'port_mapping') return false;

  const categoryKey = String(detail.machine_category?.key || '')
    .trim()
    .toLowerCase();
  if (categoryKey === 'cdn') return true;

  const productType = String(
    (detail as { product_type?: string }).product_type || (detail.product as { type?: string } | undefined)?.type || '',
  )
    .trim()
    .toLowerCase();

  return productType === 'cdn';
}

/**
 * 虚拟主机专属控制台判定。
 *
 * 与 CDN 同属「面板型」产品（控制能力全在上游自定义区域），但两者业务语义不同：
 * 虚拟主机关心空间/流量/域名绑定/面板登录，CDN 关心流量用量与节点。
 * 判定规则与 isCdnConsole 对齐：优先读产品上配置的「控制台面板」(console_template)，
 * 没配时按机器分类 / 产品类型兜底。
 */
export function isVirtualHostConsole(detail: ConsoleServiceDetail): boolean {
  const consoleTemplate = String(detail.console_template || detail.product?.console_template || '')
    .trim()
    .toLowerCase();
  if (VIRTUAL_HOST_TEMPLATE_KEYS.has(consoleTemplate)) return true;
  if (consoleTemplate === 'cdn' || consoleTemplate === 'compute' || consoleTemplate === 'port_mapping') {
    return false;
  }

  const categoryKey = String(detail.machine_category?.key || '')
    .trim()
    .toLowerCase();
  if (VIRTUAL_HOST_CATEGORY_KEYS.has(categoryKey)) return true;

  const productType = String(
    (detail as { product_type?: string }).product_type || (detail.product as { type?: string } | undefined)?.type || '',
  )
    .trim()
    .toLowerCase();

  return VIRTUAL_HOST_CATEGORY_KEYS.has(productType);
}

export interface ResolvedConsoleTabs {
  keys: string[];
  /** 自定义区域 tab 的中文名（key -> name），供侧边栏与页签标题展示 */
  areaLabels: Record<string, string>;
}

/** 面板型产品（CDN / 虚拟主机）：控制能力全部由上游自定义区域交付。 */
export function isPanelConsole(detail: ConsoleServiceDetail): boolean {
  // CDN 控制台同样按面板型裁剪：没有监控/安全组/VNC，操作入口全在自定义区域
  if (isCdnConsole(detail)) return true;
  // 虚拟主机同理：没有 CPU/内存，也没有电源、开关机、重装、安全组、VNC。
  // 判定放在最前面，因为后台商品可能仍带着云主机的 product_type / machine_category，
  // 但 console_template=virtualhost 已经是运营明确选择的控制台类别。
  if (isVirtualHostConsole(detail)) return true;

  const categoryKey = String(detail.machine_category?.key || '')
    .trim()
    .toLowerCase();
  if (PANEL_CATEGORY_KEYS.has(categoryKey)) return true;

  const productType = String(
    (detail as { product_type?: string }).product_type || (detail.product as { type?: string } | undefined)?.type || '',
  )
    .trim()
    .toLowerCase();

  return PANEL_CATEGORY_KEYS.has(productType);
}

/**
 * 依据上游能力下发结果组装可用控制台 tab。
 *
 * - 上游为「智简魔方类」（supported && fetchable）时动态组装：
 *   overview -> 自定义区域(areas) -> 内置能力 tab（monitor/nat 按能力裁剪）；
 * - 面板型产品（CDN / 虚拟主机）不具备云服务器的监控/安全组/VNC 能力，
 *   动态与兜底路径都只保留 overview + finance，其余入口由自定义区域承载；
 * - 其余场景回退到原有 NAT / 云服务器静态集合，保证非自定义产品行为不变。
 */
export function resolveAvailableTabs(
  detail: ConsoleServiceDetail,
  capabilities?: ServiceConsoleCapabilities | null,
): ResolvedConsoleTabs {
  const panelConsole = isPanelConsole(detail);
  const dynamicReady = Boolean(capabilities?.supported && capabilities?.fetchable);

  if (!dynamicReady) {
    const fallbackKeys = panelConsole ? PANEL_TABS : isNatConsole(detail) ? NAT_TABS : CLOUD_TABS;
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
    // 面板型产品没有监控/安全组/VNC/运营日志能力，只保留账单
    if (panelConsole && key !== 'finance') continue;
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
      // 空白 key / label 要先剔除：token.includes('') 恒为 true，
      // 不剔除会让没有 key 的规格被当成命中第一个别名的项。
      return keys.some((item) => item !== '' && (item.includes(token) || token.includes(item)));
    });
    const value = String(matched?.value || '').trim();
    if (value) return value;
  }
  return fallback;
}

export { copyText } from '@/utils/format';
