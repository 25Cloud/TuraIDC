import { ACCOUNT_TRANSACTION_EVENT_MAP, getStatusLabel } from '@shared/statusConfig';
import type { PrimaryTableCol } from 'tdesign-vue-next';

import { t } from '@/locales';

import type { FinanceLedgerRecord } from '../types';

export const securityColumns: PrimaryTableCol[] = [
  { colKey: 'direction_label', title: t('serviceConsole.security.direction') },
  { colKey: 'protocol_label', title: t('serviceConsole.security.protocol') },
  { colKey: 'port', title: t('serviceConsole.security.port') },
  { colKey: 'ip', title: t('serviceConsole.security.source') },
  { colKey: 'description', title: t('serviceConsole.security.description') },
  { colKey: 'operation', title: t('common.action'), width: 96 },
];

export const natColumns: PrimaryTableCol[] = [
  { colKey: 'name', title: t('serviceConsole.nat.nameLabel') },
  { colKey: 'external_address', title: t('serviceConsole.nat.publicAddress') },
  { colKey: 'internal_port', title: t('serviceConsole.nat.internalPortLabel') },
  { colKey: 'protocol_label', title: t('serviceConsole.security.protocol') },
  { colKey: 'operation', title: t('common.action'), width: 96 },
];

export const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: t('serviceConsole.tables.logActionTime'), minWidth: '10rem' },
  { colKey: 'action_label', title: t('common.action') },
  { colKey: 'summary', title: t('serviceConsole.tables.logSummary'), minWidth: '18rem' },
  { colKey: 'actor_name', title: t('serviceConsole.tables.logOperator') },
  { colKey: 'ip_address', title: t('serviceConsole.tables.logIpAddress') },
];

export const financeColumns: PrimaryTableCol[] = [
  { colKey: 'occurred_at', title: t('serviceConsole.tables.financeOccurredAt'), minWidth: '10rem' },
  { colKey: 'event_type', title: t('serviceConsole.tables.financeEventType'), minWidth: '8rem' },
  { colKey: 'amount', title: t('serviceConsole.tables.financeAmount'), minWidth: '7rem', align: 'right' },
  { colKey: 'summary', title: t('serviceConsole.tables.financeDescription'), minWidth: '16rem' },
  { colKey: 'invoice_no', title: t('serviceConsole.tables.financeInvoiceNo'), minWidth: '10rem' },
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
