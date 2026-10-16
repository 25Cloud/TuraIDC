<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.nat.title')" :bordered="false">
      <template #actions>
        <t-space>
          <t-button :loading="natState.loading" @click="loadNatForwardings">{{
            $t('serviceConsole.refresh')
          }}</t-button>
          <t-button
            v-if="natState.can_create"
            theme="primary"
            :disabled="natState.supported === false"
            @click="openNatForwardingDialog"
            >{{ $t('serviceConsole.nat.addForward') }}</t-button
          >
        </t-space>
      </template>
      <!-- 加载中盖整块遮罩 + 骨架；加载失败与「真的没有规则」分成两个可区分的视图 -->
      <panel-state
        :loading="natState.loading"
        :loaded="natState.loaded"
        :error="natState.error"
        :unsupported="natState.supported === false"
        :unsupported-text="natState.message || $t('serviceConsole.nat.unsupported')"
        :empty="natState.list.length === 0"
        :empty-text="
          natState.can_create ? $t('serviceConsole.nat.emptyCanCreate') : $t('serviceConsole.nat.emptyNotCreatable')
        "
        @retry="loadNatForwardings"
      >
        <t-table row-key="id" :data="natState.list" :columns="natColumns" :pagination="null" size="small">
          <template #operation="{ row }">
            <t-button
              v-if="row.can_delete !== false"
              theme="danger"
              variant="text"
              :disabled="natState.submitting"
              @click="deleteNatForwarding(row)"
              >{{ $t('serviceConsole.nat.delete') }}</t-button
            >
            <span v-else>--</span>
          </template>
        </t-table>
      </panel-state>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { natColumns } from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';
import PanelState from '../PanelState.vue';

const { natState, loadNatForwardings, openNatForwardingDialog, deleteNatForwarding } = useServiceConsoleContext();
</script>
