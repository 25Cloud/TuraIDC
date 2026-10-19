<template>
  <t-dialog
    v-model:visible="rescueVisible"
    :header="$t('serviceConsole.rescue.dialogHeader')"
    width="min(32rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <t-alert theme="warning" :message="$t('serviceConsole.rescue.dialogMessage')" />
    <div class="dialog-form">
      <label>
        <span>{{ $t('serviceConsole.rescue.title') }}</span>
        <t-select v-model="rescueState.system" :placeholder="$t('serviceConsole.rescue.placeholder')">
          <t-option :label="$t('serviceConsole.rescue.linux')" value="1" />
          <t-option :label="$t('serviceConsole.rescue.windows')" value="2" />
        </t-select>
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="rescueVisible = false">{{ $t('common.cancel') }}</t-button>
      <t-button theme="warning" :loading="actionLoading" @click="submitRescue">{{
        $t('serviceConsole.rescue.confirm')
      }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { rescueVisible, rescueState, actionLoading, submitRescue } = useServiceConsoleContext();
</script>
