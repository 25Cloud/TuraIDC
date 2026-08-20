<template>
  <t-drawer
    :visible="visible"
    :size="drawerSize"
    header="账单详情"
    :footer="false"
    @update:visible="(value: boolean) => emit('update:visible', value)"
    @close="emit('close')"
  >
    <record-detail-page
      :loading="loading"
      :ready="Boolean(invoice.id || invoice.invoice_no)"
      back-text="关闭详情"
      eyebrow="账单详情"
      :title="fieldValue(invoice.invoice_no || invoice.id)"
      :description="invoiceTitle(invoice)"
      :status-label="fieldValue(statusLabel)"
      :status-theme="statusTheme"
      :metrics="summaryMetrics"
      :tabs="tabs"
      :active-tab="activeTab"
      empty-text="账单不存在"
      @back="emit('close')"
      @refresh="emit('refresh')"
      @update:active-tab="(value) => (activeTab = value)"
    >
      <template #toolbar-actions>
        <t-button v-if="cancelable" theme="danger" variant="outline" :loading="cancelLoading" @click="emit('cancel')">
          取消账单
        </t-button>
      </template>

      <template #relations>
        <t-button v-if="linkedServiceId" variant="outline" size="small" @click="emit('view-service', linkedServiceId)">
          查看服务
        </t-button>
        <t-button
          v-if="invoice.order?.id || invoice.order_id"
          variant="outline"
          size="small"
          @click="emit('view-order', invoice.order?.id || invoice.order_id)"
        >
          查看订单
        </t-button>
        <t-button
          v-if="invoice.user?.id || invoice.user_id"
          variant="outline"
          size="small"
          @click="emit('view-user', invoice.user?.id || invoice.user_id)"
        >
          查看用户
        </t-button>
      </template>

      <template #tab-basic>
        <section class="finance-detail-section">
          <h4>基础信息</h4>
          <div class="finance-detail-grid">
            <div>
              <span>账单类型</span>
              <strong>{{ fieldValue(invoice.type_label || invoiceTypeLabel(invoice.type)) }}</strong>
            </div>
            <div>
              <span>用户</span>
              <strong>{{ userName(invoice.user) }}</strong>
            </div>
            <div>
              <span>订单号</span>
              <strong>{{ fieldValue(invoice.order?.order_no || invoice.order_no) }}</strong>
            </div>
            <div>
              <span>到期日</span>
              <strong>{{ fieldValue(invoice.due_date) }}</strong>
            </div>
            <div>
              <span>创建时间</span>
              <strong>{{ formatDateTime(invoice.created_at) }}</strong>
            </div>
            <div>
              <span>支付时间</span>
              <strong>{{ formatDateTime(invoice.paid_at) }}</strong>
            </div>
            <div>
              <span>链路追踪</span>
              <strong>{{ fieldValue(invoice.trace_id) }}</strong>
            </div>
            <div v-if="invoice.refund_trace_id">
              <span>退款追踪</span>
              <strong>{{ fieldValue(invoice.refund_trace_id) }}</strong>
            </div>
          </div>
        </section>

        <section v-if="items.length" class="finance-detail-section">
          <h4>账单项目</h4>
          <div class="finance-line-list">
            <div
              v-for="item in items"
              :key="String(item.id || item.description || item.name)"
              class="finance-line-item"
            >
              <span>{{ fieldValue(item.description || item.name || item.title) }}</span>
              <strong>{{ formatMoney(item.amount) }}</strong>
            </div>
          </div>
        </section>
      </template>

      <template #tab-payments>
        <section class="finance-detail-section">
          <h4>支付记录</h4>
          <div class="finance-line-list">
            <div
              v-for="payment in payments"
              :key="String(payment.id || payment.payment_no)"
              class="finance-line-item finance-line-item--stacked"
            >
              <div class="finance-line-item__head">
                <strong>{{ fieldValue(payment.payment_no) }}</strong>
                <t-tag :theme="paymentStatusTheme(payment)" variant="light">{{ paymentStatusLabel(payment) }}</t-tag>
              </div>
              <span>第三方单号：{{ fieldValue(payment.trade_no) }}</span>
              <span>链路追踪：{{ fieldValue(payment.trace_id) }}</span>
              <span>{{ fieldValue(payment.gateway) }} / {{ formatMoney(payment.amount) }}</span>
              <span>{{ formatDateTime(payment.paid_at || payment.created_at) }}</span>
            </div>
          </div>
        </section>
      </template>

      <template #tab-logs>
        <section class="finance-detail-section">
          <h4>操作日志</h4>
          <div class="finance-line-list">
            <div
              v-for="log in logs"
              :key="String(log.id || log.created_at)"
              class="finance-line-item finance-line-item--stacked"
            >
              <strong>{{ fieldValue(log.summary || log.action || log.message) }}</strong>
              <span>{{ formatDateTime(log.created_at) }}</span>
            </div>
          </div>
        </section>
      </template>
    </record-detail-page>
  </t-drawer>
</template>
<script setup lang="ts">
import { getStatusLabel, getStatusTagType, INVOICE_TYPE_MAP, PAYMENT_STATUS_MAP } from '@shared/statusConfig';
import { computed, ref } from 'vue';

import type { InvoiceRecord } from '@/api/admin';
import type { RecordDetailMetric, RecordDetailTab } from '@/components/record-detail-page/index.vue';
import RecordDetailPage from '@/components/record-detail-page/index.vue';
import { useMediaQuery } from '@/hooks/useMediaQuery';

const props = withDefaults(
  defineProps<{
    visible: boolean;
    loading?: boolean;
    invoice?: InvoiceRecord;
    payments?: Record<string, unknown>[];
    items?: Record<string, unknown>[];
    logs?: Record<string, unknown>[];
    statusLabel?: string;
    statusTheme?: string;
    cancelable?: boolean;
    cancelLoading?: boolean;
  }>(),
  {
    loading: false,
    invoice: () => ({}),
    payments: () => [],
    items: () => [],
    logs: () => [],
    statusLabel: '',
    statusTheme: 'default',
    cancelable: false,
    cancelLoading: false,
  },
);

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
  (event: 'close'): void;
  (event: 'refresh'): void;
  (event: 'cancel'): void;
  (event: 'view-order', id: unknown): void;
  (event: 'view-user', id: unknown): void;
  (event: 'view-service', id: unknown): void;
}>();

const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '780px'));
const activeTab = ref('basic');
const invoice = computed(() => props.invoice || {});
const payments = computed(() => props.payments || []);
const logs = computed(() => props.logs || []);
const linkedServiceId = computed(() => {
  const service = (invoice.value.service as Record<string, unknown> | undefined) || {};
  const order = (invoice.value.order as Record<string, unknown> | undefined) || {};
  return Number(service.id || order.service_id || 0);
});
const items = computed(() => {
  const sceneItems = invoice.value.scene?.items;
  if (Array.isArray(sceneItems)) return sceneItems as Record<string, unknown>[];
  return props.items || [];
});

const summaryMetrics = computed<RecordDetailMetric[]>(() => [
  { label: '账单金额', value: formatMoney(invoice.value.amount), primary: true },
  { label: '已付金额', value: formatMoney(invoice.value.paid_amount) },
  { label: '创建时间', value: formatDateTime(invoice.value.created_at) },
]);

const tabs = computed<RecordDetailTab[]>(() => [
  { value: 'basic', label: '基础信息' },
  { value: 'payments', label: '支付记录', show: payments.value.length > 0 },
  { value: 'logs', label: '操作日志', show: logs.value.length > 0 },
]);

function invoiceTitle(row: InvoiceRecord) {
  return fieldValue(
    row.product_full_path ||
      row.combined_display_name ||
      row.product_display_name ||
      row.product_spec_display ||
      row.type_label ||
      invoiceTypeLabel(row.type),
  );
}

function invoiceTypeLabel(type: unknown) {
  return INVOICE_TYPE_MAP[String(type || '')] || fieldValue(type);
}

function userName(user: unknown) {
  const record = toRecord(user);
  return fieldValue(record.nickname || record.display_name || record.email);
}

function paymentStatusLabel(payment: Record<string, unknown>) {
  return getStatusLabel(PAYMENT_STATUS_MAP, Number(payment.status));
}

function paymentStatusTheme(payment: Record<string, unknown>) {
  const value = getStatusTagType(PAYMENT_STATUS_MAP, Number(payment.status));
  return value === 'info' || value === 'purple' ? 'default' : value;
}

function fieldValue(value: unknown) {
  if (value === null || value === undefined || value === '') return '-';
  return String(value);
}

function formatMoney(value: unknown) {
  return `¥${Number(value || 0).toFixed(2)}`;
}

function formatDateTime(value: unknown) {
  if (!value) return '-';
  const date = new Date(String(value).replace(/-/g, '/'));
  if (Number.isNaN(date.getTime())) return String(value);
  const pad = (num: number) => String(num).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}
</script>
<style lang="less" scoped>
.finance-detail-section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.finance-detail-section + .finance-detail-section {
  margin-top: 16px;
}

.finance-detail-section h4 {
  position: relative;
  margin: 0;
  padding-left: 10px;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-3, 14px);
  font-weight: 650;
  line-height: 22px;
}

.finance-detail-section h4::before {
  position: absolute;
  top: 4px;
  bottom: 4px;
  left: 0;
  width: 3px;
  border-radius: var(--td-radius-small, 2px);
  background: var(--td-brand-color);
  content: '';
}

.finance-detail-grid,
.finance-line-list {
  overflow: hidden;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-medium, 6px);
  background: var(--td-component-stroke);
}

.finance-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
}

.finance-detail-grid > div,
.finance-line-item {
  background: var(--td-bg-color-container);
}

.finance-detail-grid > div {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 5px;
  padding: 12px;
}

.finance-detail-grid span,
.finance-line-item span {
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 1.5;
}

.finance-detail-grid strong,
.finance-line-item strong {
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-2, 13px);
  font-weight: 600;
  line-height: 1.5;
  overflow-wrap: anywhere;
}

.finance-line-list {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.finance-line-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-width: 0;
  padding: 12px;
}

.finance-line-item--stacked {
  align-items: stretch;
  flex-direction: column;
  gap: 5px;
}

.finance-line-item__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.finance-line-item__head strong {
  min-width: 0;
}

@media (width <= 560px) {
  .finance-detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>
