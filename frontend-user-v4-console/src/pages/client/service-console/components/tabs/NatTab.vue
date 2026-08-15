<template>
  <section class="console-panel-section">
    <t-card title="端口转发" :bordered="false">
      <template #actions>
        <t-space>
          <t-button :loading="natState.loading" @click="loadNatForwardings">刷新</t-button>
          <t-button
            v-if="natState.can_create"
            theme="primary"
            :disabled="natState.supported === false"
            @click="openNatForwardingDialog"
            >添加端口转发</t-button
          >
        </t-space>
      </template>
      <t-alert v-if="natState.error" theme="warning" class="console-inline-alert">{{ natState.error }}</t-alert>
      <t-empty
        v-else-if="natState.supported === false"
        :description="natState.message || '当前实例暂不支持 NAT 转发'"
      />
      <t-table v-else row-key="id" :data="natState.list" :columns="natColumns" :pagination="null" size="small">
        <template #operation="{ row }">
          <t-button
            v-if="row.can_delete !== false"
            theme="danger"
            variant="text"
            :disabled="natState.submitting"
            @click="deleteNatForwarding(row)"
            >删除</t-button
          >
          <span v-else>--</span>
        </template>
      </t-table>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { natColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';

const { natState, loadNatForwardings, openNatForwardingDialog, deleteNatForwarding } = useServiceConsoleContext();
</script>
