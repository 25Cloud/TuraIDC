<!--
  快照 / 备份面板。

  三态与安全组 / NAT 面板保持一致（PanelState 负责渲染）：
  - supported === false：整块换成后端 message 的不支持说明，不出现任何操作按钮；
  - error 非空：红色错误块 + 重试；
  - 都正常但两段列表都为空：空态，文案用后端 message（形如「当前暂无快照」）。

  写操作（新建 / 回滚 / 删除）都要二次确认或表单校验，全部由 useConsoleTabs 统一处理。
-->
<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.snapshot.title')" :bordered="false">
      <template #actions>
        <t-space>
          <t-button :loading="snapshotState.loading" @click="loadSnapshots">{{
            $t('serviceConsole.refresh')
          }}</t-button>
          <t-button
            v-if="snapshotState.can_create && snapshotState.supported !== false"
            theme="primary"
            :disabled="snapshotState.submitting"
            @click="openSnapshotDialog"
          >
            {{ $t('serviceConsole.snapshot.create') }}
          </t-button>
        </t-space>
      </template>

      <panel-state
        :loading="snapshotState.loading"
        :loaded="snapshotState.loaded"
        :error="snapshotState.error"
        :unsupported="snapshotState.supported === false"
        :unsupported-text="snapshotState.message || $t('serviceConsole.snapshot.unsupported')"
        :empty="!hasAnyRecord"
        :empty-text="snapshotState.message || $t('serviceConsole.snapshot.empty')"
        @retry="loadSnapshots"
      >
        <div class="snapshot-section">
          <div class="snapshot-section__header">
            <h4>{{ $t('serviceConsole.snapshot.snapTitle') }}</h4>
            <span class="snapshot-section__count">{{ snapshotState.snapshots.length }}</span>
          </div>
          <t-table
            v-if="snapshotState.snapshots.length"
            row-key="id"
            size="small"
            :data="snapshotState.snapshots"
            :columns="snapshotColumns"
            :pagination="null"
          >
            <template #size_gb="{ row }">{{ formatSize(row.size_gb) }}</template>
            <template #operation="{ row }">
              <t-space size="small">
                <t-button
                  v-if="row.can_restore !== false"
                  size="small"
                  variant="text"
                  theme="primary"
                  :disabled="snapshotState.submitting"
                  @click="restoreSnapshot(row)"
                >
                  {{ $t('serviceConsole.snapshot.restore') }}
                </t-button>
                <t-button
                  v-if="row.can_delete !== false"
                  size="small"
                  variant="text"
                  theme="danger"
                  :disabled="snapshotState.submitting"
                  @click="deleteSnapshot(row)"
                >
                  {{ $t('serviceConsole.snapshot.delete') }}
                </t-button>
              </t-space>
            </template>
          </t-table>
          <p v-else class="snapshot-section__empty">{{ $t('serviceConsole.snapshot.snapEmpty') }}</p>
        </div>

        <div class="snapshot-section">
          <div class="snapshot-section__header">
            <h4>{{ $t('serviceConsole.snapshot.backupTitle') }}</h4>
            <span class="snapshot-section__count">{{ snapshotState.backups.length }}</span>
          </div>
          <t-table
            v-if="snapshotState.backups.length"
            row-key="id"
            size="small"
            :data="snapshotState.backups"
            :columns="snapshotColumns"
            :pagination="null"
          >
            <template #size_gb="{ row }">{{ formatSize(row.size_gb) }}</template>
            <template #operation="{ row }">
              <t-space size="small">
                <t-button
                  v-if="row.can_restore !== false"
                  size="small"
                  variant="text"
                  theme="primary"
                  :disabled="snapshotState.submitting"
                  @click="restoreSnapshot(row)"
                >
                  {{ $t('serviceConsole.snapshot.restore') }}
                </t-button>
                <t-button
                  v-if="row.can_delete !== false"
                  size="small"
                  variant="text"
                  theme="danger"
                  :disabled="snapshotState.submitting"
                  @click="deleteSnapshot(row)"
                >
                  {{ $t('serviceConsole.snapshot.delete') }}
                </t-button>
              </t-space>
            </template>
          </t-table>
          <p v-else class="snapshot-section__empty">{{ $t('serviceConsole.snapshot.backupEmpty') }}</p>
        </div>
      </panel-state>
    </t-card>

    <t-dialog
      v-model:visible="snapshotVisible"
      :header="$t('serviceConsole.snapshot.createTitle')"
      :confirm-btn="$t('serviceConsole.snapshot.submit')"
      :cancel-btn="$t('common.cancel')"
      :confirm-loading="snapshotState.submitting"
      width="min(28rem, calc(100vw - 2rem))"
      destroy-on-close
      @confirm="submitSnapshot"
    >
      <t-form label-align="top">
        <t-form-item :label="$t('serviceConsole.snapshot.nameLabel')">
          <t-input v-model="snapshotForm.name" :placeholder="$t('serviceConsole.snapshot.namePlaceholder')" />
        </t-form-item>
        <t-form-item :label="$t('serviceConsole.snapshot.typeLabel')">
          <t-select v-model="snapshotForm.type" :options="snapshotTypeOptions" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import { computed } from 'vue';

import { t } from '@/locales';

import { snapshotColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';
import PanelState from '../PanelState.vue';

const {
  snapshotState,
  snapshotVisible,
  snapshotForm,
  loadSnapshots,
  openSnapshotDialog,
  submitSnapshot,
  deleteSnapshot,
  restoreSnapshot,
} = useServiceConsoleContext();

const hasAnyRecord = computed(() => snapshotState.snapshots.length > 0 || snapshotState.backups.length > 0);

const snapshotTypeOptions = computed(() => [
  { label: t('serviceConsole.snapshot.typeSnap'), value: 'snap' },
  { label: t('serviceConsole.snapshot.typeBackup'), value: 'backup' },
]);

/** 上游给的是 GB 数字；没有值就留空，不编造容量 */
function formatSize(value: unknown): string {
  const size = Number(value);
  if (!Number.isFinite(size) || size <= 0) return '--';

  return `${size} GB`;
}
</script>
