<template>
  <t-dialog
    v-model:visible="reinstallVisible"
    :header="$t('services.console.reinstallTitle')"
    width="min(34rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <loading-state :loading="reinstallState.loading" :text="$t('services.console.loadingSystemList')" compact>
      <div class="dialog-form">
        <label>
          <span>{{ $t('services.console.osGroup') }}</span>
          <t-select v-model="reinstallState.os_group" :placeholder="$t('services.console.selectOsGroup')" @change="handleReinstallGroupChange">
            <t-option
              v-for="group in reinstallGroupedOptions"
              :key="group.group_name"
              :label="group.group_name"
              :value="group.group_name"
            />
          </t-select>
        </label>
        <label>
          <span>{{ $t('services.console.osVersion') }}</span>
          <t-select v-model="reinstallState.os_id" :placeholder="$t('services.console.selectOsVersion')">
            <t-option
              v-for="item in currentReinstallOptions"
              :key="item.os_id"
              :label="item.name"
              :value="item.os_id"
            />
          </t-select>
        </label>
      </div>
    </loading-state>
    <template #footer>
      <t-button variant="outline" @click="reinstallVisible = false">{{ $t('services.console.cancel') }}</t-button>
      <t-button theme="primary" :loading="actionLoading" :disabled="!reinstallState.os_id" @click="submitReinstall"
        >{{ $t('services.console.submitReinstall') }}</t-button
      >
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';

import { useServiceConsoleContext } from '../context';

const {
  reinstallVisible,
  reinstallState,
  actionLoading,
  reinstallGroupedOptions,
  currentReinstallOptions,
  handleReinstallGroupChange,
  submitReinstall,
} = useServiceConsoleContext();
</script>
