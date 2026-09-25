<template>
  <t-dialog
    v-model:visible="reinstallVisible"
    header="重装系统"
    width="min(34rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <loading-state :loading="reinstallState.loading" text="正在加载系统列表" compact>
      <div class="dialog-form">
        <label>
          <span>系统分组</span>
          <t-select v-model="reinstallState.os_group" placeholder="请选择系统分组" @change="handleReinstallGroupChange">
            <t-option
              v-for="group in reinstallGroupedOptions"
              :key="group.group_name"
              :label="group.group_name"
              :value="group.group_name"
            />
          </t-select>
        </label>
        <label>
          <span>系统版本</span>
          <t-select v-model="reinstallState.os_id" placeholder="请选择系统版本">
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
      <t-button variant="outline" @click="reinstallVisible = false">取消</t-button>
      <t-button theme="primary" :loading="actionLoading" :disabled="!reinstallState.os_id" @click="submitReinstall"
        >提交重装</t-button
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
