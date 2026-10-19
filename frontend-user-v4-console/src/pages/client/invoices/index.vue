<template>
  <section class="record-page client-invoices">
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
          :placeholder="$t('client.invoices.searchPlaceholder')"
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
          :placeholder="$t('client.invoices.allStatusPlaceholder')"
          @change="handleSearch"
        >
          <t-option v-for="item in INVOICE_STATUS_OPTIONS" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>

        <t-select
          v-if="showTypeSelector"
          v-model="filters.type"
          clearable
          :placeholder="$t('client.invoices.allTypePlaceholder')"
          @change="handleSearch"
        >
          <t-option v-for="item in INVOICE_TYPE_OPTIONS" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </div>
    </t-card>

    <section class="record-list-card">
      <data-state
        :loading-text="$t('shared.state.loading')"
        :empty-text="$t('shared.state.empty')"
        :loading="loading"
        :empty="!list.length"
        :description="listError || $t('client.invoices.empty')"
      >
        <t-table class="record-table" row-key="id" :data="list" :columns="columns" :pagination="null" hover>
          <template #invoice="{ row }">
            <div class="stack-cell">
              <strong>{{ resolveInvoiceNo(row) }}</strong>
              <span>{{ row.type_label || '--' }}</span>
            </div>
          </template>
          <template #amount="{ row }">
            <span class="invoice-money">¥{{ formatMoney(row.amount) }}</span>
          </template>
          <template #paid="{ row }">
            <span class="invoice-money">¥{{ formatMoney(row.paid_amount) }}</span>
          </template>
          <template #related_refs="{ row }">
            <div class="stack-cell">
              <div class="related-ref-line">
                <span class="related-ref-label">{{ $t('client.invoices.orderLabel') }}</span>
                <t-button v-if="row.order" variant="text" theme="primary" size="small" @click="goToOrder(row)">
                  {{ invoiceOrderNo(row) }}
                </t-button>
                <span v-else>--</span>
                <status-tag
                  v-if="row.order"
                  :status-map="ORDER_STATUS_MAP"
                  :status="Number(row.order.status)"
                  size="small"
                />
              </div>
              <div class="related-ref-line">
                <span class="related-ref-label">{{ $t('client.invoices.rechargeLabel') }}</span>
                <t-button
                  v-if="isRechargeInvoice(row) && row.payment_summary?.id"
                  variant="text"
                  theme="primary"
                  size="small"
                  @click="goToPayment(row)"
                >
                  {{ rechargePaymentNo(row) }}
                </t-button>
                <span v-else>{{ rechargePaymentNo(row) }}</span>
                <status-tag
                  v-if="isRechargeInvoice(row) && row.payment_summary"
                  :status-map="PAYMENT_STATUS_MAP"
                  :status="Number(row.payment_summary.status)"
                  size="small"
                />
              </div>
            </div>
          </template>
          <template #status="{ row }">
            <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(row.status)" />
          </template>
          <template #operation="{ row }">
            <t-space>
              <t-button size="small" theme="primary" variant="text" @click="goToDetail(row)">{{
                $t('client.invoices.view')
              }}</t-button>
              <t-button
                v-if="isPayableInvoice(row)"
                size="small"
                theme="primary"
                variant="outline"
                @click="goToPay(row)"
              >
                {{ $t('client.invoices.goPay') }}
              </t-button>
            </t-space>
          </template>
        </t-table>

        <div class="record-mobile-list">
          <article v-for="row in list" :key="row.id" class="record-mobile-card">
            <div class="record-mobile-card__head">
              <strong>{{ resolveInvoiceNo(row) }}</strong>
              <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(row.status)" />
            </div>

            <div class="stack-cell">
              <strong>{{ row.type_label || '--' }}</strong>
              <span>{{ $t('client.invoices.amountLabel') }}¥{{ formatMoney(row.amount) }}</span>
              <span>{{ $t('client.invoices.paidLabel') }}¥{{ formatMoney(row.paid_amount) }}</span>
            </div>

            <div class="record-mobile-card__meta">
              <span>{{ $t('client.invoices.orderPrefix') }}{{ invoiceOrderNo(row) }}</span>
              <span>{{ $t('client.invoices.rechargePrefix') }}{{ rechargePaymentNo(row) }}</span>
              <span>{{ row.created_at || '--' }}</span>
            </div>

            <div class="record-mobile-card__actions">
              <t-button size="small" theme="primary" variant="text" @click="goToDetail(row)">{{
                $t('client.invoices.view')
              }}</t-button>
              <t-button
                v-if="isPayableInvoice(row)"
                size="small"
                theme="primary"
                variant="outline"
                @click="goToPay(row)"
              >
                {{ $t('client.invoices.goPay') }}
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
import { INVOICE_STATUS_MAP, ORDER_STATUS_MAP, PAYMENT_STATUS_MAP } from '@turaidc/shared/statusConfig';
import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { useRouter } from 'vue-router';

import {
  formatMoney,
  INVOICE_STATUS_OPTIONS,
  INVOICE_TYPE_OPTIONS,
  isPayableInvoice,
  resolveInvoiceNo,
  useInvoiceList,
} from '@/domains/finance/useInvoices';
import { t } from '@/locales';
import type { InvoiceRecord } from '@/types/client';

const router = useRouter();

const {
  loading,
  list,
  listError,
  total,
  filters,
  loadList,
  handleSearch,
  handlePageSizeChange,
  applyQuickFilter,
  goToPay,
  showTypeSelector,
} = useInvoiceList();

const quickFilters = [
  { key: '', label: t('client.services.all') },
  { key: 'week', label: t('client.invoices.last7Days') },
  { key: 'month', label: t('client.invoices.thisMonth') },
  { key: 'pending', label: t('client.invoices.pending') },
];

function goToDetail(row: InvoiceRecord) {
  router.push(`/client/invoices/${row.id}`);
}

function goToOrder(row: InvoiceRecord) {
  const orderId = Number(row.order?.id || 0);
  if (!orderId) return;
  router.push(`/client/orders/${orderId}`);
}

function goToPayment(row: InvoiceRecord) {
  const paymentId = Number(row.payment_summary?.id || 0);
  if (!paymentId) return;
  router.push(`/client/payments/${paymentId}`);
}

const columns: PrimaryTableCol[] = [
  { colKey: 'invoice', title: t('client.invoiceDetail.invoiceNo'), minWidth: '12rem' },
  { colKey: 'amount', title: t('client.invoiceDetail.invoiceAmount'), width: '9rem' },
  { colKey: 'paid', title: t('client.invoiceDetail.paidAmount'), width: '9rem' },
  { colKey: 'related_refs', title: t('client.invoices.relatedRefs'), minWidth: '14rem' },
  { colKey: 'status', title: t('common.status'), width: '8rem' },
  { colKey: 'created_at', title: t('common.createdAt'), minWidth: '12rem' },
  { colKey: 'operation', title: t('common.action'), width: '10rem', fixed: 'right', align: 'right' },
];

function invoiceOrderNo(row: InvoiceRecord) {
  return String(row.order?.order_no || '--');
}

function isRechargeInvoice(row: InvoiceRecord) {
  return String(row.type || '').trim() === 'recharge';
}

function rechargePaymentNo(row: InvoiceRecord) {
  if (!isRechargeInvoice(row)) return '--';

  return String(row.payment_summary?.payment_no || '--');
}
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

.invoice-money {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  font-weight: 600;
}

.related-ref-line {
  display: flex;
  align-items: center;
  min-height: 1.75rem;
  gap: var(--td-comp-margin-xs);
}

.related-ref-label {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  min-width: 2rem;
}
</style>
