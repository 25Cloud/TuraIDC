import { t } from '@/locales';

/**
 * 自助退款第二阶段的四组枚举（唯一契约：执行计划第二阶段的「语义对照表」）。
 *
 * 取值必须与后端一字不差：
 * - `refund_type`：`manual` 人工审核 / `auto` 自动退款 / `api_ticket` API 工单退款 / `bridge` 插件间对接；
 * - `refund_rule`：`duration` 按时长 / `month` 按月 / `full` 全额；
 * - `requirement`：`product_first` 产品首次 / `category_first` 同类产品首次 / `within_hours` 指定时间内；
 * - `channel`：`bridge` 上游接口 / `api_ticket` 上游工单 / `local` 仅本站。
 *
 * 每个取值都配一句人话说明（i18n 里的 `…Hint`）。只给枚举值时运营看不出
 * `category_first` 与 `product_first` 的差别，而这两个口径搞混就是多退一次钱。
 */

/** 退款类型：值 → i18n 键前缀（说明取 `${key}Hint`） */
export const REFUND_TYPE_KEYS: Record<string, string> = {
  manual: 'autoRefund.refundTypes.manual',
  auto: 'autoRefund.refundTypes.auto',
  api_ticket: 'autoRefund.refundTypes.apiTicket',
  bridge: 'autoRefund.refundTypes.bridge',
};

/** 退款规则：金额怎么算 */
export const REFUND_RULE_KEYS: Record<string, string> = {
  duration: 'autoRefund.refundRules.duration',
  month: 'autoRefund.refundRules.month',
  full: 'autoRefund.refundRules.full',
};

/** 退款要求：什么条件下才允许申请 */
export const REQUIREMENT_KEYS: Record<string, string> = {
  product_first: 'autoRefund.requirements.productFirst',
  category_first: 'autoRefund.requirements.categoryFirst',
  within_hours: 'autoRefund.requirements.withinHours',
};

/** 通道：申请实际外发到哪（`auto`/`manual` 都是 `local`，不外发） */
export const CHANNEL_KEYS: Record<string, string> = {
  bridge: 'autoRefund.channels.bridge',
  api_ticket: 'autoRefund.channels.apiTicket',
  local: 'autoRefund.channels.local',
};

/** 商品策略的配置状态：未配置 / 已配置 / 已停用 */
export const PRODUCT_RULE_STATUS_KEYS: Record<string, string> = {
  configured: 'autoRefund.productRules.status.configured',
  unconfigured: 'autoRefund.productRules.status.unconfigured',
  disabled: 'autoRefund.productRules.status.disabled',
};

const PRODUCT_RULE_STATUS_TAG_TYPES: Record<string, string> = {
  configured: 'success',
  unconfigured: 'warning',
  disabled: 'info',
};

export interface RefundEnumOption {
  value: string;
  label: string;
  /** 一句人话说明，直接显示在选项旁边 */
  hint: string;
}

/** 枚举值 → 选项（label + 人话说明），供单选组/下拉渲染 */
export function buildEnumOptions(keyMap: Record<string, string>): RefundEnumOption[] {
  return Object.entries(keyMap).map(([value, key]) => ({
    value,
    label: t(key),
    hint: t(`${key}Hint`),
  }));
}

/**
 * 优先本地化文案，其次用后端下发的 `*_label`，最后退回原始取值。
 * 后端 label 只当兜底：界面语言由前端决定，不能让后端文案盖掉切换后的语言。
 */
function resolveLabel(keyMap: Record<string, string>, value: unknown, backendLabel?: unknown): string {
  const key = keyMap[String(value ?? '')];
  if (key) return t(key);

  const fallback = String(backendLabel ?? '').trim() || String(value ?? '').trim();

  return fallback || '-';
}

export function refundTypeLabel(value: unknown, backendLabel?: unknown): string {
  return resolveLabel(REFUND_TYPE_KEYS, value, backendLabel);
}

export function refundRuleLabel(value: unknown, backendLabel?: unknown): string {
  return resolveLabel(REFUND_RULE_KEYS, value, backendLabel);
}

export function requirementLabel(value: unknown, backendLabel?: unknown): string {
  return resolveLabel(REQUIREMENT_KEYS, value, backendLabel);
}

export function channelLabel(value: unknown, backendLabel?: unknown): string {
  return resolveLabel(CHANNEL_KEYS, value, backendLabel);
}

export function refundTypeOptions(): RefundEnumOption[] {
  return buildEnumOptions(REFUND_TYPE_KEYS);
}

export function refundRuleOptions(): RefundEnumOption[] {
  return buildEnumOptions(REFUND_RULE_KEYS);
}

export function requirementOptions(): RefundEnumOption[] {
  return buildEnumOptions(REQUIREMENT_KEYS);
}

export function channelOptions(): RefundEnumOption[] {
  return buildEnumOptions(CHANNEL_KEYS);
}

/** 配置状态：没策略 = 未配置；有策略但停用 = 已停用；否则已配置 */
export function productRuleStatusOf(row: { configured: boolean; enabled: boolean }): string {
  if (!row.configured) return 'unconfigured';

  return row.enabled ? 'configured' : 'disabled';
}

export function productRuleStatusLabel(row: { configured: boolean; enabled: boolean }): string {
  return t(PRODUCT_RULE_STATUS_KEYS[productRuleStatusOf(row)]);
}

/** 交给 status-tag 组件的映射（tagType 用 Element Plus 命名，与其它页一致） */
export function productRuleStatusMap(): Record<string, { label: string; tagType: string }> {
  return Object.fromEntries(
    Object.entries(PRODUCT_RULE_STATUS_KEYS).map(([status, key]) => [
      status,
      { label: t(key), tagType: PRODUCT_RULE_STATUS_TAG_TYPES[status] || 'info' },
    ]),
  );
}
