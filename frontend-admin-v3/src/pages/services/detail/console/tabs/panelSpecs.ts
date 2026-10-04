/**
 * 面板型产品（CDN / 虚拟主机）配置语义分组。
 *
 * 背景：CDN 与虚拟主机没有 CPU / 内存 / 系统盘这些云服务器规格，
 * 现有「控制台总览」对它们几乎全部显示"--"。但两者的 host_config_option
 * 实际带有完整的真实配置（CDN 12~14 项、虚拟主机 5 项），
 * 因此这里按字段语义分组渲染，而不是套用云主机规格表。
 *
 * 字段值来自后端 ServiceTransformService::buildSpecs()：
 * 中文 key 已由 PANEL_SPEC_FIELD_LABELS 归一到下列英文字段。
 */

/** 能力开关类字段：值表达"支持/不支持"，适合用标签展示 */
const CAPABILITY_FIELDS = new Set([
  'websocket',
  'custom_port',
  'cc_defense',
  'waf_custom',
  'waf_security',
  'l4_forward',
]);

export type PanelCapabilityState = 'supported' | 'unsupported' | 'unknown';

export interface PanelSpecItem {
  key: string;
  label: string;
  value: string;
}

export interface PanelSpecGroup {
  /** 分组标题，如「流量与带宽」 */
  title: string;
  /** 分组说明，交代该组字段的业务含义 */
  hint: string;
  items: PanelSpecItem[];
}

export interface PanelCapabilityItem {
  key: string;
  label: string;
  value: string;
  state: PanelCapabilityState;
}

/** CDN 配置分组：按业务关注点从「套餐」到「防护」排列 */
const CDN_GROUPS: Array<{ title: string; hint: string; fields: string[] }> = [
  { title: '套餐与线路', hint: '所购套餐档位、加速区域与节点带宽', fields: ['plan', 'area', 'bw'] },
  { title: '流量与配额', hint: '月流量上限、并发连接数与可接入的站点规模', fields: ['flow_limit', 'concurrency', 'sites', 'domains'] },
  { title: '加速与转发', hint: 'CDN 节点系统与四层转发能力', fields: ['cdn_system', 'l4_forward'] },
  { title: '安全防护', hint: 'DDoS / CC 防御等级与 WAF 功能', fields: ['defense_level', 'cc_defense', 'waf_custom', 'waf_security'] },
];

/** 虚拟主机配置分组 */
const HOSTING_GROUPS: Array<{ title: string; hint: string; fields: string[] }> = [
  { title: '空间与数据库', hint: 'WEB 空间与数据库容量配额', fields: ['web_space', 'database_space', 'disk'] },
  { title: '流量与带宽', hint: '月流量上限与带宽峰值', fields: ['flow_limit', 'bw'] },
  { title: '绑定与线路', hint: '可绑定域名数量与机房线路', fields: ['domains', 'area'] },
];

/** 未归入上述分组的字段（如各套餐独有的扩展项）统一收进「其他配置」 */
const EXTRA_GROUP = { title: '其他配置', hint: '该套餐包含的附加配置项' };

/** "放开限制"类语义值：在能力语境下代表「支持且不限量」，应判为支持 */
const UNLIMITED_TOKENS = ['不限', '不限制', '无限制'];

/** 明确否定的表述：这些词出现在能力值里一律判为不支持 */
const CAPABILITY_NEGATIVE_TOKENS = ['不支持', '不提供', '不含', '关闭', '未开启'];

/** 其余否定表述（仅在值完全等于时才生效，避免误伤「不限制」这类放开语义） */
const EXACT_NEGATIVE_TOKENS = ['否', '无', '0', '不支持', '不提供'];

/** 肯定表述 */
const SUPPORTED_TOKENS = ['支持', '开启', '已开启', '启用', '含', '赠送', '允许'];

function normalize(value: unknown): string {
  return String(value ?? '').trim();
}

/**
 * 判定能力字段的开关状态。
 *
 * 上游对"不提供"有多种表述（不支持 / 本套餐不提供 / 否 / 0），
 * 统一收敛为 supported / unsupported / unknown 三态。
 *
 * 注意「不限制」在流量语境是放开上限、在能力语境是「支持且不限量」，
 * 因此必须先判 UNLIMITED_TOKENS 再判否定词，否则「绑定域名数=不限制」
 * 会被误标成不支持。
 */
export function resolveCapabilityState(value: string): PanelCapabilityState {
  const text = normalize(value);
  if (!text || text === '--') return 'unknown';

  if (UNLIMITED_TOKENS.some((token) => text.includes(token))) return 'supported';
  if (CAPABILITY_NEGATIVE_TOKENS.some((token) => text.includes(token))) return 'unsupported';
  if (EXACT_NEGATIVE_TOKENS.includes(text)) return 'unsupported';
  if (SUPPORTED_TOKENS.some((token) => text.includes(token))) return 'supported';

  return 'unknown';
}

/** 能力类字段归入独立区块，不混在参数分组里 */
export function splitPanelSpecs(
  specs: Array<{ key?: string; label?: string; value?: unknown }>,
): { capabilities: PanelCapabilityItem[]; groups: PanelSpecGroup[] } {
  const items: PanelSpecItem[] = [];
  const capabilities: PanelCapabilityItem[] = [];

  for (const spec of specs || []) {
    const key = normalize(spec?.key);
    const label = normalize(spec?.label) || key;
    const value = normalize(spec?.value);
    if (!key || !label || !value) continue;

    if (CAPABILITY_FIELDS.has(key)) {
      capabilities.push({ key, label, value, state: resolveCapabilityState(value) });
      continue;
    }

    items.push({ key, label, value });
  }

  return { capabilities, groups: groupSpecs(items) };
}

function groupSpecs(items: PanelSpecItem[]): PanelSpecGroup[] {
  if (!items.length) return [];

  const layout = isCdnSpecSet(items) ? CDN_GROUPS : HOSTING_GROUPS;
  const used = new Set<string>();
  const groups: PanelSpecGroup[] = [];

  for (const group of layout) {
    const picked = items.filter((item) => group.fields.includes(item.key) && !used.has(item.key));
    if (!picked.length) continue;
    picked.forEach((item) => used.add(item.key));
    groups.push({ title: group.title, hint: group.hint, items: picked });
  }

  const rest = items.filter((item) => !used.has(item.key));
  if (rest.length) {
    groups.push({ title: EXTRA_GROUP.title, hint: EXTRA_GROUP.hint, items: rest });
  }

  return groups;
}

/**
 * 依据字段构成判断是 CDN 还是虚拟主机。
 *
 * CDN 独有 CDN 系统 / 防御级别 / 并发限制 / 站点限制等字段，
 * 虚拟主机独有 WEB 空间 / 数据库空间，判别度足够且不依赖 catalog_type 传参。
 * 两者都不命中时按虚拟主机处理——它的字段集更小，误判代价更低。
 */
function isCdnSpecSet(items: PanelSpecItem[]): boolean {
  const keys = new Set(items.map((item) => item.key));

  return ['cdn_system', 'defense_level', 'concurrency', 'sites', 'cc_defense'].some((key) => keys.has(key));
}
