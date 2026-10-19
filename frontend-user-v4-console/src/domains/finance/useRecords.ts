import { billingCycleLabel } from '@shared/billingCycle';
import {
  ACCOUNT_TRANSACTION_EVENT_MAP,
  getStatusLabel,
  PAYMENT_STATUS_MAP,
  toSelectOptions,
} from '@shared/statusConfig';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, shallowRef } from 'vue';
import { useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { t } from '@/locales';
import type { ClientFinanceListParams, PaymentRecord } from '@/types/client';
import { formatMoney } from '@/utils/format';

import { resolveQuickDateRange } from './dateFilters';

type AnyRecord = Record<string, unknown>;
type RecordListItem = PaymentRecord;
type Fetcher = (_params?: ClientFinanceListParams) => Promise<{ data: { list: RecordListItem[]; total: number } }>;
type DetailFetcher = (_row: RecordListItem) => Promise<{ data: RecordListItem }>;

export const PAYMENT_STATUS_OPTIONS = toSelectOptions(PAYMENT_STATUS_MAP, false);

export const PAYMENT_GATEWAY_OPTIONS = [
  { label: t('finance.records.alipay'), value: 'alipay' },
  { label: t('finance.records.epay'), value: 'epay' },
  { label: t('finance.records.wechat'), value: 'wechat' },
];

export const BALANCE_EVENT_OPTIONS = toSelectOptions(ACCOUNT_TRANSACTION_EVENT_MAP, false);

export { formatMoney };

export function fieldValue(value: unknown) {
  if (value === null || value === undefined || value === '') return '--';
  return String(value);
}

export { formatDateTime } from '@/utils/format';

const BILLING_CYCLE_KEYS = new Set(['billing_cycle', 'billingcycle', 'billingcycle_zh', 'period']);
const SNAPSHOT_DISPLAY_META_KEYS = new Set([
  'product_full_path',
  'product_path',
  'product_display_path',
  'product_path_segments',
  'first_product_group_name',
  'second_product_group_name',
  'third_product_group_name',
]);

export function formatBillingCycle(value: unknown) {
  const raw = String(value ?? '').trim();
  if (!raw) return '--';
  return billingCycleLabel(raw, raw);
}

export function toRecord(value: unknown): AnyRecord {
  return value && typeof value === 'object' ? (value as AnyRecord) : {};
}

function getErrorMessage(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

// 这里只存 i18n key，翻译在 snapshotLabel() 里做：模块级常量若存译好的文案，切换语言不会重新求值。
const SNAPSHOT_LABEL_KEYS: Record<string, string> = {
  bw: 'finance.snapshot.bandwidth',
  in_bw: 'finance.snapshot.downBandwidth',
  out_bw: 'finance.snapshot.upBandwidth',
  os: 'finance.snapshot.os',
  cpu: 'finance.snapshot.cpu',
  area: 'finance.snapshot.region',
  region: 'finance.snapshot.region',
  node: 'finance.snapshot.node',
  node_group: 'finance.snapshot.nodeGroup',
  ip: 'finance.snapshot.ipCount',
  ip_num: 'finance.snapshot.ipCount',
  ipv6_num: 'finance.snapshot.ipv6Count',
  memory: 'finance.snapshot.memory',
  hostname: 'finance.snapshot.hostname',
  quantity: 'finance.snapshot.quantity',
  setup_fee: 'finance.snapshot.setupFee',
  base_amount: 'finance.snapshot.baseAmount',
  total_amount: 'finance.snapshot.totalAmount',
  config_amount: 'finance.snapshot.configAmount',
  subtotal_amount: 'finance.snapshot.subtotalAmount',
  discount_amount: 'finance.snapshot.discountAmount',
  amount: 'finance.snapshot.amount',
  price: 'finance.snapshot.price',
  pricing: 'finance.snapshot.price',
  items: 'finance.snapshot.items',
  meta: 'finance.snapshot.meta',
  configoption: 'finance.snapshot.configOption',
  kind: 'finance.snapshot.kind',
  mode: 'finance.snapshot.mode',
  target_label: 'finance.snapshot.targetService',
  target_service_id: 'finance.snapshot.targetServiceId',
  product_id: 'finance.snapshot.productId',
  product_name: 'finance.snapshot.productName',
  billing_cycle: 'finance.snapshot.billingCycle',
  billingcycle: 'finance.snapshot.billingCycle',
  billingcycle_zh: 'finance.snapshot.billingCycle',
  period: 'finance.snapshot.billingCycle',
  remark: 'finance.snapshot.remark',
  data_disk_size: 'finance.snapshot.dataDisk',
};

export function snapshotLabel(value: unknown) {
  const key = String(value || '').trim();
  if (!key) return '--';
  const labelKey = SNAPSHOT_LABEL_KEYS[key];
  return labelKey ? t(labelKey) : key;
}

export function flattenSnapshot(
  obj: unknown,
  valueLabelMap: Record<string, string> = {},
): { label: string; value: string }[] {
  const source = toRecord(obj);
  const result: { label: string; value: string }[] = [];

  for (const [key, val] of Object.entries(source)) {
    if (SNAPSHOT_DISPLAY_META_KEYS.has(key)) continue;
    if (
      [
        'unit_setup_fee',
        'unit_base_amount',
        'unit_total_amount',
        'unit_config_amount',
        '_schema_version',
        '_schema_type',
      ].includes(key)
    ) {
      continue;
    }
    if (val === null || val === undefined || val === '') continue;
    if (key === 'items' && Array.isArray(val)) {
      val.forEach((item, index) => {
        const record = toRecord(item);
        result.push({
          label: snapshotLabel(
            record.label || record.name || record.option_name || record.spec_key || `${key}.${index + 1}`,
          ),
          value: formatSnapshotItem(record),
        });
      });
      continue;
    }

    if (val && typeof val === 'object' && !Array.isArray(val)) {
      const nested = flattenSnapshot(val, valueLabelMap);
      nested.forEach((item) => result.push({ label: `${snapshotLabel(key)} / ${item.label}`, value: item.value }));
    } else {
      result.push({ label: snapshotLabel(key), value: formatSnapshotValue(valueLabelMap[key] || val, key) });
    }
  }

  return result;
}

export function configValueLabelMap(row: AnyRecord | null | undefined): Record<string, string> {
  const snapshot = toRecord(row?.config_pricing_snapshot);
  const items = Array.isArray(snapshot.items) ? snapshot.items : [];
  return items.reduce(
    (result, item) => {
      const record = toRecord(item);
      const field = String(record.field || '').trim();
      const label = String(
        record.value_label || record.suboption_name || record.option_name || record.value || '',
      ).trim();
      if (field && label) result[field] = label;
      return result;
    },
    {} as Record<string, string>,
  );
}

function formatSnapshotItem(record: AnyRecord) {
  const value = fieldValue(
    record.value_label ||
      record.option_value_label ||
      record.suboption_label ||
      record.value ||
      record.option_value ||
      record.suboption_name ||
      record.suboption_name_first ||
      record.option_name_first ||
      record.version ||
      record.label ||
      record.name,
  );
  const amount = record.amount ?? record.total_amount ?? record.price ?? record.pricing ?? record.fee;
  const setupFee = record.setup_fee ?? record.setupfee;
  const parts = [value];
  if (amount !== null && amount !== undefined && amount !== '')
    parts.push(t('finance.snapshot.amountWithValue', { amount: formatMoney(amount) }));
  if (setupFee !== null && setupFee !== undefined && setupFee !== '')
    parts.push(t('finance.snapshot.setupFeeWithValue', { amount: formatMoney(setupFee) }));
  return parts.join(' / ');
}

function formatSnapshotValue(value: unknown, key = ''): string {
  if (value === null || value === undefined || value === '') return '--';
  if (Array.isArray(value)) {
    return value
      .map((item, index) => {
        if (item && typeof item === 'object') return `${index + 1}. ${formatSnapshotItem(toRecord(item))}`;
        return fieldValue(item);
      })
      .join(t('common.listSeparator'));
  }
  if (value && typeof value === 'object') {
    return Object.entries(toRecord(value))
      .filter(([, childValue]) => childValue !== null && childValue !== undefined && childValue !== '')
      .map(([childKey, childValue]) =>
        t('common.labelValue', {
          label: snapshotLabel(childKey),
          value: formatSnapshotValue(childValue, childKey),
        }),
      )
      .join(t('common.listSeparator'));
  }
  const raw = String(value);
  if (BILLING_CYCLE_KEYS.has(key)) return formatBillingCycle(raw);
  if (['bw', 'in_bw', 'out_bw'].includes(key) && /^\d+(?:\.\d+)?$/.test(raw)) return `${raw} Mbps`;
  if (key === 'memory' && /^\d+(?:\.\d+)?$/.test(raw)) return `${raw} MB`;
  if (['ip_num', 'ipv6_num', 'quantity'].includes(key) && /^\d+(?:\.\d+)?$/.test(raw))
    return t('common.countUnit', { count: raw });
  return raw;
}

export function resolveOrderTagTheme(status: unknown) {
  const value = Number(status);
  if (value === 1 || value === 3) return 'success';
  if (value === 0 || value === 2) return 'warning';
  if (value === 4) return 'default';
  return 'danger';
}

export function resolvePaymentStatusLabel(status: unknown) {
  return getStatusLabel(PAYMENT_STATUS_MAP, Number(status));
}

export function resolveAccountTransactionEventLabel(eventType: unknown) {
  return getStatusLabel(ACCOUNT_TRANSACTION_EVENT_MAP, String(eventType || ''));
}

export function resolveBalanceTheme(value: unknown) {
  return Number(value || 0) >= 0 ? 'success' : 'danger';
}

function compactParams(params: ClientFinanceListParams): ClientFinanceListParams {
  return Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== '' && value !== undefined && value !== null),
  ) as ClientFinanceListParams;
}

function resolveListPayload(response: unknown) {
  const payload = toRecord((response as { data?: unknown } | null | undefined)?.data);
  return {
    list: Array.isArray(payload.list) ? (payload.list as RecordListItem[]) : [],
    total: Number(payload.total || 0),
  };
}

export function useRecordList(fetcher: Fetcher, errorMessage: string, options: { detailFetcher?: DetailFetcher } = {}) {
  const router = useRouter();
  const loading = ref(false);
  const detailLoading = ref(false);
  const list = shallowRef<RecordListItem[]>([]);
  const total = ref(0);
  const detailVisible = ref(false);
  const currentRow = shallowRef<RecordListItem | null>(null);
  const filters = reactive({
    page: 1,
    page_size: 10,
    keyword: '',
    status: '' as string | number,
    type: '',
    start_date: '',
    end_date: '',
    quickFilter: '',
  });

  const hasRows = computed(() => list.value.length > 0);

  function buildParams(): ClientFinanceListParams {
    return compactParams({
      page: filters.page,
      page_size: filters.page_size,
      keyword: filters.keyword,
      status: filters.status,
      type: filters.type,
      start_date: filters.start_date,
      end_date: filters.end_date,
    });
  }

  async function loadList() {
    loading.value = true;
    try {
      const response = await fetcher(buildParams());
      const payload = resolveListPayload(response);
      list.value = payload.list;
      total.value = payload.total;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, errorMessage));
    } finally {
      loading.value = false;
    }
  }

  function handleSearch() {
    filters.page = 1;
    void loadList();
  }

  function handlePageSizeChange() {
    filters.page = 1;
    void loadList();
  }

  function applyQuickFilter(key: string) {
    filters.quickFilter = key;
    filters.page = 1;
    filters.status = '';
    filters.type = '';
    filters.start_date = '';
    filters.end_date = '';

    if (key === 'pending') {
      filters.status = 0;
    }
    const range = resolveQuickDateRange(key);
    filters.start_date = range.start_date || '';
    filters.end_date = range.end_date || '';
    void loadList();
  }

  function goToInvoice(row: RecordListItem) {
    const invoiceId = Number(row?.invoice_id || 0);
    if (invoiceId > 0) {
      router.push({ path: '/client/invoices', query: { detail: String(invoiceId) } });
    }
  }

  async function openDetail(row: RecordListItem) {
    currentRow.value = row;
    detailVisible.value = true;
    if (!options.detailFetcher) return;

    detailLoading.value = true;
    try {
      const response = await options.detailFetcher(row);
      currentRow.value = response.data || row;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('finance.messages.detailLoadFailed')));
    } finally {
      detailLoading.value = false;
    }
  }

  function closeDetail() {
    detailVisible.value = false;
    currentRow.value = null;
  }

  onMounted(() => {
    void loadList();
  });

  return {
    router,
    loading,
    detailLoading,
    list,
    total,
    detailVisible,
    currentRow,
    filters,
    hasRows,
    loadList,
    handleSearch,
    handlePageSizeChange,
    applyQuickFilter,
    goToInvoice,
    openDetail,
    closeDetail,
  };
}

export const recordApi = {
  payments: (params?: ClientFinanceListParams) => clientApi.payments(params),
};
