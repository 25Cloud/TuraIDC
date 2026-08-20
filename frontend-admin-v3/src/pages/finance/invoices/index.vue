<template>
  <div class="finance-invoices-page">
    <t-card :bordered="false">
      <div class="invoice-filter">
        <t-input
          v-model="filters.keyword"
          class="filter-keyword"
          clearable
          placeholder="搜索账单号 / 订单号 / 用户"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.type" class="filter-type" clearable placeholder="类型" @change="handleSearch">
          <t-option v-for="item in invoiceTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-select v-model="filters.status" class="filter-status" clearable placeholder="状态" @change="handleSearch">
          <t-option v-for="item in invoiceStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-date-picker
          v-model="filters.start_date"
          class="filter-start"
          clearable
          mode="date"
          format="YYYY-MM-DD"
          value-type="YYYY-MM-DD"
          placeholder="开始日期"
          @change="handleSearch"
        />
        <t-date-picker
          v-model="filters.end_date"
          class="filter-end"
          clearable
          mode="date"
          format="YYYY-MM-DD"
          value-type="YYYY-MM-DD"
          placeholder="结束日期"
          @change="handleSearch"
        />
      </div>

      <div v-if="!isMobile" class="table-scroll">
        <t-table row-key="id" :data="invoices" :columns="columns" :loading="loading" hover table-layout="fixed">
          <template #user="{ row }">
            <div class="stack-cell">
              <strong>{{ userName(row.user) }}</strong>
              <span>{{ fieldValue(row.user?.email) }}</span>
            </div>
          </template>
          <template #item="{ row }">
            <div class="stack-cell">
              <button
                type="button"
                class="user-link"
                :disabled="!Number(toRecord(row.service).id || 0)"
                @click="goServiceDetail(row)"
              >
                <strong>{{ invoiceTitle(row) }}</strong>
              </button>
              <span>{{ fieldValue(row.order?.order_no || row.summary?.highlight || row.order_no) }}</span>
            </div>
          </template>
          <template #type="{ row }">{{ row.type_label || invoiceTypeLabel(row.type) }}</template>
          <template #amount="{ row }">{{ formatMoney(row.amount) }}</template>
          <template #paid="{ row }">{{ formatMoney(row.paid_amount) }}</template>
          <template #status="{ row }">
            <status-tag :status-map="INVOICE_STATUS_MAP" :status="row.status" />
          </template>
          <template #createdAt="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #paidAt="{ row }">{{ formatDateTime(row.paid_at) }}</template>
          <template #operation="{ row }">
            <t-space v-if="!isMobile" size="small">
              <t-button size="small" variant="text" theme="primary" @click="openDetail(row)">详情</t-button>
              <t-button
                v-if="canCancel(row)"
                size="small"
                variant="text"
                theme="danger"
                :loading="cancelLoadingId === row.id"
                @click="confirmCancel(row)"
              >
                取消
              </t-button>
            </t-space>
            <t-dropdown v-else :options="mobileActionOptions(row)" @click="handleMobileActionHandler(row)">
              <t-button size="small" variant="text">更多</t-button>
            </t-dropdown>
          </template>
        </t-table>
      </div>

      <div v-else class="invoice-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="invoices.length" class="invoice-mobile-stack">
            <mobile-record-card
              v-for="row in invoices"
              :key="row.id"
              :title="fieldValue(row.invoice_no || row.id)"
              eyebrow="账单管理"
              :subtitle="row.type_label || invoiceTypeLabel(row.type)"
              :description="invoiceTitle(row)"
              highlight-label="账单金额"
              :highlight-value="formatMoney(row.amount)"
              :status-map="INVOICE_STATUS_MAP"
              :status="row.status"
              :rows="invoiceMobileRows(row)"
              :action-options="mobileActionOptions(row)"
              @action="(value) => handleMobileAction(value, row)"
            />
          </div>
          <t-empty v-else description="暂无账单" />
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
      :cancelable="canCancel(currentInvoice)"
      :cancel-loading="detailState.cancelLoading"
      @close="closeDetail"
      @refresh="reloadDetail"
      @cancel="confirmCancel(currentInvoice, true)"
      @view-order="(id) => id && router.push(`/admin/finance/orders/${id}`)"
      @view-user="(id) => id && router.push(`/admin/users/${id}`)"
      @view-service="(id) => viewLinkedService(id, currentInvoice)"
    />
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { INVOICE_STATUS_MAP, INVOICE_TYPE_MAP, toLabelMap, toTagTypeMap } from '@shared/statusConfig';
import type { DropdownOption, PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import type { InvoiceRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import InvoiceDetailDrawer from '@/components/finance-record-detail/InvoiceDetailDrawer.vue';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import StatusTag from '@/components/status-tag/index.vue';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { useUserStore } from '@/store';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

const loading = ref(false);
const invoices = ref<InvoiceRecord[]>([]);
const total = ref(0);
const cancelLoadingId = ref<number | string | null>(null);
const userStore = useUserStore();
const router = useRouter();
const isMobile = useMediaQuery('(max-width: 768px)');

const filters = reactive({
  keyword: '',
  type: '',
  status: '',
  start_date: '',
  end_date: '',
});

const pagination = reactive({
  page: 1,
  page_size: 20,
});

const detailState = reactive({
  visible: false,
  loading: false,
  cancelLoading: false,
  currentId: 0,
  detail: {
    invoice: {} as InvoiceRecord,
    payments: [] as Record<string, unknown>[],
    items: [] as Record<string, unknown>[],
    logs: [] as Record<string, unknown>[],
  },
});

const statusLabelMap = toLabelMap(INVOICE_STATUS_MAP);
const statusTypeMap = toTagTypeMap(INVOICE_STATUS_MAP);

const invoiceTypeOptions = Object.entries(INVOICE_TYPE_MAP)
  .filter(([value]) => value !== 'normal') // 历史兼容别名，不单独列出
  .map(([value, label]) => ({ value, label }));
const invoiceStatusOptions = Object.entries(statusLabelMap).map(([value, label]) => ({ value, label }));

const columns: PrimaryTableCol<InvoiceRecord>[] = [
  { colKey: 'invoice_no', title: '账单号', minWidth: 170 },
  { colKey: 'user', title: '用户', minWidth: 180 },
  { colKey: 'item', title: '账单项目', minWidth: 240 },
  { colKey: 'type', title: '类型', width: 120 },
  { colKey: 'amount', title: '金额', width: 120 },
  { colKey: 'paid', title: '已付', width: 120 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'createdAt', title: '创建时间', width: 170 },
  { colKey: 'paidAt', title: '支付时间', width: 170 },
  { colKey: 'operation', title: '操作', width: 130, fixed: 'right' },
];

const currentInvoice = computed(() => detailState.detail.invoice || ({} as InvoiceRecord));
const invoicePayments = computed(() => detailState.detail.payments || []);
const invoiceItems = computed(() => {
  const sceneItems = currentInvoice.value.scene?.items;
  if (Array.isArray(sceneItems)) return sceneItems as Record<string, unknown>[];
  return detailState.detail.items || [];
});
const invoiceLogs = computed(() => detailState.detail.logs || []);

function userPermissions() {
  const info = userStore.userInfo as { permissions?: string[] };
  return Array.isArray(info.permissions) ? info.permissions : [];
}

function hasPermission(permission: string) {
  const permissions = userPermissions();
  return hasPermissionInList(permissions, permission);
}

function buildParams() {
  const params: Record<string, unknown> = {
    page: pagination.page,
    page_size: pagination.page_size,
  };
  if (filters.keyword) params.keyword = filters.keyword;
  if (filters.type) params.type = filters.type;
  if (filters.status !== '') params.status = filters.status;
  if (filters.start_date) params.start_date = filters.start_date;
  if (filters.end_date) params.end_date = filters.end_date;
  return params;
}

async function loadList() {
  loading.value = true;
  try {
    const response = await adminApi.invoices.list(buildParams());
    invoices.value = response.list || [];
    total.value = Number(response.total || 0);
    pagination.page = Number(response.page || pagination.page);
    pagination.page_size = Number(response.page_size || pagination.page_size);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载账单列表失败'));
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

async function openDetail(row: InvoiceRecord) {
  if (!row.id) return;
  detailState.visible = true;
  detailState.currentId = Number(row.id);
  detailState.detail = { invoice: row, payments: [], items: [], logs: [] };
  await reloadDetail();
}

async function reloadDetail() {
  if (!detailState.currentId) return;
  detailState.loading = true;
  try {
    const response = await adminApi.invoices.detail(detailState.currentId);
    detailState.detail = normalizeInvoiceDetail(response, currentInvoice.value);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载账单详情失败'));
  } finally {
    detailState.loading = false;
  }
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
      payment_summary: { ...(fallback.payment_summary || {}), ...(invoice.payment_summary || {}) },
      order: invoice.order || fallback.order || null,
      product: invoice.product || fallback.product || null,
      scene: invoice.scene || fallback.scene || {},
    },
    payments: Array.isArray(payload.payments) ? (payload.payments as Record<string, unknown>[]) : [],
    items: Array.isArray(payload.items) ? (payload.items as Record<string, unknown>[]) : [],
    logs: Array.isArray(payload.logs) ? (payload.logs as Record<string, unknown>[]) : [],
  };
}

function closeDetail() {
  detailState.visible = false;
  detailState.currentId = 0;
  detailState.cancelLoading = false;
  detailState.detail = { invoice: {}, payments: [], items: [], logs: [] };
}

function canCancel(row: InvoiceRecord) {
  const status = Number(row.status ?? -1);
  return hasPermission(AdminPermissions.INVOICE_MANAGE) && [0, 3].includes(status);
}

function confirmCancel(row: InvoiceRecord, fromDrawer = false) {
  const dialog = DialogPlugin.confirm({
    header: '取消账单',
    body: `确认取消账单「${row.invoice_no || row.id}」吗？`,
    theme: 'warning',
    confirmBtn: '确认取消',
    cancelBtn: '取消',
    async onConfirm() {
      await cancelInvoice(row, fromDrawer);
      dialog.hide();
    },
  });
}

async function cancelInvoice(row: InvoiceRecord, fromDrawer = false) {
  if (!row.id) return;
  if (fromDrawer) detailState.cancelLoading = true;
  cancelLoadingId.value = row.id;
  try {
    await adminApi.invoices.cancel(row.id);
    MessagePlugin.success('账单已取消');
    await loadList();
    if (detailState.currentId === Number(row.id)) await reloadDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '取消账单失败'));
  } finally {
    cancelLoadingId.value = null;
    detailState.cancelLoading = false;
  }
}

function mobileActionOptions(row: InvoiceRecord) {
  return [
    { content: '详情', value: 'detail' },
    { content: '取消', value: 'cancel', disabled: !canCancel(row) },
  ];
}

function handleMobileActionHandler(row: InvoiceRecord) {
  return (data: DropdownOption) => handleMobileAction(data.value, row);
}

function handleMobileAction(action: unknown, row: InvoiceRecord) {
  if (action === 'detail') openDetail(row);
  if (action === 'cancel' && canCancel(row)) confirmCancel(row);
}

function invoiceMobileRows(row: InvoiceRecord) {
  return [
    { label: '用户', value: userName(row.user) },
    { label: '已付', value: formatMoney(row.paid_amount) },
    { label: '订单', value: fieldValue(row.order?.order_no || row.order_no) },
    { label: '创建', value: formatDateTime(row.created_at) },
    { label: '支付', value: formatDateTime(row.paid_at), show: Boolean(row.paid_at) },
  ];
}

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

function goServiceDetail(row: InvoiceRecord) {
  const service = toRecord(row.service);
  const id = Number(service.id || 0);
  if (!id) return;
  router.push({
    path: `/admin/services/${id}`,
    query: { user: String(row.user_id || (toRecord(row.user).id as number) || '') },
  });
}

function viewLinkedService(id: unknown, row: InvoiceRecord) {
  if (!Number(id || 0)) return;
  router.push({
    path: `/admin/services/${Number(id)}`,
    query: { user: String(row.user_id || (toRecord(row.user).id as number) || '') },
  });
}

function invoiceTypeLabel(type: unknown) {
  return INVOICE_TYPE_MAP[String(type || '')] || fieldValue(type);
}

function invoiceStatusLabel(status: unknown) {
  return statusLabelMap[String(status ?? '')] || fieldValue(status);
}

function invoiceStatusTheme(status: unknown) {
  const value = statusTypeMap[String(status ?? '')] || 'default';
  return value === 'info' ? 'default' : value;
}

function userName(user: unknown) {
  const record = toRecord(user);
  return fieldValue(record.nickname || record.display_name || record.email);
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

onMounted(() => loadList());
</script>
