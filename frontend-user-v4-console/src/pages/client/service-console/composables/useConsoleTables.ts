import { ACCOUNT_TRANSACTION_EVENT_MAP, getStatusLabel } from '@shared/statusConfig';
import type { PrimaryTableCol } from 'tdesign-vue-next';

import type { FinanceLedgerRecord } from '@/types/client';

export const securityColumns: PrimaryTableCol[] = [
  { colKey: 'direction_label', title: '方向' },
  { colKey: 'protocol_label', title: '协议' },
  { colKey: 'port', title: '端口' },
  { colKey: 'ip', title: '来源' },
  { colKey: 'description', title: '说明' },
  { colKey: 'operation', title: '操作', width: 96 },
];

export const natColumns: PrimaryTableCol[] = [
  { colKey: 'name', title: '名称' },
  { colKey: 'external_address', title: '公网地址' },
  { colKey: 'internal_port', title: '内网端口' },
  { colKey: 'protocol_label', title: '协议' },
  { colKey: 'operation', title: '操作', width: 96 },
];

export const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: '操作时间', minWidth: '10rem' },
  { colKey: 'action_label', title: '操作' },
  { colKey: 'summary', title: '操作详情', minWidth: '18rem' },
  { colKey: 'actor_name', title: '操作人' },
  { colKey: 'ip_address', title: 'IP 地址' },
];

export const financeColumns: PrimaryTableCol[] = [
  { colKey: 'occurred_at', title: '发生时间', minWidth: '10rem' },
  { colKey: 'event_type', title: '账单类型', minWidth: '8rem' },
  { colKey: 'amount', title: '金额', minWidth: '7rem', align: 'right' },
  { colKey: 'summary', title: '说明', minWidth: '16rem' },
  { colKey: 'invoice_no', title: '关联账单', minWidth: '10rem' },
];

export function resolveFinanceTagTheme(row: FinanceLedgerRecord) {
  const sceneKey = resolveFinanceBusinessKey(row);
  if (sceneKey.includes('refund') || String(row?.event_type || '').includes('refund')) return 'danger';
  if (sceneKey === 'auto_renew') return 'success';
  if (sceneKey === 'manual_renew') return 'warning';
  if (sceneKey === 'purchase') return 'primary';

  const badgeType = String(row?.display?.badge_type || '').trim();
  if (badgeType === 'success') return 'success';
  if (badgeType === 'danger') return 'danger';
  const amount = Number(row?.change_amount || 0);
  if (amount > 0) return 'success';
  if (amount < 0) return 'danger';
  return 'default';
}

export function resolveFinanceBusinessLabel(row: FinanceLedgerRecord) {
  const sceneKey = resolveFinanceBusinessKey(row);
  if (sceneKey.includes('refund') || String(row?.event_type || '').includes('refund')) return '已退款';
  if (sceneKey === 'auto_renew') return '自动续费';
  if (sceneKey === 'manual_renew') return '手动续费';
  if (sceneKey === 'purchase') return '产品购买';

  const label = String(
    row?.business_scene_label ||
      row?.invoice?.business_scene_label ||
      row?.display?.business_scene_label ||
      row?.invoice?.type_label ||
      resolveFinanceEventLabel(row?.event_type) ||
      '--',
  );
  if (label.includes('退款')) return '已退款';
  if (label === '购买') return '产品购买';

  return label;
}

export function resolveFinanceEventLabel(eventType: unknown) {
  const normalized = String(eventType || '').trim();
  return normalized ? getStatusLabel(ACCOUNT_TRANSACTION_EVENT_MAP, normalized) : '';
}

function resolveFinanceBusinessKey(row: FinanceLedgerRecord) {
  return String(row?.business_scene || row?.invoice?.business_scene || '').trim();
}
