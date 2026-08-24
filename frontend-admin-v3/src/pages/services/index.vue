<template>
  <div class="services-page">
    <t-card :bordered="false">
      <div class="service-filter">
        <t-select v-model="filters.status" clearable placeholder="主机状态" @change="handleSearch">
          <t-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-input
          v-model="filters.keyword"
          clearable
          placeholder="搜索主机ID / 主机IP / 实例ID / 用户名 / 账单号"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-button v-if="!isMobile" variant="outline" :disabled="!selectedRowKeys.length" @click="openHostnameDialog">
          批量主机名<span v-if="selectedRowKeys.length">({{ selectedRowKeys.length }})</span>
        </t-button>
        <t-button v-if="!isMobile" variant="text" :disabled="!selectedRowKeys.length" @click="clearSelection"
          >清空选择</t-button
        >
      </div>

      <div v-if="!isMobile" class="table-scroll">
        <t-table
          row-key="id"
          :data="services"
          :columns="columns"
          :loading="loading"
          :selected-row-keys="selectedRowKeys"
          hover
          table-layout="fixed"
          @select-change="handleSelectChange"
        >
          <template #service="{ row }">
            <div class="service-cell">
              <div class="service-primary">
                <button
                  type="button"
                  class="user-link"
                  :disabled="!Number(row.service_id || row.id || 0)"
                  @click="goServiceDetail(row)"
                >
                  <strong>服务/实例 #{{ fieldValue(row.service_id || row.id) }}</strong>
                </button>
                <t-tag v-if="row.invoice?.id" variant="light">账单 #{{ row.invoice.id }}</t-tag>
              </div>
              <span v-if="row.invoice?.invoice_no">账单号 {{ row.invoice.invoice_no }}</span>
            </div>
          </template>
          <template #host="{ row }">
            <div class="host-cell">
              <span v-if="row.upstream_host_id_text || row.upstream_host_id">
                <em>上游</em>{{ row.upstream_host_id_text || row.upstream_host_id }}
              </span>
              <span v-if="row.host_ips?.length"><em>IP</em>{{ row.host_ips.join(' / ') }}</span>
              <span v-if="row.host_username || row.connection?.username">
                <em>登录</em>{{ row.host_username || row.connection?.username }}
              </span>
              <span v-if="!hasHostInfo(row)" class="muted">-</span>
            </div>
          </template>
          <template #user="{ row }">
            <button type="button" class="user-link" :disabled="!row.user?.id" @click="goUserDetail(row)">
              <strong>{{ userName(row.user) }}</strong>
              <span>{{ fieldValue(row.user?.email) }}</span>
            </button>
          </template>
          <template #product="{ row }">
            <button
              type="button"
              class="user-link"
              :disabled="!Number(row.service_id || row.id || 0)"
              @click="goServiceDetail(row)"
            >
              <strong>{{
                fieldValue(
                  row.product_full_path ||
                    row.product_display_name ||
                    row.product?.display_name ||
                    (row.product_id ? `未配置规格 #${row.product_id}` : ''),
                )
              }}</strong>
              <span>{{ fieldValue(row.domain) }}</span>
            </button>
          </template>
          <template #status="{ row }">
            <status-tag :status-map="SERVICE_STATUS_MAP" :status="row.status" />
          </template>
          <template #billing="{ row }">
            <div class="billing-cell">
              <strong>{{ formatMoney(row.amount) }}</strong>
              <span>{{ billingCycleLabel(row.billing_cycle) }}</span>
            </div>
          </template>
          <template #expires="{ row }">
            <span :class="{ 'expiring-soon': isExpiringSoon(row.expires_at) }">{{ shortDate(row.expires_at) }}</span>
          </template>
          <template #created="{ row }">{{ shortDate(row.created_at) }}</template>
          <template #operation="{ row }">
            <t-button
              variant="text"
              theme="primary"
              size="small"
              :disabled="!Number(row.service_id || row.id || 0)"
              @click="goServiceDetail(row)"
            >
              管理实例
            </t-button>
          </template>
        </t-table>
      </div>

      <div v-else class="service-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="services.length" class="service-mobile-stack">
            <mobile-record-card
              v-for="row in services"
              :key="row.id"
              :title="serviceTitle(row)"
              eyebrow="服务列表"
              linkable
              :link-disabled="!Number(row.service_id || row.id || 0)"
              :subtitle="
                fieldValue(
                  row.invoice?.invoice_no
                    ? `账单 ${row.invoice.invoice_no}`
                    : row.upstream_host_id_text || row.upstream_host_id,
                )
              "
              :description="
                fieldValue(row.product_full_path || row.product_display_name || row.product?.display_name || row.domain)
              "
              highlight-label="服务金额"
              :highlight-value="formatMoney(row.amount)"
              :status-map="SERVICE_STATUS_MAP"
              :status="row.status"
              :rows="serviceMobileRows(row)"
              :action-options="mobileActionOptions(row)"
              @title-click="goServiceDetail(row)"
              @action="(value) => handleMobileAction(value, row)"
            />
          </div>
          <t-empty v-else description="暂无服务" />
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

    <t-dialog
      v-model:visible="hostnameDialogVisible"
      :header="hostnameRows.length > 1 ? '批量设置自定义主机名' : '设置自定义主机名'"
      width="920px"
      :confirm-btn="{ content: '保存', loading: hostnameSubmitting }"
      @confirm="submitHostnames"
    >
      <t-alert theme="info" message="留空目标主机名会清空对应服务的自定义主机名。" />
      <div class="hostname-table">
        <t-table
          row-key="service_id"
          :data="hostnameRows"
          :columns="hostnameColumns"
          table-layout="fixed"
          max-height="420"
        >
          <template #service="{ row }">
            <div class="hostname-service">
              <strong>{{ fieldValue(row.service_name) }}</strong>
              <span>{{ fieldValue(row.product_name) }}</span>
              <span>{{ fieldValue(row.user_name) }}</span>
            </div>
          </template>
          <template #current="{ row }">
            <div class="hostname-current">
              <span>{{ fieldValue(row.current_domain) }}</span>
              <small v-if="row.current_custom_hostname">当前自定义：{{ row.current_custom_hostname }}</small>
            </div>
          </template>
          <template #hostname="{ row }">
            <t-input v-model="row.hostname" clearable maxlength="200" placeholder="留空则清空自定义主机名" />
          </template>
        </t-table>
      </div>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { billingCycleLabel as billingCycleLabelOf } from '@shared/billingCycle';
import { SERVICE_STATUS_MAP, toSelectOptions } from '@shared/statusConfig';
import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import type { ServiceRecord } from '@/api/service';
import { serviceApi } from '@/api/service';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import StatusTag from '@/components/status-tag/index.vue';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { fieldValue, formatMoney } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminServices' });

interface HostnameRow {
  service_id: number;
  service_name: string;
  product_name: string;
  user_name: string;
  current_domain: string;
  current_custom_hostname: string;
  hostname: string;
}

const router = useRouter();
const loading = ref(false);
const services = ref<ServiceRecord[]>([]);
const total = ref(0);
const selectedRowKeys = ref<Array<string | number>>([]);
const hostnameDialogVisible = ref(false);
const hostnameSubmitting = ref(false);
const hostnameRows = ref<HostnameRow[]>([]);
const isMobile = useMediaQuery('(max-width: 768px)');

const filters = reactive({
  keyword: '',
  status: '',
});

const pagination = reactive({
  page: 1,
  page_size: 20,
});

const statusOptions = computed(() =>
  toSelectOptions(SERVICE_STATUS_MAP, false).map((item) => ({ ...item, value: String(item.value) })),
);

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'row-select', type: 'multiple', width: 54, fixed: 'left' },
  { colKey: 'service', title: '服务', minWidth: 280 },
  { colKey: 'host', title: '主机信息', minWidth: 240 },
  { colKey: 'user', title: '用户', minWidth: 180 },
  { colKey: 'product', title: '配置', minWidth: 180 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'billing', title: '计费/金额', width: 130 },
  { colKey: 'expires', title: '到期时间', width: 130 },
  { colKey: 'created', title: '开通时间', width: 120 },
  { colKey: 'operation', title: '操作', width: 110 },
];

const hostnameColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'service_id', title: '服务ID', width: 90 },
  { colKey: 'service', title: '服务信息', minWidth: 240 },
  { colKey: 'current', title: '当前展示', minWidth: 170 },
  { colKey: 'hostname', title: '目标主机名', minWidth: 240 },
];

const selectedRows = computed(() => {
  const selected = new Set(selectedRowKeys.value.map((item) => String(item)));
  return services.value.filter((row) => selected.has(String(row.id)));
});

function buildParams() {
  const params: Record<string, unknown> = {
    page: pagination.page,
    page_size: pagination.page_size,
  };
  if (filters.keyword.trim()) params.keyword = filters.keyword.trim();
  if (filters.status !== '') params.status = filters.status;
  return params;
}

async function loadList() {
  loading.value = true;
  try {
    const response = await serviceApi.list(buildParams());
    services.value = response.list || [];
    total.value = Number(response.total || 0);
    selectedRowKeys.value = [];
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载服务列表失败'));
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  pagination.page = 1;
  loadList();
}

function handlePageChange(data: PageInfo) {
  pagination.page = data.current;
  pagination.page_size = data.pageSize;
  loadList();
}

function handleSelectChange(keys: Array<string | number>) {
  selectedRowKeys.value = keys;
}

function clearSelection() {
  selectedRowKeys.value = [];
}

function openHostnameDialog() {
  if (!selectedRows.value.length) {
    MessagePlugin.warning('请先选择需要批量设置的服务');
    return;
  }
  hostnameRows.value = selectedRows.value.map(buildHostnameRow);
  hostnameDialogVisible.value = true;
}

async function submitHostnames() {
  if (!hostnameRows.value.length) return;
  hostnameSubmitting.value = true;
  try {
    const response = await serviceApi.batchUpdateCustomHostnames({
      items: hostnameRows.value.map((row) => ({
        service_id: Number(row.service_id || 0),
        hostname: row.hostname || '',
      })),
    });
    MessagePlugin.success(response.message || '自定义主机名已更新');
    hostnameDialogVisible.value = false;
    await loadList();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存自定义主机名失败'));
  } finally {
    hostnameSubmitting.value = false;
  }
}

function buildHostnameRow(row: ServiceRecord): HostnameRow {
  return {
    service_id: Number(row.id || 0),
    service_name: String(row.service_id || row.id || ''),
    product_name: String(
      row.product_full_path ||
        row.product_display_name ||
        row.product?.display_name ||
        (row.product_id ? `未配置规格 #${row.product_id}` : ''),
    ),
    user_name: userName(row.user),
    current_domain: String(row.domain || ''),
    current_custom_hostname: String(row.custom_hostname || ''),
    hostname: String(row.custom_hostname || ''),
  };
}

function goUserDetail(row: ServiceRecord) {
  if (row.user?.id) {
    router.push({ name: 'AdminUserDetail', params: { id: String(row.user.id) } });
  }
}

function goServiceDetail(row: ServiceRecord) {
  const id = Number(row.service_id || row.id || 0);
  if (!id) return;
  router.push({ path: `/admin/services/${id}`, query: { user: String(row.user?.id || '') } });
}

function mobileActionOptions(row: ServiceRecord) {
  return [
    { content: '管理实例', value: 'service', disabled: !Number(row.service_id || row.id || 0) },
    { content: '查看用户', value: 'user', disabled: !row.user?.id },
  ];
}

function handleMobileAction(value: unknown, row: ServiceRecord) {
  if (value === 'service') goServiceDetail(row);
  if (value === 'user') goUserDetail(row);
}

function serviceMobileRows(row: ServiceRecord) {
  return [
    { label: '用户', value: userName(row.user) },
    { label: '主机', value: hostSummary(row) },
    { label: '周期', value: billingCycleLabel(row.billing_cycle) },
    { label: '到期', value: shortDate(row.expires_at), strong: isExpiringSoon(row.expires_at) },
    { label: '开通', value: shortDate(row.created_at) },
  ];
}

function serviceTitle(row: ServiceRecord) {
  return `服务 ID ${fieldValue(row.service_id || row.id)}`;
}

function hostSummary(row: ServiceRecord) {
  if (row.host_ips?.length) return row.host_ips.join(' / ');
  return fieldValue(row.upstream_host_id_text || row.upstream_host_id || row.host_username || row.connection?.username);
}

function userName(user: unknown) {
  const record = toRecord(user);
  return fieldValue(record.username || record.nickname || record.display_name || record.email);
}

function hasHostInfo(row: ServiceRecord) {
  return Boolean(
    row.upstream_host_id_text ||
    row.upstream_host_id ||
    row.host_ips?.length ||
    row.host_username ||
    row.connection?.username,
  );
}

function billingCycleLabel(cycle: unknown) {
  // 别名（biannually 拼写错误、onetime、yearly）统一由 @shared/billingCycle 归一
  return billingCycleLabelOf(cycle) || fieldValue(cycle);
}

function isExpiringSoon(value: unknown) {
  if (!value) return false;
  const diff = new Date(String(value).replace(/-/g, '/')).getTime() - Date.now();
  return diff > 0 && diff < 7 * 24 * 3600 * 1000;
}

function shortDate(value: unknown) {
  if (!value) return '-';
  return String(value).slice(0, 10);
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

onMounted(() => loadList());
</script>
