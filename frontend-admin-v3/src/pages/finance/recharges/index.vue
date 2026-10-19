<template>
  <div class="finance-recharges-page">
    <t-card :bordered="false">
      <div class="recharge-filter">
        <t-input
          v-model="filters.keyword"
          class="filter-keyword"
          clearable
          :placeholder="$t('finance.recharges.searchPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select
          v-model="filters.status"
          class="filter-status"
          clearable
          :placeholder="$t('finance.recharges.filterStatus')"
          @change="handleSearch"
        >
          <t-option v-for="item in paymentStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-date-picker
          v-model="filters.start_date"
          class="filter-start"
          clearable
          mode="date"
          format="YYYY-MM-DD"
          value-type="YYYY-MM-DD"
          :placeholder="$t('finance.recharges.startDate')"
          @change="handleSearch"
        />
        <t-date-picker
          v-model="filters.end_date"
          class="filter-end"
          clearable
          mode="date"
          format="YYYY-MM-DD"
          value-type="YYYY-MM-DD"
          :placeholder="$t('finance.recharges.endDate')"
          @change="handleSearch"
        />
      </div>

      <div v-if="!isMobile" class="admin-list-toolbar">
        <admin-view-mode-switch inline :mode="viewMode" @change="setViewMode" />
      </div>

      <div v-if="!isMobile && !isCardView" class="table-scroll">
        <t-table row-key="id" :data="recharges" :columns="columns" :loading="loading" hover table-layout="fixed">
          <template #payment_no="{ row }">
            <button type="button" class="user-link" :disabled="!isEntityId(row.id)" @click="openDetail(row)">
              <strong>{{ fieldValue(row.payment_no) }}</strong>
            </button>
          </template>
          <template #user="{ row }">
            <div class="stack-cell">
              <button
                type="button"
                class="user-link"
                :disabled="!isEntityId(row.user?.id || row.user_id)"
                @click="goUserDetail(row)"
              >
                <strong>{{ userName(row.user) }}</strong>
                <span>{{ fieldValue(row.user?.email) }}</span>
              </button>
            </div>
          </template>
          <template #payment="{ row }">
            <div class="stack-cell">
              <strong>{{ fieldValue(row.payment_no) }}</strong>
              <span>{{ paymentSummary(row.payment || paymentRecord(row)) }}</span>
            </div>
          </template>
          <template #amount="{ row }">{{ formatMoney(row.amount) }}</template>
          <template #paid="{ row }">{{ formatMoney(row.paid_amount) }}</template>
          <template #status="{ row }">
            <status-tag :status-map="PAYMENT_STATUS_MAP" :status="row.status" />
          </template>
          <template #createdAt="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #paidAt="{ row }">{{ formatDateTime(row.paid_at) }}</template>
          <template #operation="{ row }">
            <t-button size="small" variant="text" theme="primary" @click="openDetail(row)">{{
              $t('finance.recharges.detail')
            }}</t-button>
          </template>
        </t-table>
      </div>

      <div v-if="isMobile || isCardView" class="recharge-mobile-list">
        <t-loading :loading="loading" size="small">
          <div
            v-if="recharges.length"
            class="recharge-mobile-stack"
            :style="isCardView && !isMobile ? ADMIN_CARD_GRID_STYLE : undefined"
          >
            <mobile-record-card
              v-for="row in recharges"
              :key="row.id"
              :title="fieldValue(row.payment_no)"
              :eyebrow="$t('finance.recharges.eyebrow')"
              :subtitle="fieldValue(row.gateway || $t('finance.recharges.thirdPartyPayment'))"
              :description="paymentSummary(row.payment || paymentRecord(row))"
              :highlight-label="$t('finance.recharges.amountLabel')"
              :highlight-value="formatMoney(row.amount)"
              :status-map="PAYMENT_STATUS_MAP"
              :status="row.status"
              :rows="rechargeMobileRows(row)"
              :action-options="mobileActionOptions()"
              @action="(value) => handleMobileAction(value, row)"
            />
          </div>
          <t-empty v-else :description="$t('finance.recharges.empty')" />
        </t-loading>
      </div>

      <div v-if="total > 0" class="pagination-row">
        <t-pagination
          :current="pagination.page"
          :page-size="pagination.page_size"
          :total="total"
          :page-size-options="[20, 50, 100]"
          show-jumper
          @change="handlePageChange"
        />
      </div>
    </t-card>

    <invoice-detail-drawer
      v-model:visible="detailState.visible"
      :loading="detailState.loading"
      :invoice="currentInvoice"
      :payments="invoicePayments"
      :items="invoiceItems"
      :logs="invoiceLogs"
      :status-label="invoiceStatusLabel(currentInvoice.status)"
      :status-theme="invoiceStatusTheme(currentInvoice.status)"
      @close="closeDetail"
      @refresh="reloadDetail"
      @view-order="(id) => goOrder(router, id)"
      @view-user="(id) => goUser(router, id)"
      @view-service="(id) => viewLinkedService(id)"
    />
  </div>
</template>
<script setup lang="ts">
import AdminViewModeSwitch from '@/components/admin-view-mode-switch/index.vue';
import { ADMIN_CARD_GRID_STYLE, useAdminViewMode } from '@/composables/useAdminViewMode';

const { mode: viewMode, isCard: isCardView, setMode: setViewMode } = useAdminViewMode('admin-recharges', 'list');

import './index.less';

import { PAYMENT_STATUS_MAP, toLabelMap, toTagTypeMap } from '@shared/statusConfig';
import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol, TableRowData, TagProps } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import type { InvoiceRecord, RechargeRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import InvoiceDetailDrawer from '@/components/finance-record-detail/InvoiceDetailDrawer.vue';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import StatusTag from '@/components/status-tag/index.vue';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { goOrder, goService, goUser, isEntityId } from '@/utils/route/adminEntityLink';
import { errorMessage } from '@/utils/userMessage';

type ThemeType = NonNullable<TagProps['theme']>;

const loading = ref(false);
const recharges = ref<RechargeRecord[]>([]);
const total = ref(0);
const router = useRouter();
const isMobile = useMediaQuery('(max-width: 768px)');
const detailState = reactive({
  visible: false,
  loading: false,
  currentId: 0,
  detail: {
    invoice: {} as InvoiceRecord,
    payments: [] as Record<string, unknown>[],
    items: [] as Record<string, unknown>[],
    logs: [] as Record<string, unknown>[],
  },
});

const filters = reactive({
  keyword: '',
  status: '',
  start_date: '',
  end_date: '',
});

const pagination = reactive({
  page: 1,
  page_size: 20,
});

const statusLabelMap = toLabelMap(PAYMENT_STATUS_MAP);
const statusTypeMap = toTagTypeMap(PAYMENT_STATUS_MAP);
const paymentStatusOptions = computed(() => Object.entries(statusLabelMap).map(([value, label]) => ({ value, label })));

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'payment_no', title: t('finance.recharges.paymentNo'), minWidth: 190 },
  { colKey: 'user', title: t('finance.recharges.user'), minWidth: 180 },
  { colKey: 'payment', title: t('finance.recharges.paymentRecord'), minWidth: 220 },
  { colKey: 'amount', title: t('finance.recharges.amount'), width: 120 },
  { colKey: 'paid', title: t('finance.recharges.paid'), width: 120 },
  { colKey: 'status', title: t('common.status'), width: 110 },
  { colKey: 'createdAt', title: t('finance.recharges.createdAt'), width: 170 },
  { colKey: 'paidAt', title: t('finance.recharges.paidAt'), width: 170 },
  { colKey: 'operation', title: t('common.action'), width: 80, fixed: 'right' },
];

const currentInvoice = computed(() => detailState.detail.invoice || ({} as InvoiceRecord));
const invoicePayments = computed(() => detailState.detail.payments || []);
const invoiceItems = computed(() => {
  const sceneItems = currentInvoice.value.scene?.items;
  if (Array.isArray(sceneItems)) return sceneItems as Record<string, unknown>[];
  return detailState.detail.items || [];
});
const invoiceLogs = computed(() => detailState.detail.logs || []);

function buildParams() {
  const params: Record<string, unknown> = {
    page: pagination.page,
    page_size: pagination.page_size,
  };
  if (filters.keyword) params.keyword = filters.keyword;
  if (filters.status !== '') params.status = filters.status;
  if (filters.start_date) params.start_date = filters.start_date;
  if (filters.end_date) params.end_date = filters.end_date;
  return params;
}

async function loadList() {
  loading.value = true;
  try {
    const response = await adminApi.financeMenu.recharges(buildParams());
    recharges.value = response.list || [];
    total.value = Number(response.total || 0);
    pagination.page = Number(response.page || pagination.page);
    pagination.page_size = Number(response.page_size || pagination.page_size);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('finance.recharges.loadFailed')));
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  pagination.page = 1;
  loadList();
}

function handlePageChange(data: { current: number; pageSize: number }) {
  pagination.page = data.current;
  pagination.page_size = data.pageSize;
  loadList();
}

async function openDetail(row: RechargeRecord) {
  const invoiceId = row.invoice_id;
  detailState.visible = true;
  detailState.currentId = Number(invoiceId || 0);
  detailState.detail = {
    invoice: normalizeRechargeInvoice(row),
    payments: [row.payment || paymentRecord(row)],
    items: [],
    logs: [],
  };
  if (invoiceId) await reloadDetail();
}

async function reloadDetail() {
  if (!detailState.currentId) return;
  detailState.loading = true;
  try {
    const response = await adminApi.invoices.detail(detailState.currentId);
    detailState.detail = normalizeInvoiceDetail(response, currentInvoice.value);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('finance.recharges.loadDetailFailed')));
  } finally {
    detailState.loading = false;
  }
}

function closeDetail() {
  detailState.visible = false;
  detailState.currentId = 0;
  detailState.detail = { invoice: {}, payments: [], items: [], logs: [] };
}

function normalizeInvoiceDetail(payload: Record<string, unknown> = {}, fallback: InvoiceRecord = {}) {
  const invoice =
    payload.invoice && typeof payload.invoice === 'object'
      ? (payload.invoice as InvoiceRecord)
      : (payload as InvoiceRecord);
  return {
    invoice: {
      ...fallback,
      ...invoice,
      type: invoice.type || fallback.type || 'recharge',
      payment_summary: { ...(fallback.payment_summary || {}), ...(invoice.payment_summary || {}) },
      order: invoice.order || fallback.order || null,
      product: invoice.product || fallback.product || null,
      scene: invoice.scene || fallback.scene || {},
    },
    payments: Array.isArray(payload.payments)
      ? (payload.payments as Record<string, unknown>[])
      : detailState.detail.payments,
    items: Array.isArray(payload.items) ? (payload.items as Record<string, unknown>[]) : [],
    logs: Array.isArray(payload.logs) ? (payload.logs as Record<string, unknown>[]) : [],
  };
}

function normalizeRechargeInvoice(row: RechargeRecord): InvoiceRecord {
  const invoice = toRecord(row.invoice);
  return {
    ...(invoice as InvoiceRecord),
    id: row.invoice_id as string | number | undefined,
    invoice_no: row.invoice_no,
    type: String(invoice.type || 'recharge'),
    user: row.user,
    amount: row.amount,
    paid_amount: row.paid_amount,
    status: row.status,
    paid_at: row.paid_at,
    created_at: row.created_at,
  };
}

function paymentRecord(row: RechargeRecord): Record<string, unknown> {
  return {
    id: row.id,
    payment_no: row.payment_no,
    gateway: row.gateway,
    trade_no: row.trade_no,
    amount: row.amount,
    status: row.status,
    paid_at: row.paid_at,
    created_at: row.created_at,
    invoice_id: row.invoice_id,
    invoice_no: row.invoice_no,
  };
}

function handleMobileAction(value: unknown, row: RechargeRecord) {
  if (value === 'detail') openDetail(row);
}

function mobileActionOptions() {
  return [{ content: t('finance.recharges.detailAction'), value: 'detail' }];
}

function rechargeMobileRows(row: RechargeRecord) {
  return [
    { label: t('finance.recharges.user'), value: userName(row.user) },
    { label: t('finance.recharges.paid'), value: formatMoney(row.paid_amount), strong: true },
    { label: t('finance.recharges.invoice'), value: fieldValue(row.invoice_no), show: Boolean(row.invoice_no) },
    { label: t('finance.recharges.thirdPartyNo'), value: fieldValue(row.trade_no), show: Boolean(row.trade_no) },
    { label: t('finance.recharges.createdLabel'), value: formatDateTime(row.created_at) },
    { label: t('finance.recharges.paidAtLabel'), value: formatDateTime(row.paid_at), show: Boolean(row.paid_at) },
  ];
}

function userName(user: unknown) {
  const record = toRecord(user);
  return fieldValue(record.nickname || record.display_name || record.email);
}

function paymentSummary(payment: unknown) {
  const record = toRecord(payment);
  return fieldValue([record.gateway, record.trade_no].filter(Boolean).join(' '));
}

function invoiceStatusLabel(status: unknown) {
  return statusLabelMap[String(status ?? '')] || fieldValue(status);
}

function invoiceStatusTheme(status: unknown): ThemeType {
  const value = statusTypeMap[String(status ?? '')] || 'default';
  if (value === 'warning' || value === 'success' || value === 'primary' || value === 'danger') return value;
  return 'default';
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

function goUserDetail(row: RechargeRecord) {
  goUser(router, toRecord(row.user).id || row.user_id);
}

function viewLinkedService(id: unknown) {
  const invoice = currentInvoice.value;
  goService(router, id, invoice.user_id || toRecord(invoice.user).id);
}

onMounted(() => loadList());
</script>
