<template>
  <t-alert v-if="isExpiringSoon" theme="warning" class="console-alert">
    实例将于 {{ detail.expires_at }} 到期，建议提前续费避免服务中断。
    <template #operation>
      <t-button variant="text" theme="primary" @click="openRenewDialog">立即续费</t-button>
    </template>
  </t-alert>

  <t-alert v-if="!canManageConsole" theme="info" class="console-alert">
    当前实例未接入完整控制能力，页面将以只读模式展示基础信息。
  </t-alert>
</template>
<script setup lang="ts">
import { computed } from 'vue';

import { useServiceConsoleContext } from './context';

const { detail, canManageConsole, openRenewDialog } = useServiceConsoleContext();

const isExpiringSoon = computed(() => {
  if (!detail.value.expires_at) return false;
  const expiresAt = new Date(String(detail.value.expires_at).replace(/-/g, '/')).getTime();
  if (!Number.isFinite(expiresAt)) return false;

  return expiresAt - Date.now() <= 7 * 24 * 60 * 60 * 1000;
});
</script>
