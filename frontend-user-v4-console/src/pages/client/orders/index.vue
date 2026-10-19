<template>
  <section class="record-page client-orders">
    <!-- 快捷筛选标签 -->
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
          :placeholder="$t('orders.searchPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffixIcon>
            <search-icon />
          </template>
        </t-input>

        <t-select
          v-model="filters.status"
          clearable
          :placeholder="$t('orders.allStatusPlaceholder')"
          @change="handleSearch"
        >
          <t-option v-for="item in ORDER_STATUS_OPTIONS" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>

        <t-select
          v-model="filters.type"
          clearable
          :placeholder="$t('orders.allTypePlaceholder')"
          @change="handleSearch"
        >
          <t-option v-for="item in ORDER_TYPE_OPTIONS" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </div>
    </t-card>

    <section class="record-list-card">
      <data-state
        :loading-text="$t('shared.state.loading')"
        :empty-text="$t('shared.state.empty')"
        :loading="loading"
        :empty="!list.length"
        :description="listError || $t('orders.emptyOrders')"
      >
        <t-table class="record-table" row-key="id" :data="list" :columns="columns" :pagination="null" hover>
          <template #order="{ row }">
            <div class="stack-cell">
              <strong>{{ row.order_no || '--' }}</strong>
              <span>{{ row.type_label || '--' }}</span>
            </div>
          </template>
          <template #product="{ row }">
            <div class="stack-cell">
              <strong>{{ orderProductDisplay(row) }}</strong>
              <span v-if="row.service_name">{{ row.service_name }}</span>
            </div>
          </template>
          <template #amount="{ row }">
            <span class="order-money">¥{{ formatMoney(row.amount) }}</span>
          </template>
          <template #status="{ row }">
            <status-tag :status-map="ORDER_STATUS_MAP" :status="Number(row.status)" />
          </template>
          <template #invoice_ref="{ row }">
            <div v-if="row.invoice" class="stack-cell">
              <t-button
                variant="text"
                theme="primary"
                size="small"
                @click="router.push(`/client/invoices/${row.invoice.id}`)"
              >
                {{ row.invoice.invoice_no || '--' }}
              </t-button>
              <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(row.invoice.status)" size="small" />
            </div>
            <span v-else>--</span>
          </template>
          <template #created_at="{ row }">{{ row.created_at || '--' }}</template>
          <template #operation="{ row }">
            <t-space>
              <t-button size="small" theme="primary" variant="text" @click="router.push(`/client/orders/${row.id}`)">
                {{ t('pages.listBase.detail') }}
              </t-button>
              <t-button v-if="canPayOrder(row)" size="small" theme="primary" variant="outline" @click="goPayOrder(row)">
                {{ t('client.invoices.goPay') }}
              </t-button>
              <t-button
                v-if="Number(row.status) === 0"
                size="small"
                theme="danger"
                variant="text"
                :loading="canceling"
                @click="cancelOrder(row)"
              >
                {{ t('common.cancel') }}
              </t-button>
            </t-space>
          </template>
        </t-table>

        <div class="record-mobile-list">
          <article
            v-for="row in list"
            :key="row.id"
            class="record-mobile-card"
            @click="router.push(`/client/orders/${row.id}`)"
          >
            <div class="record-mobile-card__head">
              <strong>{{ row.order_no || '--' }}</strong>
              <status-tag :status-map="ORDER_STATUS_MAP" :status="Number(row.status)" />
            </div>
            <div class="stack-cell">
              <strong>{{ orderProductDisplay(row) }}</strong>
              <span>{{ row.type_label || '--' }}</span>
              <span>¥{{ formatMoney(row.amount) }}</span>
            </div>
            <div class="record-mobile-card__meta">
              <span v-if="row.invoice">{{ $t('orders.invoice') }}: {{ row.invoice.invoice_no || '--' }}</span>
              <span>{{ row.created_at || '--' }}</span>
            </div>
            <div class="record-mobile-card__actions">
              <t-button
                size="small"
                theme="primary"
                variant="text"
                @click.stop="router.push(`/client/orders/${row.id}`)"
              >
                {{ t('pages.listBase.detail') }}
              </t-button>
              <t-button
                v-if="canPayOrder(row)"
                size="small"
                theme="primary"
                variant="outline"
                @click.stop="goPayOrder(row)"
              >
                {{ t('client.invoices.goPay') }}
              </t-button>
              <t-button
                v-if="Number(row.status) === 0"
                size="small"
                theme="danger"
                variant="text"
                :loading="canceling"
                @click.stop="cancelOrder(row)"
              >
                {{ t('common.cancel') }}
              </t-button>
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
import DataState from '@shared/user-v3/components/DataState.vue';
import StatusTag from '@shared/user-v3/components/StatusTag.vue';
import { INVOICE_STATUS_MAP, ORDER_STATUS_MAP } from '@turaidc/shared/statusConfig';
import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { useRouter } from 'vue-router';

import {
  formatMoney,
  ORDER_STATUS_OPTIONS,
  ORDER_TYPE_OPTIONS,
  orderProductDisplay,
  useOrderList,
} from '@/domains/finance/useOrders';
import { t } from '@/locales';

const router = useRouter();

const {
  loading,
  canceling,
  list,
  listError,
  total,
  filters,
  loadList,
  handleSearch,
  handlePageSizeChange,
  applyQuickFilter,
  cancelOrder,
} = useOrderList();

function invoicePayId(row: { invoice?: { id?: number | string | null } | null; invoice_id?: number | string | null }) {
  const id = Number(row.invoice?.id || row.invoice_id || 0);
  return Number.isFinite(id) && id > 0 ? id : 0;
}

function canPayOrder(row: {
  status?: number | string;
  invoice?: { id?: number | string | null } | null;
  invoice_id?: number | string | null;
}) {
  return Number(row.status) === 0 && invoicePayId(row) > 0;
}

function goPayOrder(row: { invoice?: { id?: number | string | null } | null; invoice_id?: number | string | null }) {
  const id = invoicePayId(row);
  if (id > 0) {
    router.push(`/client/invoices/${id}/pay`);
  }
}

const quickFilters = [
  { key: '', label: t('orders.filterAll') },
  { key: 'week', label: t('orders.filterLast7Days') },
  { key: 'month', label: t('orders.filterThisMonth') },
  { key: 'pending', label: t('orders.statusPending') },
];

const columns: PrimaryTableCol[] = [
  { colKey: 'order', title: t('orders.orderNo'), minWidth: '12rem' },
  { colKey: 'product', title: t('orders.productService'), minWidth: '12rem' },
  { colKey: 'amount', title: t('orders.amount'), width: '9rem' },
  { colKey: 'status', title: t('common.status'), width: '8rem' },
  { colKey: 'invoice_ref', title: t('orders.relatedInvoice'), minWidth: '12rem' },
  { colKey: 'created_at', title: t('orders.createdAt'), minWidth: '12rem' },
  { colKey: 'operation', title: t('common.action'), width: '14rem', fixed: 'right', align: 'right' },
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

.order-money {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  font-weight: 600;
}
</style>
