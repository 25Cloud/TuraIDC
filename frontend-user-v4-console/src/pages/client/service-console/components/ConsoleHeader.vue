<template>
  <t-card class="console-header-card" :class="{ 'is-compact': compact }" :bordered="false">
    <div class="console-header-main">
      <div class="console-title-line">
        <h1>{{ detail.name || $t('serviceConsole.instanceHash', { id: serviceId }) }}</h1>
        <t-button theme="primary" variant="text" @click="openNameDialog">{{ $t('serviceConsole.rename') }}</t-button>
        <t-tag :theme="resolveTdesignStatusTheme(detail)" variant="light">{{
          resolveServiceStatusLabel(detail.status)
        }}</t-tag>
        <t-tag v-if="detail.product?.type_label" variant="light">{{ detail.product.type_label }}</t-tag>
      </div>

      <div class="console-meta-grid">
        <span class="meta-item meta-id">{{ $t('serviceConsole.instanceIdMeta', { id: detail.id || '--' }) }}</span>
        <span v-if="!compact" class="meta-item meta-region">{{
          $t('serviceConsole.addressMeta', { address: serviceRegion })
        }}</span>
        <span v-if="!compact" class="meta-item meta-ip"
          >{{ primaryConnectionLabel }}：{{ primaryConnectionValues[0] || '--' }}</span
        >
        <span v-if="compact" class="meta-item meta-region">{{ compactRegionLabel }}：{{ serviceRegion }}</span>
        <div class="console-auto-renew-line">
          <span>{{ $t('serviceConsole.autoRenew') }}</span>
          <t-switch
            :value="Number(detail.auto_renew) === 1"
            :loading="autoRenewLoading"
            @change="handleToggleAutoRenew"
          />
        </div>
        <div class="console-remark-line">
          <span>{{ $t('serviceConsole.remarkLabel') }}</span>
          <strong :class="{ 'is-empty': !detail.remark }">{{
            detail.remark || $t('serviceConsole.addRemarkHint')
          }}</strong>
          <t-button
            shape="square"
            variant="text"
            size="small"
            :aria-label="detail.remark ? $t('serviceConsole.editRemark') : $t('serviceConsole.addRemark')"
            @click="openRemarkDialog"
          >
            <template #icon><edit-icon /></template>
          </t-button>
        </div>
      </div>
    </div>

    <div class="console-header-actions">
      <!-- CDN 没有开关机/重装/救援这些云主机语义，只保留状态同步与账单相关入口 -->
      <template v-if="compact">
        <t-button
          variant="outline"
          :loading="statusSyncing"
          :disabled="!canSyncStatus || actionLoading"
          @click="handleSyncStatus"
        >
          <template #icon><refresh-icon /></template>
          {{ $t('serviceConsole.statusSync') }}
        </t-button>
        <t-button v-if="!isTrialMachine" variant="outline" @click="openRenewDialog">
          <template #icon><money-icon /></template>
          {{ $t('serviceConsole.renewLabel') }}
        </t-button>
      </template>
      <template v-else>
        <t-button
          v-if="isInstanceRunning"
          variant="outline"
          :disabled="!detail.actions?.power || actionLoading"
          @click="handlePowerAction('off')"
        >
          <template #icon><pause-circle-filled-icon /></template>
          {{ $t('serviceConsole.power.off') }}
        </t-button>
        <t-button
          v-else
          theme="primary"
          :disabled="!detail.actions?.power || actionLoading"
          @click="handlePowerAction('on')"
        >
          <template #icon><play-circle-filled-icon /></template>
          {{ $t('serviceConsole.power.on') }}
        </t-button>
        <t-button
          variant="outline"
          :disabled="!detail.actions?.power || actionLoading"
          @click="handlePowerAction('reboot')"
        >
          <template #icon><rotate-icon /></template>
          {{ $t('serviceConsole.power.reboot') }}
        </t-button>
        <t-button
          variant="outline"
          :loading="statusSyncing"
          :disabled="!canSyncStatus || actionLoading"
          @click="handleSyncStatus"
        >
          <template #icon><refresh-icon /></template>
          {{ $t('serviceConsole.statusSync') }}
        </t-button>
        <t-dropdown trigger="click" :options="moreOptions" @click="handleMoreClick">
          <t-button variant="outline">
            <template #icon><ellipsis-icon /></template>
            {{ $t('serviceConsole.more') }}
          </t-button>
        </t-dropdown>
      </template>
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
        <button type="button" class="copy-link" :aria-label="$t('serviceConsole.copyIp', { ip })" @click="copyText(ip)">
          <copy-icon size="1rem" />
        </button>
      </div>
    </div>
    <template #footer>
      <t-button v-if="primaryConnectionValues.length > 1" variant="outline" @click="copyText(primaryConnectionText)">{{
        t('serviceConsole.copyAll')
      }}</t-button>
      <t-button theme="primary" @click="ipDialogVisible = false">{{ t('serviceConsole.close') }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import {
  CopyIcon,
  EditIcon,
  EllipsisIcon,
  MoneyIcon,
  PauseCircleFilledIcon,
  PlayCircleFilledIcon,
  RefreshIcon,
  RotateIcon,
} from 'tdesign-icons-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, ref } from 'vue';

import { t } from '@/locales';

import { useServiceConsoleContext } from './context';

/**
 * compact：面板型控制台模式（CDN / 虚拟主机共用）。
 * 头部剔除云主机语义（公网/内网 IP、开关机、重启、重装系统、重置密码、救援模式），
 * 只留状态同步与续费。
 *
 * compactRegionLabel：紧凑模式下的区域字段名。CDN 说「加速区域」，
 * 虚拟主机说「机房区域」，由调用方按控制台类别传入。
 */
withDefaults(defineProps<{ compact?: boolean; compactRegionLabel?: string }>(), {
  compactRegionLabel: t('serviceConsole.areaLabel.acceleration'),
});

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
  openRenewDialog,
  openPasswordDialog,
  openReinstallDialog,
  openRescueDialog,
  copyText,
} = useServiceConsoleContext();

const ipDialogVisible = ref(false);

const moreOptions = computed(() => [
  {
    content: t('serviceConsole.menu.resetPassword'),
    value: 'password',
    disabled: !detail.value.actions?.password_reset,
  },
  { content: t('serviceConsole.menu.reinstall'), value: 'reinstall', disabled: !detail.value.actions?.reinstall },
  { content: t('serviceConsole.menu.rescue'), value: 'rescue', disabled: !detail.value.actions?.rescue },
  { content: t('serviceConsole.power.hardOff'), value: 'hard_off', disabled: !detail.value.actions?.power },
  { content: t('serviceConsole.power.hardReboot'), value: 'hard_reboot', disabled: !detail.value.actions?.power },
  { content: t('serviceConsole.menu.upgrade'), value: 'upgrade', disabled: !hasUpgradeEntry.value },
  { content: t('serviceConsole.menu.snapshot'), value: 'snapshot', disabled: true },
  { content: t('serviceConsole.menu.sshKey'), value: 'ssh_key', disabled: true },
  { content: t('serviceConsole.menu.disk'), value: 'disk', disabled: true },
]);

const hasUpgradeEntry = computed(() => Boolean(detail.value.actions?.upgrade ?? false));

/**
 * 试用机（上游下发 ontrial）不支持续费，隐藏续费入口。
 * 与 CDN 总览「付费信息」卡的判断保持一致，避免头部能点却弹不出。
 */
const isTrialMachine = computed(
  () =>
    String(detail.value.billing_cycle || '')
      .trim()
      .toLowerCase() === 'ontrial',
);

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
    return;
  }

  if (command === 'rescue') {
    openRescueDialog();
    return;
  }

  if (command === 'snapshot' || command === 'ssh_key' || command === 'disk') {
    MessagePlugin.info(t('serviceConsole.upstreamFeatureUnsupported'));
  }
}

function handleMoreClick(payload: string | number | { [key: string]: any }) {
  const command = typeof payload === 'object' && payload !== null ? payload.value : payload;
  handleMoreCommand(String(command));
}
</script>
