<template>
  <section class="console-panel-section">
    <t-card title="端口转发" :bordered="false">
      <template #actions>
        <t-button :loading="natState.loading" @click="loadNatForwardings">刷新</t-button>
      </template>
      <t-alert v-if="natState.error" theme="warning" class="console-inline-alert">{{ natState.error }}</t-alert>
      <t-empty
        v-else-if="natState.supported === false"
        :description="natState.message || '当前实例暂不支持 NAT 转发'"
      />
      <t-table v-else row-key="id" :data="natState.list" :columns="natColumns" :pagination="null" size="small" />
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { natColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';

const { natState, loadNatForwardings } = useServiceConsoleContext();
</script>
