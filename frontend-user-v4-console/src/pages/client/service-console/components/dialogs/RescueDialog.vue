<template>
  <t-dialog
    v-model:visible="rescueVisible"
    header="进入救援模式"
    width="min(32rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <t-alert
      theme="warning"
      message="进入救援模式后实例将重启并挂载救援系统，用于排查系统故障。完成后请在救援系统中执行“exit rescue”或重新安装系统退出。"
    />
    <div class="dialog-form">
      <label>
        <span>救援系统</span>
        <t-select v-model="rescueState.system" placeholder="请选择救援系统">
          <t-option label="Linux 救援系统" value="1" />
          <t-option label="Windows 救援系统" value="2" />
        </t-select>
      </label>
    </div>
    <template #footer>
      <t-button variant="outline" @click="rescueVisible = false">取消</t-button>
      <t-button theme="warning" :loading="actionLoading" @click="submitRescue">确认进入</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from '../context';

const { rescueVisible, rescueState, actionLoading, submitRescue } = useServiceConsoleContext();
</script>
