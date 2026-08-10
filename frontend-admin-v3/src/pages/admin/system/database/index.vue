<template>
  <div class="admin-database-page">
    <t-card class="database-card" :bordered="false">
      <div class="database-toolbar">
        <t-button
          v-if="canManage"
          theme="warning"
          variant="outline"
          :loading="optimizing"
          :disabled="optimizationCoolingDown"
          @click="handleOptimizeAll"
        >
          {{
            optimizationCoolingDown
              ? `冷却中 ${formatDuration(status.optimization.cooldown_remaining_seconds)}`
              : '智能优化表'
          }}
        </t-button>
        <t-button v-if="canManage" theme="primary" :loading="exporting" @click="handleExportBackup">
          导出备份
        </t-button>
      </div>

      <div class="database-summary">
        <span>数据库：{{ status.database || '-' }}</span>
        <span>表数量：{{ status.total_count }}</span>
        <span>总行数：{{ formatNumber(status.total_rows) }}</span>
        <span>总大小：{{ formatSizeMb(status.total_size_mb) }}</span>
        <span
          >建议优化：{{ status.optimization.candidate_count }} 张，预计回收
          {{ formatSizeMb(status.optimization.estimated_reclaimable_mb) }}</span
        >
        <span v-if="optimizationCoolingDown">上次优化：{{ status.optimization.last_optimized_at || '-' }}</span>
      </div>

      <div class="database-optimization-candidates">
        <span class="candidates-label">候选表：</span>
        <template v-if="status.optimization.candidates.length">
          <t-tag
            v-for="candidate in status.optimization.candidates"
            :key="candidate.name"
            theme="warning"
            variant="light"
          >
            {{ candidate.name }} · {{ formatSizeMb(candidate.reclaimable_mb) }} ·
            {{ formatPercent(candidate.fragmentation_ratio) }}
          </t-tag>
        </template>
        <span v-else class="candidates-empty">暂无达到优化阈值的数据表</span>
      </div>

      <t-table row-key="name" :data="filteredList" :columns="columns" :loading="loading" hover table-layout="fixed">
        <template #name="{ row }">
          <strong>{{ row.name }}</strong>
        </template>
        <template #rows="{ row }">{{ formatNumber(row.rows) }}</template>
        <template #size="{ row }">{{ formatSizeMb(row.size_mb) }}</template>
        <template #updateTime="{ row }">{{ row.update_time || '-' }}</template>
      </t-table>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import type { PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';

import type { DatabaseOptimizationCandidate, DatabaseStatus, DatabaseTableItem } from '@/api/admin/database';
import { databaseApi } from '@/api/admin/database';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { useUserStore } from '@/store';
import { errorMessage } from '@/utils/userMessage';

const userStore = useUserStore();

const loading = ref(false);
const optimizing = ref(false);
const exporting = ref(false);
const status = ref<DatabaseStatus>({
  database: '',
  list: [],
  total_count: 0,
  total_rows: 0,
  total_size_mb: 0,
  optimization: {
    candidate_count: 0,
    estimated_reclaimable_mb: 0,
    candidates: [],
    cooldown_remaining_seconds: 0,
    last_optimized_at: null,
  },
});

const canManage = computed(() => hasPermission(AdminPermissions.DATABASE_MANAGE));

const filteredList = computed(() => status.value.list);
const optimizationCoolingDown = computed(() => status.value.optimization.cooldown_remaining_seconds > 0);

const columns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { title: '表名', colKey: 'name', minWidth: 220 },
  { title: '行数', colKey: 'rows', width: 140 },
  { title: '大小', colKey: 'size', width: 140 },
  { title: '更新时间', colKey: 'updateTime', minWidth: 180 },
]);

onMounted(() => {
  void loadStatus();
});

async function loadStatus() {
  loading.value = true;
  try {
    const payload = await databaseApi.status();
    status.value = {
      database: String(payload.database || ''),
      list: Array.isArray(payload.list) ? payload.list.map(normalizeTable) : [],
      total_count: Number(payload.total_count || 0),
      total_rows: Number(payload.total_rows || 0),
      total_size_mb: Number(payload.total_size_mb || 0),
      optimization: normalizeOptimization(payload.optimization),
    };
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载数据库状态失败'));
  } finally {
    loading.value = false;
  }
}

function handleOptimizeAll() {
  handleOptimizeTables([]);
}

function handleOptimizeTables(tables: string[]) {
  if (!canManage.value) {
    return;
  }

  if (tables.length === 0 && optimizationCoolingDown.value) {
    MessagePlugin.warning(
      `数据库优化冷却中，请在 ${formatDuration(status.value.optimization.cooldown_remaining_seconds)} 后重试`,
    );
    return;
  }

  const isAll = tables.length === 0;
  const label = isAll ? `全部 ${status.value.total_count} 张表的碎片` : `${tables.length} 张选中表`;
  const dialog = DialogPlugin.confirm({
    header: '优化数据表',
    body: isAll
      ? `确认检查${label}？仅对可回收空间达到阈值的表执行 OPTIMIZE TABLE；该操作可能短暂锁表，请避开高峰期。`
      : `确认对${label}执行 OPTIMIZE TABLE？该操作可能短暂锁表，请避开高峰期。`,
    confirmBtn: { content: '确认优化', theme: 'warning' },
    cancelBtn: '取消',
    onConfirm: async () => {
      optimizing.value = true;
      try {
        const result = await databaseApi.optimize(isAll ? {} : { tables });
        MessagePlugin.success(String(result.message || '数据表优化完成'));
        await loadStatus();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '优化数据表失败'));
      } finally {
        optimizing.value = false;
      }
    },
  });
}

function handleExportBackup() {
  if (!canManage.value) {
    return;
  }

  const dialog = DialogPlugin.confirm({
    header: '导出数据库备份',
    body: '确认导出当前数据库完整 SQL 备份？文件可能较大，导出期间请勿重复点击。',
    confirmBtn: { content: '确认导出', theme: 'primary' },
    cancelBtn: '取消',
    onConfirm: async () => {
      exporting.value = true;
      try {
        await databaseApi.exportBackup();
        MessagePlugin.success('备份导出已开始下载');
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '导出数据库备份失败'));
      } finally {
        exporting.value = false;
      }
    },
  });
}

function normalizeTable(item: DatabaseTableItem): DatabaseTableItem {
  return {
    name: String(item.name || ''),
    rows: Number(item.rows || 0),
    size_mb: Number(item.size_mb || 0),
    update_time: item.update_time ? String(item.update_time) : null,
  };
}

function normalizeOptimization(value?: DatabaseStatus['optimization']): DatabaseStatus['optimization'] {
  return {
    candidate_count: Number(value?.candidate_count || 0),
    estimated_reclaimable_mb: Number(value?.estimated_reclaimable_mb || 0),
    candidates: Array.isArray(value?.candidates) ? value.candidates.map(normalizeCandidate) : [],
    cooldown_remaining_seconds: Number(value?.cooldown_remaining_seconds || 0),
    last_optimized_at: value?.last_optimized_at ? String(value.last_optimized_at) : null,
  };
}

function normalizeCandidate(item: DatabaseOptimizationCandidate): DatabaseOptimizationCandidate {
  return {
    name: String(item.name || ''),
    reclaimable_mb: Number(item.reclaimable_mb || 0),
    fragmentation_ratio: Number(item.fragmentation_ratio || 0),
  };
}

function formatNumber(value: number) {
  return Number(value || 0).toLocaleString('zh-CN');
}

function formatSizeMb(value: number) {
  const size = Number(value || 0);
  if (size >= 1024) {
    return `${(size / 1024).toFixed(2)} GB`;
  }
  return `${size.toFixed(2)} MB`;
}

function formatPercent(value: number) {
  return `${(Math.max(0, Number(value || 0)) * 100).toFixed(1)}%`;
}

function formatDuration(value: number) {
  const seconds = Math.max(0, Math.ceil(Number(value || 0)));
  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;

  if (minutes <= 0) {
    return `${remainingSeconds} 秒`;
  }
  return remainingSeconds > 0 ? `${minutes} 分 ${remainingSeconds} 秒` : `${minutes} 分钟`;
}

function hasPermission(permission: string) {
  const permissions = userStore.userInfo?.permissions || [];
  return hasPermissionInList(permissions, permission);
}
</script>
<style scoped lang="less">
.admin-database-page {
  .database-card {
    min-height: 100%;
  }

  .database-toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 12px;
  }

  .database-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 16px;
    color: var(--td-text-color-secondary);
    font-size: 13px;
  }

  .database-optimization-candidates {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin: -4px 0 16px;
    color: var(--td-text-color-secondary);
    font-size: 13px;
  }

  .candidates-label {
    color: var(--td-text-color-primary);
  }

  .candidates-empty {
    color: var(--td-text-color-placeholder);
  }
}
</style>
