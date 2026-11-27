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
      <!-- 已经进过救援时，直接在同一个弹窗里给出退出入口，避免用户排障完无处可去 -->
      <t-button theme="default" variant="outline" :loading="actionLoading" @click="submitExitRescue">
        {{ $t('serviceConsole.rescue.exitConfirmBtn') }}
      </t-button>
      <t-button theme="warning" :loading="actionLoading" @click="submitRescue">{{
        $t('serviceConsole.rescue.confirm')
      }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { rescueVisible, rescueState, actionLoading, submitRescue, submitExitRescue } = useServiceConsoleContext();
</script>
