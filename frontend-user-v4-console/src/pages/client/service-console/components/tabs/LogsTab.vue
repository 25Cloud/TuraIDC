<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.logs.title')" :bordered="false">
      <template #actions>
        <t-button :loading="logsState.loading" @click="loadLogs()">{{ $t('serviceConsole.logs.refresh') }}</t-button>
      </template>
      <div class="log-summary">
        <span>{{
          $t('serviceConsole.logs.totalCount', { count: logsState.summary.total || logsState.total || 0 })
        }}</span>
        <span>{{ $t('serviceConsole.logs.todayCount', { count: logsState.summary.today_total || 0 }) }}</span>
        <span v-if="logsState.summary.latest_created_at">{{
          $t('serviceConsole.logs.latestAt', { time: logsState.summary.latest_created_at })
        }}</span>
      </div>
      <!-- 首次加载盖遮罩 + 骨架；失败与「暂无日志」分成两个可区分的视图 -->
      <panel-state
        :loading="logsState.loading"
        :loaded="logsState.loaded"
        :error="logsState.error"
        :empty="logsState.list.length === 0"
        :empty-text="$t('serviceConsole.logs.empty')"
        @retry="loadLogs"
      >
        <t-table row-key="id" :data="logsState.list" :columns="logColumns" :pagination="null" size="small" />
        <div v-if="logsState.total > 0" class="console-pagination">
          <t-pagination
            v-model="logsState.page"
            v-model:page-size="logsState.page_size"
            :total="logsState.total"
            :page-size-options="[10, 20, 50]"
            show-total
            @change="loadLogs()"
          />
        </div>
      </panel-state>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { logColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';
import PanelState from '../PanelState.vue';

const { logsState, loadLogs } = useServiceConsoleContext();
</script>
