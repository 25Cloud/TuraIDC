<template>
  <div class="finance-orders-page">
    <t-card :bordered="false">
      <div class="order-filter">
        <t-input
          v-model="filters.keyword"
          class="filter-keyword"
          clearable
          placeholder="搜索订单号 / 账单号 / 用户 / 服务"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select
          v-if="mode === 'orders' || mode === 'all'"
          v-model="filters.type"
          class="filter-type"
          clearable
          placeholder="类型"
          @change="handleSearch"
        >
          <t-option v-for="item in orderTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-select
          v-if="mode === 'upgrade'"
          v-model="filters.upgrade_kind"
          class="filter-type"
          clearable
          placeholder="配置类型"
          @change="handleSearch"
        >
          <t-option label="全部" value="all" />
          <t-option label="流量包" value="traffic_package" />
        </t-select>
        <t-select v-model="filters.status" class="filter-status" clearable placeholder="状态" @change="handleSearch">
          <t-option v-for="item in orderStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
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
        <t-table row-key="id" :data="orders" :columns="columns" :loading="loading" hover table-layout="fixed">
          <template #user="{ row }">
            <div class="stack-cell">
              <strong>{{ userName(row) }}</strong>
              <span>{{ fieldValue(row.user?.email) }}</span>
            </div>
          </template>
          <template #product="{ row }">
            <div class="stack-cell">
              <strong>{{ fieldValue(row.product_full_path || row.product_name) }}</strong>
              <span>{{ serviceIdLabel(row.service) }}</span>
            </div>
          </template>
          <template #type="{ row }">{{ row.type_label || orderTypeLabel(row.type) }}</template>
          <template #upgrade="{ row }">
            <div class="stack-cell">
              <strong>{{ row.upgrade_kind_label || '附加配置' }}</strong>
              <span>{{ fieldValue(row.upgrade_target_label || row.upgrade_mode) }}</span>
            </div>
          </template>
          <template #amount="{ row }">{{ formatMoney(row.amount) }}</template>
          <template #quantity="{ row }">{{ row.quantity || 1 }}</template>
          <template #status="{ row }">
            <status-tag :status-map="ORDER_STATUS_MAP" :status="row.status" />
          </template>
          <template #invoice="{ row }">
            <div class="stack-cell">
              <strong>{{ fieldValue(row.invoice?.invoice_no) }}</strong>
              <span v-if="row.invoice?.paid_at">支付：{{ formatDateTime(row.invoice.paid_at) }}</span>
            </div>
          </template>
          <template #createdAt="{ row }">{{ formatDateTime(row.created_at) }}</template>
          <template #operation="{ row }">
            <t-button size="small" variant="text" theme="primary" @click="goDetail(row)">详情</t-button>
          </template>
        </t-table>
      </div>

      <div v-else class="order-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="orders.length" class="order-mobile-stack">
            <mobile-record-card
              v-for="row in orders"
              :key="row.id"
              :title="fieldValue(row.order_no || row.id)"
              :eyebrow="mobileEyebrow"
              :subtitle="row.type_label || orderTypeLabel(row.type)"
              :description="fieldValue(row.product_full_path || row.product_name)"
              highlight-label="订单金额"
              :highlight-value="formatMoney(row.amount)"
              :status-map="ORDER_STATUS_MAP"
              :status="row.status"
              :rows="orderMobileRows(row)"
              :action-options="[{ content: '详情', value: 'detail' }]"
              @action="(value) => handleMobileAction(value, row)"
            />
          </div>
          <t-empty v-else description="暂无订单" />
        </t-loading>
      </div>

      <div v-if="total > 0" class="pagination-row">
        <t-pagination
          :current="pagination.page"
          :page-size="pagination.page_size"
          :total="total"
          :page-size-options="[20, 50, 100]"
          show-jumper
          @change="handlePaginationChange"
        />
      </div>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { ORDER_STATUS_MAP, ORDER_TYPE_MAP, toSelectOptions } from '@shared/statusConfig';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { OrderRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import StatusTag from '@/components/status-tag/index.vue';
import { useListPage } from '@/hooks/useListPage';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

type FinanceOrderMode = 'all' | 'orders' | 'renewals' | 'upgrade';

const ORDER_TAB_OPTIONS = [
  { value: 'all', label: '全部订单' },
  { value: 'orders', label: '普通订单' },
  { value: 'renewals', label: '续费订单' },
  { value: 'upgrade', label: '附加配置' },
];

const route = useRoute();
const router = useRouter();
const isMobile = useMediaQuery('(max-width: 768px)');

function normalizeMode(value: unknown): FinanceOrderMode | null {
  return ORDER_TAB_OPTIONS.some((o) => o.value === value) ? (value as FinanceOrderMode) : null;
}

function resolveTabFromRoute(): FinanceOrderMode {
  const q = route.query.tab as string;
  return normalizeMode(q) || normalizeMode(route.meta.orderTab) || 'all';
}
const activeTab = ref<FinanceOrderMode>(resolveTabFromRoute());

function syncTabFromRoute() {
  activeTab.value = resolveTabFromRoute();
}

onMounted(syncTabFromRoute);
watch(() => [route.path, route.query.tab, route.meta.orderTab], syncTabFromRoute);

const orderTypeOptions = Object.entries(ORDER_TYPE_MAP).map(([value, label]) => ({ value, label }));
const mode = computed<FinanceOrderMode>(() => activeTab.value);
const orderStatusOptions = computed(() => toSelectOptions(ORDER_STATUS_MAP, false));
const mobileEyebrow = computed(() => {
  const found = ORDER_TAB_OPTIONS.find((o) => o.value === mode.value);
  return found?.label || '订单管理';
});

const {
  filters,
  list: orders,
  total,
  loading,
  pagination,
  handleSearch,
  resetFilters,
  handlePaginationChange,
} = useListPage<Record<string, any>, OrderRecord>({
  defaultFilters: {
    keyword: '',
    type: '',
    upgrade_kind: 'all',
    status: '',
    start_date: '',
    end_date: '',
  },
  defaultPageSize: 20,
  onError: (error) => MessagePlugin.error(errorMessage(error, '加载订单列表失败')),
  fetch: async () => {
    const apiCall =
      mode.value === 'renewals'
        ? adminApi.financeMenu.renewalOrders
        : mode.value === 'upgrade'
          ? adminApi.financeMenu.upgradeOrders
          : adminApi.orders.list;
    const response = await apiCall(buildParams());
    return response;
  },
});

const columns = computed<PrimaryTableCol<OrderRecord>[]>(() => {
  const base: PrimaryTableCol<OrderRecord>[] = [
    { colKey: 'order_no', title: '订单号', minWidth: 170 },
    { colKey: 'user', title: '用户', minWidth: 180 },
    { colKey: 'product', title: '产品/服务', minWidth: 240 },
    { colKey: 'type', title: '类型', width: 110 },
  ];
  if (mode.value === 'upgrade') {
    base.push({ colKey: 'upgrade', title: '配置', minWidth: 140 });
  }
  base.push(
    { colKey: 'amount', title: '金额', width: 120 },
    { colKey: 'quantity', title: '数量', width: 80 },
    { colKey: 'status', title: '状态', width: 110 },
    { colKey: 'invoice', title: '关联账单', minWidth: 170 },
    { colKey: 'createdAt', title: '时间', width: 170 },
    { colKey: 'operation', title: '操作', width: 80, fixed: 'right' },
  );
  return base;
});

function buildParams() {
  const params: Record<string, unknown> = {
    page: pagination.page,
    page_size: pagination.page_size,
  };
  if (filters.keyword) params.keyword = filters.keyword;
  if (filters.status !== '') params.status = filters.status;
  if ((mode.value === 'orders' || mode.value === 'all') && filters.type) params.type = filters.type;
  if (mode.value === 'upgrade') params.upgrade_kind = filters.upgrade_kind || 'all';
  if (filters.start_date) params.start_date = filters.start_date;
  if (filters.end_date) params.end_date = filters.end_date;
  return params;
}

function goDetail(row: OrderRecord) {
  router.push(`/admin/finance/orders/${row.id}`);
}

function handleMobileAction(value: unknown, row: OrderRecord) {
  if (value === 'detail') goDetail(row);
}

function orderMobileRows(row: OrderRecord) {
  return [
    { label: '用户', value: userName(row) },
    { label: '数量', value: String(row.quantity || 1) },
    { label: '服务', value: serviceIdLabel(row.service) },
    { label: '账单', value: fieldValue(row.invoice?.invoice_no) },
    { label: '时间', value: formatDateTime(row.created_at) },
  ];
}

// Tab 切换时重置筛选并重新加载
watch(activeTab, () => resetFilters());

function userName(row: OrderRecord) {
  const user = toRecord(row.user);
  return fieldValue(user.nickname || user.display_name || user.email || (row.user_id ? `用户 #${row.user_id}` : ''));
}

function serviceIdLabel(service: unknown) {
  const record = toRecord(service);
  return fieldValue(record.service_id || record.id);
}

function orderTypeLabel(type: unknown) {
  return ORDER_TYPE_MAP[String(type || '')] || fieldValue(type);
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}
</script>
