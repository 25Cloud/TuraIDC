<template>
  <t-alert v-if="isExpiringSoon" theme="warning" class="console-alert">
    {{ $t('serviceConsole.alerts.expiringSoon', { time: detail.expires_at }) }}
    <template #operation>
      <t-button variant="text" theme="primary" @click="openRenewDialog">{{ $t('serviceConsole.alerts.renewNow') }}</t-button>
    </template>
  </t-alert>

  <t-alert v-if="!canManageConsole" theme="info" class="console-alert">
    {{ $t('serviceConsole.alerts.readOnlyNotice') }}
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
