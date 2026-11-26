<template>
  <t-alert v-if="isExpired" theme="error" class="console-alert">
    {{ $t('serviceConsole.alerts.expired', { time: detail.expires_at }) }}
    <template #operation>
      <t-button variant="text" theme="primary" @click="openRenewDialog">{{
        $t('serviceConsole.alerts.renewNow')
      }}</t-button>
    </template>
  </t-alert>

  <t-alert v-else-if="isExpiringSoon" theme="warning" class="console-alert">
    {{ $t('serviceConsole.alerts.expiringSoon', { time: detail.expires_at }) }}
    <template #operation>
      <t-button variant="text" theme="primary" @click="openRenewDialog">{{
        $t('serviceConsole.alerts.renewNow')
      }}</t-button>
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

const expiresInMs = computed(() => {
  if (!detail.value.expires_at) return null;
  const expiresAt = new Date(String(detail.value.expires_at).replace(/-/g, '/')).getTime();
  if (!Number.isFinite(expiresAt)) return null;
  return expiresAt - Date.now();
});

const isExpired = computed(() => expiresInMs.value !== null && expiresInMs.value <= 0);

const isExpiringSoon = computed(() => {
  const remaining = expiresInMs.value;
  if (remaining === null) return false;
  return remaining > 0 && remaining <= 7 * 24 * 60 * 60 * 1000;
});
</script>
