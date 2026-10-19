<template>
  <div class="admin-redis-page">
    <t-card class="redis-card" :bordered="false">
      <div class="redis-toolbar">
        <t-button theme="default" variant="outline" :loading="loading" @click="loadStatus">
          {{ $t('redis.index.refresh') }}
        </t-button>
        <t-button
          v-if="canManage"
          theme="warning"
          variant="outline"
          :loading="flushing"
          :disabled="!status.available"
          @click="handleFlushCache"
        >
          {{ $t('redis.index.flushCache') }}
        </t-button>
      </div>

      <t-alert v-if="!status.available" class="redis-alert" theme="error" :message="unavailableMessage" />

      <t-alert v-else class="redis-alert" theme="info" :message="$t('redis.index.readonlyNotice')" />

      <div class="redis-summary">
        <span>{{ $t('redis.index.client') }}{{ status.client || '-' }}</span>
        <span>{{ $t('redis.index.cacheStore') }}{{ status.cache_store || '-' }}</span>
        <span>{{ $t('redis.index.keyPrefix') }}{{ status.prefix || $t('redis.index.none') }}</span>
        <span>{{ $t('redis.index.version') }}{{ serverValue('redis_version') || '-' }}</span>
        <span>{{ $t('redis.index.uptime') }}{{ formatUptime(status.stats.uptime_in_days) }}</span>
        <span>{{ $t('redis.index.connectedClients') }}{{ status.stats.connected_clients ?? 0 }}</span>
        <span>{{ $t('redis.index.memoryUsed') }}{{ status.memory.used_memory_human || '-' }}</span>
        <span>{{ $t('redis.index.memoryPeak') }}{{ status.memory.used_memory_peak_human || '-' }}</span>
        <span>{{ $t('redis.index.fragmentationRatio') }}{{ formatRatio(status.memory.mem_fragmentation_ratio) }}</span>
        <span>{{ $t('redis.index.hitRate') }}{{ formatRatio(status.stats.hit_rate) }}</span>
      </div>

      <div class="redis-section-title">{{ $t('redis.index.connectionStatus') }}</div>
      <t-table
        row-key="name"
        :data="status.connections"
        :columns="connectionColumns"
        :loading="loading"
        hover
        table-layout="fixed"
      >
        <template #connected="{ row }">
          <t-tag :theme="row.connected ? 'success' : 'danger'" variant="light">
            {{ row.connected ? $t('redis.index.normal') : $t('redis.index.abnormal') }}
          </t-tag>
        </template>
        <template #message="{ row }">{{ row.message || '-' }}</template>
      </t-table>

      <template v-if="status.keyspace.length">
        <div class="redis-section-title">{{ $t('redis.index.keyspace') }}</div>
        <t-table
          row-key="database"
          :data="status.keyspace"
          :columns="keyspaceColumns"
          :loading="loading"
          hover
          table-layout="fixed"
        >
          <template #database="{ row }">db{{ row.database }}</template>
          <template #keys="{ row }">{{ formatNumber(row.keys) }}</template>
          <template #expires="{ row }">{{ formatNumber(row.expires) }}</template>
          <template #avgTtl="{ row }">{{ formatTtl(row.avg_ttl) }}</template>
        </t-table>
      </template>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import type { PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';

import type { RedisStatus } from '@/api/admin/redis';
import { redisApi } from '@/api/admin/redis';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { errorMessage } from '@/utils/userMessage';

const userStore = useUserStore();

const loading = ref(false);
const flushing = ref(false);
const status = ref<RedisStatus>({
  available: false,
  client: '',
  prefix: '',
  cache_store: '',
  connections: [],
  server: {},
  keyspace: [],
  memory: {},
  stats: {},
});

const canManage = computed(() => {
  const permissions = userStore.userInfo?.permissions || [];
  return hasPermissionInList(permissions, AdminPermissions.DATABASE_MANAGE);
});

const unavailableMessage = computed(() => {
  const hasExtension = status.value.client !== '';
  return hasExtension ? t('redis.index.disconnectedHint') : t('redis.index.extensionMissingHint');
});

const connectionColumns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { title: t('redis.index.connection'), colKey: 'name', width: 160 },
  { title: t('redis.index.databaseIndex'), colKey: 'database', width: 100 },
  { title: t('common.status'), colKey: 'connected', width: 100 },
  { title: t('redis.index.note'), colKey: 'message', minWidth: 220 },
]);

const keyspaceColumns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { title: t('redis.index.databaseColumn'), colKey: 'database', width: 100 },
  { title: t('redis.index.keyCount'), colKey: 'keys', width: 140 },
  { title: t('redis.index.expiringKeys'), colKey: 'expires', width: 140 },
  { title: t('redis.index.avgTtl'), colKey: 'avgTtl', width: 140 },
]);

async function loadStatus() {
  loading.value = true;
  try {
    const payload = await redisApi.status();
    status.value = normalizeStatus(payload);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('redis.index.loadFailed')));
  } finally {
    loading.value = false;
  }
}

function handleFlushCache() {
  const dialog = DialogPlugin.confirm({
    header: t('redis.index.clearTitle'),
    body: t('redis.index.clearConfirm'),
    confirmBtn: { content: t('redis.index.confirmClear'), theme: 'warning' },
    cancelBtn: t('common.cancel'),
    onConfirm: async () => {
      flushing.value = true;
      try {
        const result = await redisApi.flushCache();
        MessagePlugin.success(String(result.message || t('redis.index.cleared')));
        await loadStatus();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('redis.index.clearFailed')));
      } finally {
        flushing.value = false;
      }
    },
  });
}

function normalizeStatus(raw: unknown): RedisStatus {
  const source = (raw ?? {}) as Partial<RedisStatus>;
  return {
    available: Boolean(source.available),
    client: String(source.client || ''),
    prefix: String(source.prefix || ''),
    cache_store: String(source.cache_store || ''),
    connections: Array.isArray(source.connections) ? source.connections : [],
    server: source.server && typeof source.server === 'object' ? source.server : {},
    keyspace: Array.isArray(source.keyspace) ? source.keyspace : [],
    memory: source.memory && typeof source.memory === 'object' ? source.memory : {},
    stats: source.stats && typeof source.stats === 'object' ? source.stats : {},
  };
}

function serverValue(key: string) {
  return String(status.value.server?.[key] ?? '');
}

function formatNumber(value: number) {
  return Number(value || 0).toLocaleString('zh-CN');
}

function formatRatio(value: unknown) {
  const ratio = Number(value || 0);
  return `${(ratio * 100).toFixed(1)}%`;
}

function formatUptime(days: unknown) {
  const total = Number(days || 0);
  if (total <= 0) return '-';
  return t('redis.index.days', { count: total });
}

function formatTtl(ms: number) {
  const value = Number(ms || 0);
  if (value <= 0) return '-';
  if (value >= 1000) return t('redis.index.seconds', { count: Math.round(value / 1000) });
  return t('redis.index.milliseconds', { count: value });
}

onMounted(loadStatus);
</script>
<style scoped lang="less">
.admin-redis-page {
  .redis-card {
    min-height: 100%;
  }

  .redis-toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 12px;
  }

  .redis-alert {
    margin-bottom: 16px;
  }

  .redis-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 16px;
    color: var(--td-text-color-secondary);
    font-size: 13px;
  }

  .redis-section-title {
    margin: 20px 0 12px;
    color: var(--td-text-color-primary);
    font-size: 14px;
    font-weight: 600;
  }
}
</style>
