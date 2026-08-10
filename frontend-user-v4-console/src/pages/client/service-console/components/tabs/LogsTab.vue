<template>
  <section class="console-panel-section">
    <t-card title="操作日志" :bordered="false">
      <template #actions>
        <t-button :loading="logsState.loading" @click="loadLogs">刷新</t-button>
      </template>
      <div class="log-summary">
        <span>共 {{ logsState.summary.total || logsState.total || 0 }} 条</span>
        <span>今日 {{ logsState.summary.today_total || 0 }} 条</span>
        <span v-if="logsState.summary.latest_created_at">最近 {{ logsState.summary.latest_created_at }}</span>
      </div>
      <t-table row-key="id" :data="logsState.list" :columns="logColumns" :pagination="null" size="small" />
      <div v-if="logsState.total > 0" class="console-pagination">
        <t-pagination
          v-model="logsState.page"
          v-model:page-size="logsState.page_size"
          :total="logsState.total"
          :page-size-options="[10, 20, 50]"
          show-total
          @change="loadLogs"
        />
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { logColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';

const { logsState, loadLogs } = useServiceConsoleContext();
</script>
