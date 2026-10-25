<template>
  <t-drawer :visible="visible" :size="drawerSize" :header="$t('logs.queueDrawer.title')" @close="emit('close')">
    <t-alert
      v-if="connectionMismatch"
      theme="warning"
      :message="
        $t('logs.queueDrawer.connectionMismatch', {
          runtime: connection.runtime,
          inspected: connection.inspected,
        })
      "
      class="queue-drawer-alert"
    />

    <div class="queue-drawer-toolbar">
      <t-radio-group v-model="queue" variant="default-filled">
        <t-radio-button value="pending">{{ $t('logs.queueDrawer.pendingTab') }}</t-radio-button>
        <t-radio-button value="failed">{{ $t('logs.queueDrawer.failedTab') }}</t-radio-button>
      </t-radio-group>
      <t-button
        v-if="queue === 'failed'"
        theme="danger"
        variant="outline"
        :disabled="total === 0 || clearing"
        :loading="clearing"
        @click="confirmClear"
      >
        {{ $t('logs.queueDrawer.clearFailed') }}
      </t-button>
    </div>

    <t-alert
      v-if="queue === 'pending'"
      theme="info"
      :message="$t('logs.queueDrawer.pendingReadonlyTip')"
      class="queue-drawer-alert"
    />

    <t-table
      row-key="id"
      :data="rows"
      :columns="columns"
      :loading="loading"
      :pagination="pagination"
      hover
      table-layout="fixed"
      @page-change="onPageChange"
    >
      <template #job="{ row }">
        <div class="queue-job-cell">
          <strong>{{ row.display_name }}</strong>
          <span class="queue-job-cell__meta">
            {{ $t('logs.queueDrawer.queueLabel') }}: {{ row.queue || '-' }}
            <template v-if="row.uuid"> · UUID: {{ row.uuid.slice(0, 8) }}</template>
          </span>
        </div>
      </template>
      <template #state="{ row }">
        <t-tag size="small" :theme="stateTheme(row)" variant="light">{{ stateLabel(row) }}</t-tag>
      </template>
      <template #time="{ row }">
        {{ formatDateTime(row.failed_at || row.reserved_at || row.available_at || row.created_at) }}
      </template>
      <template #op="{ row }">
        <t-popconfirm
          v-if="row.kind === 'failed'"
          :content="$t('logs.queueDrawer.deleteConfirm')"
          @confirm="deleteRow(row)"
        >
          <t-link theme="danger" hover="color">{{ $t('logs.queueDrawer.deleteAction') }}</t-link>
        </t-popconfirm>
        <span v-else class="queue-job-readonly">{{ $t('logs.queueDrawer.readonlyMark') }}</span>
      </template>
    </t-table>

    <template #footer>
      <t-button variant="outline" @click="emit('close')">
        <template #icon><chevron-left-icon /></template>
        {{ $t('logs.queueDrawer.back') }}
      </t-button>
    </template>
  </t-drawer>
</template>
<script setup lang="ts">
import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import type { PageInfo, PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, ref, watch } from 'vue';

import type { QueueJobItem } from '@/api/admin/settings';
import { schedulesApi } from '@/api/admin/settings';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { formatDateTime } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'QueueJobsDrawer' });

const props = defineProps<{
  visible: boolean;
  initialQueue?: 'pending' | 'failed';
}>();

const emit = defineEmits<{
  close: [];
  /** 删除/清空会改变任务计数，通知父级刷新概览卡片 */
  changed: [];
}>();

const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '860px'));

const queue = ref<'pending' | 'failed'>(props.initialQueue ?? 'failed');
const rows = ref<QueueJobItem[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const loading = ref(false);
const clearing = ref(false);
const connection = ref({ runtime: '', inspected: '' });

// 切换页签/翻页是典型的「同一数据槽位重复触发」场景：慢的旧响应晚到会把
// 新页签的数据覆盖掉，必须用请求序号失效旧请求。
let requestSeq = 0;

const connectionMismatch = computed(() => connection.value.runtime !== connection.value.inspected);

const pagination = computed(() => ({
  current: page.value,
  pageSize: pageSize.value,
  total: total.value,
  showJumper: true,
}));

const columns = computed<PrimaryTableCol[]>(() => [
  { colKey: 'job', title: t('logs.queueDrawer.colJob'), minWidth: 260 },
  { colKey: 'state', title: t('logs.queueDrawer.colState'), width: 110 },
  { colKey: 'attempts', title: t('logs.queueDrawer.colAttempts'), width: 90, align: 'center' },
  { colKey: 'time', title: t('logs.queueDrawer.colTime'), width: 170 },
  { colKey: 'op', title: t('logs.queueDrawer.colOp'), width: 90 },
]);

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      queue.value = props.initialQueue ?? 'failed';
      page.value = 1;
      void load();
    }
  },
);

watch(queue, () => {
  page.value = 1;
  void load();
});

async function load() {
  const seq = ++requestSeq;
  loading.value = true;
  try {
    const response = await schedulesApi.queueJobs({
      queue: queue.value,
      page: page.value,
      page_size: pageSize.value,
    });
    if (seq !== requestSeq) return;
    rows.value = Array.isArray(response.list) ? response.list : [];
    total.value = Number(response.total) || 0;
    connection.value = response.connection || { runtime: '', inspected: '' };
  } catch (error) {
    if (seq !== requestSeq) return;
    rows.value = [];
    total.value = 0;
    MessagePlugin.error(errorMessage(error, t('logs.queueDrawer.loadFailed')));
  } finally {
    if (seq === requestSeq) loading.value = false;
  }
}

function onPageChange(info: PageInfo) {
  page.value = info.current || 1;
  pageSize.value = info.pageSize || 20;
  void load();
}

function stateTheme(row: QueueJobItem) {
  if (row.kind === 'failed') return 'danger';
  return row.state === 'reserved' ? 'warning' : 'primary';
}

function stateLabel(row: QueueJobItem) {
  if (row.kind === 'failed') return t('logs.queueDrawer.stateFailed');
  return row.state === 'reserved' ? t('logs.queueDrawer.stateReserved') : t('logs.queueDrawer.statePending');
}

function deleteRow(row: QueueJobItem) {
  schedulesApi
    .deleteQueueJob('failed', row.id)
    .then(() => {
      MessagePlugin.success(t('logs.queueDrawer.deleteSuccess'));
      emit('changed');
      void load();
    })
    .catch((error) => MessagePlugin.error(errorMessage(error, t('logs.queueDrawer.deleteFailed'))));
}

function confirmClear() {
  const dialog = DialogPlugin.confirm({
    header: t('logs.queueDrawer.clearConfirmTitle'),
    body: t('logs.queueDrawer.clearConfirmBody', { count: total.value }),
    theme: 'warning',
    confirmBtn: { content: t('logs.queueDrawer.clearConfirmYes'), theme: 'danger', loading: clearing.value },
    cancelBtn: t('logs.queueDrawer.cancel'),
    onConfirm: async () => {
      clearing.value = true;
      try {
        const result = await schedulesApi.clearQueueJobs('failed');
        MessagePlugin.success(t('logs.queueDrawer.clearSuccess', { count: result.deleted_count }));
        emit('changed');
        await load();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('logs.queueDrawer.clearFailed')));
      } finally {
        clearing.value = false;
        dialog.destroy();
      }
    },
    onClose: () => dialog.destroy(),
  });
}
</script>
<style scoped>
.queue-drawer-alert {
  margin-bottom: 12px;
}

.queue-drawer-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.queue-job-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.queue-job-cell__meta {
  font-size: 12px;
  color: var(--td-text-color-secondary, #86909c);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.queue-job-readonly {
  font-size: 12px;
  color: var(--td-text-color-placeholder, #a9aeb8);
}
</style>
