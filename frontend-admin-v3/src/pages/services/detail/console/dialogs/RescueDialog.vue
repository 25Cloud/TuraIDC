<template>
  <t-dialog
    v-model:visible="rescueVisible"
    :header="$t('services.console.rescueMode')"
    width="min(32rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <t-alert theme="warning" :message="$t('services.console.rescueModeTip')" />
    <div class="dialog-form">
      <label>
        <span>{{ $t('services.console.rescueSystem') }}</span>
        <t-select v-model="rescueState.system" :placeholder="$t('services.console.rescueSystemPlaceholder')">
          <t-option :label="$t('services.console.linuxRescueSystem')" value="1" />
          <t-option :label="$t('services.console.windowsRescueSystem')" value="2" />
        </t-select>
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="rescueVisible = false">{{ $t('services.console.cancel') }}</t-button>
      <t-button theme="warning" :loading="actionLoading" @click="submitRescue">{{
        $t('services.console.confirmEnter')
      }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { rescueVisible, rescueState, actionLoading, submitRescue } = useServiceConsoleContext();
</script>
