<template>
  <div class="tickets-page">
    <t-card :bordered="false">
      <!-- 按「谁在等谁」分栏：客服关心的是哪张等自己回复，状态码本身不构成决策 -->
      <t-tabs :value="activeBucket" class="tickets-buckets" @change="handleBucketChange">
        <t-tab-panel v-for="bucket in BUCKETS" :key="bucket" :value="bucket" :label="bucketLabel(bucket)" />
      </t-tabs>

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
        <span class="tickets-refresh-hint">
          <span class="tickets-refresh-dot" :class="{ 'is-active': syncing }" />
          {{ $t('tickets.index.autoRefreshHint') }}
        </span>
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
        <t-table row-key="id" :data="list" :columns="columns" hover table-layout="fixed" @row-click="handleRowClick">
          <template #id="{ row }">
            <button type="button" class="ticket-id-link" @click.stop="openDetail(row.id)">#{{ row.id }}</button>
          </template>
          <template #subject="{ row }">
            <button type="button" class="ticket-subject-link" @click.stop="openDetail(row.id)">
              {{ row.subject || '-' }}
            </button>
          </template>
          <template #user="{ row }">
            <button type="button" class="ticket-user-link" @click.stop="goUserDetail(row.user?.id || row.user_id)">
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
      <t-empty v-if="!list.length && !loading" :description="emptyDescription" />
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
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { TicketRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import AdminViewModeSwitch from '@/components/admin-view-mode-switch/index.vue';
import { useAdminViewMode } from '@/composables/useAdminViewMode';
import { t } from '@/locales';
import { formatDateTime } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminTickets' });

/** 三个分桶：与后端 status=pending / handled / closed 一一对应 */
const BUCKETS = ['pending', 'handled', 'closed'] as const;
type TicketBucket = (typeof BUCKETS)[number];

const BUCKET_HINT_KEY: Record<TicketBucket, string> = {
  pending: 'tickets.index.bucketEmptyPending',
  handled: 'tickets.index.bucketEmptyHandled',
  closed: 'tickets.index.bucketEmptyClosed',
};

const BUCKET_LABEL_KEY: Record<TicketBucket, string> = {
  pending: 'tickets.index.bucketPending',
  handled: 'tickets.index.bucketHandled',
  closed: 'tickets.index.bucketClosed',
};

/** 静默轮询间隔：客服看列表时不必手刷，又不至于把接口打满 */
const POLL_INTERVAL_MS = 30_000;

const router = useRouter();
const route = useRoute();
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
/** 静默刷新中：只给刷新提示点打光，不整块 loading，避免翻页/阅读时列表闪白 */
const syncing = ref(false);
const list = ref<TicketRecord[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const filters = reactive({
  keyword: '',
  priority: '' as string | number,
  department: '',
});

/** 当前分桶：从 URL 读，保证从详情页返回时仍停在原来的栏 */
const activeBucket = ref<TicketBucket>(normalizeBucket(route.query.bucket));

/** 各栏角标：待处理要让人一眼看到积压量 */
const counts = reactive<Record<TicketBucket, number>>({ pending: 0, handled: 0, closed: 0 });

const emptyDescription = computed(() => t(BUCKET_HINT_KEY[activeBucket.value]));

const priorityOptions = [
  { label: t('tickets.index.priorityLow'), value: 1 },
  { label: t('tickets.index.priorityMedium'), value: 2 },
  { label: t('tickets.index.priorityHigh'), value: 3 },
  { label: t('tickets.index.priorityUrgent'), value: 4 },
];
const departmentOptions = [
  { label: t('tickets.index.departmentSales'), value: 'sales' },
  { label: t('tickets.index.departmentSupport'), value: 'support' },
  { label: t('tickets.index.departmentBilling'), value: 'billing' },
  { label: t('tickets.index.departmentAbuse'), value: 'abuse' },
];

// 请求竞态防护：分栏与筛选可快速切换，慢的旧响应不得覆盖新列表
let loadRequestSeq = 0;

function bucketLabel(bucket: TicketBucket) {
  const label = t(BUCKET_LABEL_KEY[bucket]);
  const count = counts[bucket];

  return count > 0 ? `${label} (${count})` : label;
}

async function loadSummary() {
  try {
    const summary = await adminApi.tickets.summary();
    counts.pending = Number(summary.pending ?? 0);
    counts.handled = Number(summary.handled ?? 0);
    counts.closed = Number(summary.closed ?? 0);
  } catch {
    // 角标拉取失败不影响列表可用性，静默跳过，下一轮轮询会再试
  }
}

async function loadList(options: { silent?: boolean } = {}) {
  const silent = options.silent === true;
  const currentSeq = ++loadRequestSeq;

  if (silent) {
    syncing.value = true;
  } else {
    loading.value = true;
  }

  try {
    const response = await adminApi.tickets.list({
      ...filters,
      status: activeBucket.value,
      page: page.value,
      page_size: pageSize.value,
    });
    if (currentSeq !== loadRequestSeq) return;

    const nextList = Array.isArray(response.list) ? response.list : [];
    const nextTotal = Number(response.total || 0);

    // 静默刷新且顺序没变时不替换数组：避免表格重渲染把滚动位置顶回去
    const orderUnchanged =
      nextList.length === list.value.length &&
      nextList.every((item, index) => String(item?.id) === String(list.value[index]?.id));

    if (!silent || !orderUnchanged || nextTotal !== total.value) {
      list.value = nextList;
    }

    total.value = nextTotal;
    page.value = Number(response.page || page.value);
    pageSize.value = Number(response.page_size || pageSize.value);

    // 静默刷新时数据可能变少，当前页会越界成空页：退回最后一页，别让人以为工单被清空了
    if (silent && !list.value.length && total.value > 0) {
      const lastPage = Math.max(1, Math.ceil(total.value / pageSize.value));

      if (lastPage < page.value) {
        page.value = lastPage;
        void loadList({ silent: true });
      }
    }
  } catch (error) {
    if (currentSeq !== loadRequestSeq) return;

    // 静默刷新失败保持旧数据：每 30 秒弹一次报错比不刷新更烦
    if (silent) return;

    list.value = [];
    total.value = 0;
    MessagePlugin.error(errorMessage(error, t('tickets.index.loadFailed')));
  } finally {
    if (currentSeq === loadRequestSeq) {
      loading.value = false;
      syncing.value = false;
    }
  }
}

function handleSearch() {
  page.value = 1;
  loadList();
}

/** 切栏：重置到第一页，并把栏位写进 URL，从详情页返回时不会跳回默认栏 */
function handleBucketChange(value: unknown) {
  const bucket = normalizeBucket(value);

  if (bucket === activeBucket.value) return;

  activeBucket.value = bucket;
  page.value = 1;
  void router.replace({ query: { ...route.query, bucket } });
  loadSummary();
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
  return String(
    user.nickname || user.display_name || user.email || t('tickets.index.userFallback', { id: item.user_id || '-' }),
  );
}

function assigneeName(item: TicketRecord) {
  const assignee = item.assignee || {};
  return String(assignee.nickname || assignee.username || t('tickets.index.unassigned'));
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
  const labels: Record<number, string> = {
    0: t('tickets.index.statusOpen'),
    1: t('tickets.index.statusCustomerReply'),
    2: t('tickets.index.statusStaffReply'),
    3: t('tickets.index.statusClosed'),
  };
  return labels[Number(value)] || '--';
}

function statusTheme(value: unknown): 'default' | 'success' | 'warning' | 'danger' {
  const number = Number(value);
  if (number === 0) return 'warning';
  if (number === 1) return 'danger';
  if (number === 2) return 'success';
  return 'default';
}

/** 归一化分桶：非法/旧链接（例如 status=ongoing）一律回到待处理 */
function normalizeBucket(value: unknown): TicketBucket {
  const raw = String(value ?? '');

  return (BUCKETS as readonly string[]).includes(raw) ? (raw as TicketBucket) : 'pending';
}

let pollTimer: ReturnType<typeof setInterval> | null = null;

/** 静默对齐一次：角标与列表同时刷新，都不打断当前操作 */
function syncQuietly() {
  loadSummary();
  loadList({ silent: true });
}

function startPolling() {
  stopPolling();
  pollTimer = setInterval(() => {
    // 后台标签页不刷新，省掉无意义的请求
    if (document.visibilityState !== 'visible') return;
    syncQuietly();
  }, POLL_INTERVAL_MS);
}

function stopPolling() {
  if (pollTimer !== null) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

/** 切回本页立刻对齐，避免先看到一份过期列表 */
function handleVisibilityChange() {
  if (document.visibilityState === 'visible') syncQuietly();
}

onMounted(() => {
  loadSummary();
  loadList();
  startPolling();
  document.addEventListener('visibilitychange', handleVisibilityChange);
});

onBeforeUnmount(() => {
  stopPolling();
  document.removeEventListener('visibilitychange', handleVisibilityChange);
});
</script>
