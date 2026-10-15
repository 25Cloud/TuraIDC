<template>
  <section class="record-page">
    <div class="quick-filter-tags">
      <t-tag
        v-for="item in quickFilters"
        :key="item.key"
        :variant="filters.quickFilter === item.key ? 'outline' : 'light'"
        :theme="filters.quickFilter === item.key ? 'primary' : 'default'"
        class="quick-filter-tag"
        @click="applyQuickFilter(item.key)"
      >
        {{ item.label }}
      </t-tag>
    </div>

    <t-card class="record-card" :bordered="false">
      <div class="record-toolbar">
        <t-input
          v-model="filters.keyword"
          clearable
          :placeholder="$t('client.payments.searchPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffixIcon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.status" clearable :placeholder="$t('client.payments.allStatusPlaceholder')" @change="handleSearch">
          <t-option v-for="item in PAYMENT_STATUS_OPTIONS" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-select v-model="filters.type" clearable :placeholder="$t('client.payments.allGatewayPlaceholder')" @change="handleSearch">
          <t-option v-for="item in PAYMENT_GATEWAY_OPTIONS" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </div>
    </t-card>

    <section class="record-list-card">
      <data-state :loading="loading" :empty="!hasRows" :description="$t('client.payments.empty')">
        <t-table class="record-table" row-key="id" :data="list" :columns="columns" :pagination="null" hover>
          <template #payment="{ row }">
            <div class="stack-cell">
              <strong>{{ $t('client.payments.merchantLabel') }}{{ row.payment_no || '--' }}</strong>
              <span>{{ $t('client.payments.thirdPartyLabel') }}{{ row.trade_no || '--' }}</span>
            </div>
          </template>
          <template #gateway="{ row }">{{ gatewayDisplay(row) }}</template>
          <template #amount="{ row }">¥{{ formatMoney(row.amount) }}</template>
          <template #status="{ row }">
            <status-tag :status-map="PAYMENT_STATUS_MAP" :status="Number(row.status)" />
          </template>
          <template #time="{ row }">{{ row.paid_at || row.created_at || '--' }}</template>
          <template #operation="{ row }">
            <t-button size="small" theme="primary" variant="text" @click="goToDetail(row)">{{ $t('client.payments.view') }}</t-button>
          </template>
        </t-table>

        <div class="record-mobile-list">
          <article v-for="row in list" :key="row.id" class="record-mobile-card" @click="goToDetail(row)">
            <div class="record-mobile-card__head">
              <strong>{{ $t('client.payments.merchantLabel') }}{{ row.payment_no || '--' }}</strong>
              <status-tag :status-map="PAYMENT_STATUS_MAP" :status="Number(row.status)" />
            </div>
            <div class="stack-cell">
              <strong>¥{{ formatMoney(row.amount) }}</strong>
              <span>{{ gatewayDisplay(row) }}</span>
            </div>
            <div class="record-mobile-card__meta">
              <span>{{ $t('client.payments.thirdPartyLabel') }}{{ row.trade_no || $t('client.payments.waitingCallback') }}</span>
              <span>{{ row.paid_at || row.created_at || '--' }}</span>
            </div>
          </article>
        </div>
      </data-state>
    </section>

    <div v-if="total > 0" class="record-pagination">
      <t-pagination
        v-model="filters.page"
        v-model:page-size="filters.page_size"
        :page-size-options="[10, 20, 50]"
        :total="total"
        show-total
        @change="loadList"
        @page-size-change="handlePageSizeChange"
      />
    </div>
  </section>
</template>
<script setup lang="ts">
import { PAYMENT_STATUS_MAP } from '@shared/statusConfig';
import DataState from '@shared/user-v3/components/DataState.vue';
import StatusTag from '@shared/user-v3/components/StatusTag.vue';
import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { useRouter } from 'vue-router';

import {
  formatMoney,
  PAYMENT_GATEWAY_OPTIONS,
  PAYMENT_STATUS_OPTIONS,
  recordApi,
  useRecordList,
} from '@/domains/finance/useRecords';
import { t } from '@/locales';
import type { PaymentRecord } from '@/types/client';

const router = useRouter();

const { loading, list, total, filters, hasRows, loadList, handleSearch, handlePageSizeChange, applyQuickFilter } =
  useRecordList(recordApi.payments, '第三方支付记录加载失败');

const quickFilters = [
  { key: '', label: t('client.payments.all') },
  { key: 'week', label: t('client.payments.last7Days') },
  { key: 'month', label: t('client.payments.thisMonth') },
  { key: 'pending', label: t('client.payments.pending') },
];

function goToDetail(row: PaymentRecord) {
  router.push(`/client/payments/${row.id}`);
}

function gatewayDisplay(row: PaymentRecord) {
  return row.gateway_label || row.gateway_key || row.gateway || '--';
}

const columns: PrimaryTableCol[] = [
  { colKey: 'payment', title: t('client.payments.orderNo'), minWidth: '16rem' },
  { colKey: 'gateway', title: t('client.payments.gateway'), width: '8rem' },
  { colKey: 'amount', title: t('client.payments.amount'), width: '9rem' },
  { colKey: 'status', title: t('common.status'), width: '8rem' },
  { colKey: 'time', title: t('common.time'), minWidth: '12rem' },
  { colKey: 'operation', title: t('common.action'), width: '6rem', fixed: 'right', align: 'right' },
];
</script>
<style scoped lang="less">
@import '../record-page.less';

.quick-filter-tags {
  display: flex;
  gap: var(--td-comp-margin-s);
  margin-bottom: var(--td-comp-margin-m);
  flex-wrap: wrap;
}

.quick-filter-tag {
  cursor: pointer;
  user-select: none;
}
</style>
