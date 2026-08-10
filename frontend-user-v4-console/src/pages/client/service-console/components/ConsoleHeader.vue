<template>
  <t-card class="console-header-card" :bordered="false">
    <div class="console-header-main">
      <div class="console-title-line">
        <h1>{{ detail.name || `服务 #${serviceId}` }}</h1>
        <t-button theme="primary" variant="text" @click="openNameDialog">修改名称</t-button>
        <t-tag :theme="resolveTdesignStatusTheme(detail)" variant="light">{{
          resolveServiceStatusLabel(detail.status)
        }}</t-tag>
        <t-tag v-if="detail.product?.type_label" variant="light">{{ detail.product.type_label }}</t-tag>
      </div>

      <div class="console-meta-grid">
        <span class="meta-item meta-id">实例 ID：{{ detail.id || '--' }}</span>
        <span class="meta-item meta-region">地址：{{ serviceRegion }}</span>
        <span class="meta-item meta-ip">{{ primaryConnectionLabel }}：{{ primaryConnectionValues[0] || '--' }}</span>
        <div class="console-auto-renew-line">
          <span>自动续费</span>
          <t-switch
            :value="Number(detail.auto_renew) === 1"
            :loading="autoRenewLoading"
            @change="handleToggleAutoRenew"
          />
        </div>
        <div class="console-remark-line">
          <span>备注：</span>
          <strong :class="{ 'is-empty': !detail.remark }">{{ detail.remark || '点击添加备注' }}</strong>
          <t-button
            shape="square"
            variant="text"
            size="small"
            :aria-label="detail.remark ? '编辑备注' : '添加备注'"
            @click="openRemarkDialog"
          >
            <template #icon><edit-icon /></template>
          </t-button>
        </div>
      </div>
    </div>

    <div class="console-header-actions">
      <t-button
        v-if="isInstanceRunning"
        variant="outline"
        :disabled="!detail.actions?.power || actionLoading"
        @click="handlePowerAction('off')"
      >
        <template #icon><pause-circle-filled-icon /></template>
        关机
      </t-button>
      <t-button
        v-else
        theme="primary"
        :disabled="!detail.actions?.power || actionLoading"
        @click="handlePowerAction('on')"
      >
        <template #icon><play-circle-filled-icon /></template>
        开机
      </t-button>
      <t-button
        variant="outline"
        :disabled="!detail.actions?.power || actionLoading"
        @click="handlePowerAction('reboot')"
      >
        <template #icon><rotate-icon /></template>
        重启
      </t-button>
      <t-button
        variant="outline"
        :loading="statusSyncing"
        :disabled="!canSyncStatus || actionLoading"
        @click="handleSyncStatus"
      >
        <template #icon><refresh-icon /></template>
        状态同步
      </t-button>
      <t-dropdown trigger="click" :options="moreOptions" @click="handleMoreClick">
        <t-button variant="outline">
          <template #icon><ellipsis-icon /></template>
          更多
        </t-button>
      </t-dropdown>
    </div>
  </t-card>

  <t-dialog
    v-model:visible="ipDialogVisible"
    :header="primaryConnectionLabel"
    width="min(24rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="ip-dialog-list">
      <div v-for="ip in primaryConnectionValues" :key="ip" class="ip-dialog-item">
        <strong>{{ ip }}</strong>
        <button type="button" class="copy-link" :aria-label="`复制${ip}`" @click="copyText(ip)">
          <copy-icon size="1rem" />
        </button>
      </div>
    </div>
    <template #footer>
      <t-button v-if="primaryConnectionValues.length > 1" variant="outline" @click="copyText(primaryConnectionText)"
        >复制全部</t-button
      >
      <t-button theme="primary" @click="ipDialogVisible = false">关闭</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import {
  CopyIcon,
  EditIcon,
  EllipsisIcon,
  PauseCircleFilledIcon,
  PlayCircleFilledIcon,
  RefreshIcon,
  RotateIcon,
} from 'tdesign-icons-vue-next';
import { computed, ref } from 'vue';

import { useServiceConsoleContext } from './context';

const {
  detail,
  serviceId,
  statusSyncing,
  actionLoading,
  autoRenewLoading,
  canSyncStatus,
  serviceRegion,
  primaryConnectionLabel,
  primaryConnectionValues,
  primaryConnectionText,
  instanceStatusTheme,
  resolveServiceStatusLabel,
  resolveTdesignStatusTheme,
  openNameDialog,
  openRemarkDialog,
  handleSyncStatus,
  handlePowerAction,
  handleToggleAutoRenew,
  openPasswordDialog,
  openReinstallDialog,
  copyText,
} = useServiceConsoleContext();

const ipDialogVisible = ref(false);

const moreOptions = computed(() => [
  { content: '重置密码', value: 'password', disabled: !detail.value.actions?.password_reset },
  { content: '重装系统', value: 'reinstall', disabled: !detail.value.actions?.reinstall },
  { content: '强制关机', value: 'hard_off', disabled: !detail.value.actions?.power },
  { content: '强制重启', value: 'hard_reboot', disabled: !detail.value.actions?.power },
]);

const isInstanceRunning = computed(() => instanceStatusTheme.value === 'success');

function handleMoreCommand(command: string) {
  if (command === 'hard_off' || command === 'hard_reboot') {
    handlePowerAction(command);
    return;
  }

  if (command === 'password') {
    openPasswordDialog();
    return;
  }

  if (command === 'reinstall') {
    void openReinstallDialog();
  }
}

function handleMoreClick(payload: { value: string | number }) {
  handleMoreCommand(String(payload.value));
}
</script>
