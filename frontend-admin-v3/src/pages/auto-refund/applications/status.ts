import { t } from '@/locales';

/**
 * 退款申请状态机（唯一来源：docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md）。
 *
 * 文案进 i18n，颜色跟 `@turaidc/shared/statusConfig` 的 status-tag 约定对齐
 * （标签组件认 Element Plus 命名的 tagType，info/purple 会落到灰色）。
 * 插件自己的状态不属于 shared 的既有枚举，这里只做本页的映射，不改 shared。
 */
export const REFUND_STATUS_KEYS: Record<string, string> = {
  created: 'autoRefund.status.created',
  pending_review: 'autoRefund.status.pendingReview',
  failed: 'autoRefund.status.failed',
  uncertain: 'autoRefund.status.uncertain',
  cancelled: 'autoRefund.status.cancelled',
  settled: 'autoRefund.status.settled',
  rejected: 'autoRefund.status.rejected',
  closed: 'autoRefund.status.closed',
};

const REFUND_STATUS_TAG_TYPES: Record<string, string> = {
  created: 'info',
  pending_review: 'warning',
  uncertain: 'warning',
  failed: 'danger',
  cancelled: 'info',
  settled: 'success',
  rejected: 'danger',
  closed: 'info',
};

export function refundStatusLabel(status: unknown): string {
  const key = REFUND_STATUS_KEYS[String(status ?? '')];

  return key ? t(key) : String(status ?? '-') || '-';
}

/** 状态标签用的 tagType（交给 status-tag 组件换算成 TDesign theme） */
export function refundStatusTagType(status: unknown): string {
  return REFUND_STATUS_TAG_TYPES[String(status ?? '')] || 'info';
}

/** 详情摘要区直接吃 TDesign theme 字符串 */
export function refundStatusTheme(status: unknown): 'default' | 'primary' | 'success' | 'warning' | 'danger' {
  const tagType = refundStatusTagType(status);
  if (tagType === 'success' || tagType === 'warning' || tagType === 'danger') return tagType;

  return 'default';
}

/** 列表与详情共用的状态标签映射：文案随语言切换实时取 */
export function refundStatusMap(): Record<string, { label: string; tagType: string }> {
  return Object.fromEntries(
    Object.entries(REFUND_STATUS_KEYS).map(([status, key]) => [
      status,
      { label: t(key), tagType: REFUND_STATUS_TAG_TYPES[status] || 'info' },
    ]),
  );
}

/**
 * 可结算/可驳回的状态：状态机里只有 `pending_review` 能走向 `settled` / `rejected`。
 * `uncertain`（结果未知）先由管理员到上游核对，再决定结算还是作废。
 */
export function canSettleStatus(status: unknown): boolean {
  return String(status ?? '') === 'pending_review';
}

export function canRejectStatus(status: unknown): boolean {
  return String(status ?? '') === 'pending_review';
}

/**
 * 可作废的状态：`pending_review`（卡死）与 `uncertain`（结果未知）。
 * 作废是手动收口、不可撤回，成功后会释放 `active_service_id` 幂等闸门。
 */
export function canCloseStatus(status: unknown): boolean {
  const value = String(status ?? '');

  return value === 'pending_review' || value === 'uncertain';
}
