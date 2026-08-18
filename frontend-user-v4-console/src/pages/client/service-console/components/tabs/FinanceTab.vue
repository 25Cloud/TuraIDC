<template>
  <section class="console-panel-section">
    <t-card title="财务日志" :bordered="false">
      <template #actions>
        <t-button :loading="financeState.loading" @click="loadFinanceLogs">刷新</t-button>
      </template>
      <div class="log-summary finance-summary">
        <span>共 {{ financeState.summary.total_count || financeState.total || 0 }} 条</span>
        <span>收入 ¥{{ formatMoney(financeState.summary.total_in || 0) }}</span>
        <span>支出 ¥{{ formatMoney(financeState.summary.total_out || 0) }}</span>
        <span>退款 ¥{{ formatMoney(financeState.summary.refund_in || 0) }}</span>
      </div>
      <data-state
        :loading="financeState.loading"
        :empty="!financeState.list.length"
        :description="financeState.error || '暂无财务记录'"
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
            @change="loadFinanceLogs"
          />
        </div>
      </data-state>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import {
  financeColumns,
  resolveFinanceBusinessLabel,
  resolveFinanceEventLabel,
  resolveFinanceTagTheme,
} from '../../composables/useConsoleTables';
import { useServiceConsoleContext } from '../context';

const { financeState, formatMoney, loadFinanceLogs } = useServiceConsoleContext();
</script>
