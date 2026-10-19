<template>
  <section class="console-panel-section">
    <t-card :title="$t('serviceConsole.financeLog')" :bordered="false">
      <template #actions>
        <t-button :loading="financeState.loading" @click="loadFinanceLogs()">{{
          $t('serviceConsole.refresh')
        }}</t-button>
      </template>
      <div class="log-summary finance-summary">
        <span>{{
          $t('serviceConsole.finance.totalCount', {
            count: financeState.summary.total_count || financeState.total || 0,
          })
        }}</span>
        <span>{{ $t('serviceConsole.finance.totalIn') }} ¥{{ formatMoney(financeState.summary.total_in || 0) }}</span>
        <span>{{ $t('serviceConsole.finance.totalOut') }} ¥{{ formatMoney(financeState.summary.total_out || 0) }}</span>
        <span>{{ $t('serviceConsole.finance.refundIn') }} ¥{{ formatMoney(financeState.summary.refund_in || 0) }}</span>
      </div>
      <!-- 原来把错误文案直接塞进空态描述，失败和「没有记录」长得一模一样；
           这里改成三态容器：加载遮罩 / 错误块 + 重试 / 空态引导 -->
      <panel-state
        :loading="financeState.loading"
        :loaded="financeState.loaded"
        :error="financeState.error"
        :empty="financeState.list.length === 0"
        :empty-text="$t('serviceConsole.finance.empty')"
        @retry="loadFinanceLogs"
      >
        <t-table
          row-key="id"
          :data="financeState.list"
          :columns="financeColumns"
          :loading="financeState.loading"
          :pagination="null"
          size="small"
        >
          <template #event_type="{ row }">
            <div class="finance-type-cell">
              <t-tag size="small" :theme="resolveFinanceTagTheme(row)" variant="light">
                {{ resolveFinanceBusinessLabel(row) }}
              </t-tag>
              <span>{{ resolveFinanceEventLabel(row.event_type) || row.display?.badge || '--' }}</span>
            </div>
          </template>
          <template #amount="{ row }">
            <span
              class="finance-amount"
              :class="{
                'is-income': Number(row.change_amount || 0) > 0,
                'is-outcome': Number(row.change_amount || 0) < 0,
              }"
            >
              {{ Number(row.change_amount || 0) > 0 ? '+' : '' }}¥{{ formatMoney(row.change_amount || 0) }}
            </span>
          </template>
          <template #summary="{ row }">
            <div class="finance-summary-cell">
              <strong>{{ resolveFinanceBusinessLabel(row) }}</strong>
              <span>{{ row.remark || row.display?.subtitle || '--' }}</span>
            </div>
          </template>
          <template #invoice_no="{ row }">
            {{ row.invoice?.invoice_no || '--' }}
          </template>
        </t-table>
        <div v-if="financeState.total > 0" class="console-pagination">
          <t-pagination
            v-model="financeState.page"
            v-model:page-size="financeState.page_size"
            :total="financeState.total"
            :page-size-options="[10, 20, 50]"
            show-total
            @change="loadFinanceLogs()"
          />
        </div>
      </panel-state>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import {
  financeColumns,
  resolveFinanceBusinessLabel,
  resolveFinanceEventLabel,
  resolveFinanceTagTheme,
} from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';
import PanelState from '../PanelState.vue';

const { financeState, formatMoney, loadFinanceLogs } = useServiceConsoleContext();
</script>
