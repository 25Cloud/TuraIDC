<template>
  <div class="tickets-page">
    <t-card :bordered="false">
      <div class="tickets-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          :placeholder="$t('tickets.index.searchPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select
          v-model="filters.status"
          clearable
          :placeholder="$t('tickets.index.statusPlaceholder')"
          @change="handleSearch"
        >
          <t-option v-for="option in statusOptions" :key="option.value" :label="option.label" :value="option.value" />
        </t-select>
        <t-select
          v-model="filters.priority"
          clearable
          :placeholder="$t('tickets.index.priorityPlaceholder')"
          @change="handleSearch"
        >
          <t-option v-for="option in priorityOptions" :key="option.value" :label="option.label" :value="option.value" />
        </t-select>
        <t-select
          v-model="filters.department"
          clearable
          :placeholder="$t('tickets.index.departmentPlaceholder')"
          @change="handleSearch"
        >
          <t-option
            v-for="option in departmentOptions"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </t-select>
      </div>

      <div v-if="!isMobile" class="admin-list-toolbar">
        <admin-view-mode-switch inline :mode="viewMode" @change="setViewMode" />
      </div>
    </t-card>

    <t-loading :loading="loading" size="small">
      <!-- 卡片式是本页既有形态；列表式给需要逐行扫状态与优先级的场景 -->
      <div v-if="isMobile || viewMode === 'card'" class="ticket-grid">
        <article v-for="item in list" :key="item.id" class="ticket-card" @click="openDetail(item.id)">
          <div class="ticket-card__head">
            <span>#{{ item.id }}</span>
            <t-tag :theme="statusTheme(item.status)" variant="light">{{ statusLabel(item.status) }}</t-tag>
          </div>
          <h2>{{ item.subject || '-' }}</h2>
          <div class="ticket-card__meta">
            <span>
              <user-icon />
              <button type="button" @click.stop="goUserDetail(item.user?.id || item.user_id)">
                {{ userName(item) }}
              </button>
            </span>
            <span>{{ $t('tickets.index.assignee', { name: assigneeName(item) }) }}</span>
          </div>
          <div class="ticket-card__foot">
            <t-tag :theme="priorityTheme(item.priority)" variant="light">{{ priorityLabel(item.priority) }}</t-tag>
            <t-tag theme="default" variant="light">{{ departmentLabel(item.department) }}</t-tag>
            <time>{{ formatDateTime(item.updated_at || item.created_at) }}</time>
          </div>
        </article>
      </div>
      <div v-else class="table-scroll">
        <t-table
          row-key="id"
          :data="list"
          :columns="columns"
          hover
          table-layout="fixed"
          @row-click="handleRowClick"
        >
          <template #id="{ row }">
            <button type="button" class="ticket-id-link" @click.stop="openDetail(row.id)">#{{ row.id }}</button>
          </template>
          <template #subject="{ row }">
            <button type="button" class="ticket-subject-link" @click.stop="openDetail(row.id)">
              {{ row.subject || '-' }}
            </button>
          </template>
          <template #user="{ row }">
            <button
              type="button"
              class="ticket-user-link"
              @click.stop="goUserDetail(row.user?.id || row.user_id)"
            >
              {{ userName(row) }}
            </button>
          </template>
          <template #status="{ row }">
            <t-tag :theme="statusTheme(row.status)" variant="light">{{ statusLabel(row.status) }}</t-tag>
          </template>
          <template #priority="{ row }">
            <t-tag :theme="priorityTheme(row.priority)" variant="light">{{ priorityLabel(row.priority) }}</t-tag>
          </template>
          <template #department="{ row }">{{ departmentLabel(row.department) }}</template>
          <template #updatedAt="{ row }">{{ formatDateTime(row.updated_at || row.created_at) }}</template>
        </t-table>
      </div>
      <t-empty v-if="!list.length && !loading" :description="$t('tickets.index.empty')" />
    </t-loading>

    <div v-if="total > 0" class="ticket-pagination">
      <t-pagination
        :current="page"
        :page-size="pageSize"
        :total="total"
        :page-size-options="[20, 50, 100]"
        show-jumper
        @change="handlePageChange"
      />
    </div>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { useMediaQuery } from '@vueuse/core';
import { SearchIcon, UserIcon } from 'tdesign-icons-vue-next';
import type { PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import type { TicketRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import AdminViewModeSwitch from '@/components/admin-view-mode-switch/index.vue';
import { useAdminViewMode } from '@/composables/useAdminViewMode';
import { formatDateTime } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';
import { t } from '@/locales';

defineOptions({ name: 'AdminTickets' });

const DEFAULT_STATUS_FILTER = 'ongoing';

const router = useRouter();
// 与其它列表页同一个断点（含 768px），避免平板横屏在「显示切换入口」与「卡片视图」之间错位
const isMobile = useMediaQuery('(max-width: 768px)');
// 工单原本只有卡片形态，默认值取 card，切换过之后按页面偏好记住
const { mode: viewMode, setMode: setViewMode } = useAdminViewMode('admin-tickets', 'card');

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'id', title: t('tickets.index.columnId'), width: 90 },
  { colKey: 'subject', title: t('tickets.index.columnSubject'), minWidth: 260 },
  { colKey: 'user', title: t('tickets.index.columnUser'), width: 160 },
  { colKey: 'status', title: t('tickets.index.columnStatus'), width: 120 },
  { colKey: 'priority', title: t('tickets.index.columnPriority'), width: 100 },
  { colKey: 'department', title: t('tickets.index.columnDepartment'), width: 120 },
  { colKey: 'updatedAt', title: t('tickets.index.columnUpdatedAt'), width: 170 },
];

const loading = ref(false);
const list = ref<TicketRecord[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const filters = reactive({
  keyword: '',
  status: DEFAULT_STATUS_FILTER as string | number,
  priority: '' as string | number,
  department: '',
});

const statusOptions = [
  { label: t('tickets.index.statusInProgress'), value: DEFAULT_STATUS_FILTER },
  { label: t('tickets.index.statusOpen'), value: 0 },
  { label: t('tickets.index.statusCustomerReply'), value: 1 },
  { label: t('tickets.index.statusStaffReply'), value: 2 },
  { label: t('tickets.index.statusClosed'), value: 3 },
];
const priorityOptions = [
  { label: '低', value: 1 },
  { label: '中', value: 2 },
  { label: '高', value: 3 },
  { label: t('tickets.index.priorityUrgent'), value: 4 },
];
const departmentOptions = [
  { label: t('tickets.index.departmentSales'), value: 'sales' },
  { label: t('tickets.index.departmentSupport'), value: 'support' },
  { label: t('tickets.index.departmentBilling'), value: 'billing' },
  { label: t('tickets.index.departmentAbuse'), value: 'abuse' },
];

async function loadList() {
  loading.value = true;
  try {
    const response = await adminApi.tickets.list({
      ...filters,
      page: page.value,
      page_size: pageSize.value,
    });
    list.value = Array.isArray(response.list) ? response.list : [];
    total.value = Number(response.total || 0);
    page.value = Number(response.page || page.value);
    pageSize.value = Number(response.page_size || pageSize.value);
  } catch (error) {
    list.value = [];
    total.value = 0;
    MessagePlugin.error(errorMessage(error, '加载工单列表失败'));
  } finally {
    loading.value = false;
  }
}

function handleSearch() {
  page.value = 1;
  loadList();
}

function handlePageChange(pageInfo: PageInfo) {
  page.value = pageInfo.current;
  pageSize.value = pageInfo.pageSize;
  loadList();
}

function openDetail(id: number | string) {
  router.push(`/admin/ticket-conversations/${id}`);
}

/** 列表式整行可点：与卡片式点卡片进详情保持一致的操作习惯 */
function handleRowClick({ row }: { row: TableRowData }) {
  if (row?.id) {
    openDetail(row.id as number | string);
  }
}

function goUserDetail(userId: unknown) {
  if (!userId) return;
  router.push(`/admin/users/${userId}`);
}

function userName(item: TicketRecord) {
  const user = item.user || {};
  return String(user.nickname || user.display_name || user.email || `用户 #${item.user_id || '-'}`);
}

function assigneeName(item: TicketRecord) {
  const assignee = item.assignee || {};
  return String(assignee.nickname || assignee.username || '未指派');
}

function departmentLabel(value: unknown) {
  return departmentOptions.find((item) => item.value === value)?.label || String(value || '--');
}

function priorityLabel(value: unknown) {
  return priorityOptions.find((item) => item.value === Number(value))?.label || '--';
}

function priorityTheme(value: unknown): 'default' | 'success' | 'warning' | 'danger' {
  const number = Number(value);
  if (number === 2) return 'success';
  if (number === 3) return 'warning';
  if (number === 4) return 'danger';
  return 'default';
}

function statusLabel(value: unknown) {
  return (
    (
      {
        0: '开启',
        1: '客户回复',
        2: '员工回复',
        3: '已关闭',
      } as Record<number, string>
    )[Number(value)] || '--'
  );
}

function statusTheme(value: unknown): 'default' | 'success' | 'warning' | 'danger' {
  const number = Number(value);
  if (number === 0) return 'warning';
  if (number === 1) return 'danger';
  if (number === 2) return 'success';
  return 'default';
}

onMounted(loadList);
</script>
