<template>
  <section class="invoice-detail-page">
    <!-- 面包屑导航 -->
    <t-breadcrumb class="invoice-breadcrumb">
      <t-breadcrumb-item @click="router.push('/client/dashboard')">{{
        $t('client.invoiceDetail.financeCenter')
      }}</t-breadcrumb-item>
      <t-breadcrumb-item @click="router.push('/client/invoices')">{{
        $t('client.invoiceDetail.invoiceRecords')
      }}</t-breadcrumb-item>
      <t-breadcrumb-item>{{ $t('client.invoiceDetail.invoiceDetail') }}</t-breadcrumb-item>
    </t-breadcrumb>

    <!-- 操作栏 -->
    <div class="detail-toolbar">
      <t-button variant="outline" @click="router.push('/client/invoices')">{{
        $t('client.invoiceDetail.backToList')
      }}</t-button>
      <div class="detail-toolbar__actions">
        <t-button variant="outline" :loading="loading" @click="loadInvoice()">
          <template #icon><refresh-icon /></template>
          {{ $t('client.invoiceDetail.refresh') }}
        </t-button>
        <t-button
          v-if="detail && isPayable(detail)"
          theme="primary"
          @click="router.push(`/client/invoices/${detail.id}/pay`)"
        >
          {{ $t('client.invoiceDetail.goPay') }}
        </t-button>
      </div>
    </div>

    <t-loading :loading="loading" :text="$t('client.invoiceDetail.loading')">
      <template v-if="detail">
        <!-- 标签页 -->
        <t-card :bordered="false" class="invoice-detail-tabs-card">
          <t-tabs v-model="activeTab">
            <t-tab-panel value="basic" :label="$t('client.invoiceDetail.basicTab')">
              <div class="detail-kv-grid">
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.invoiceNo') }}</span>
                  <strong>{{ detail.invoice_no || '--' }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.invoiceType') }}</span>
                  <strong>{{ detail.type_label || typeLabel(detail.type) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.invoiceStatus') }}</span>
                  <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(detail.status)" />
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.invoiceAmount') }}</span>
                  <strong>¥{{ formatMoney(detail.amount) }}</strong>
                </div>
                <div v-if="Number(detail.discount || 0) > 0" class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.discountAmount') }}</span>
                  <strong class="detail-kv-discount">-¥{{ formatMoney(detail.discount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.paidAmount') }}</span>
                  <strong>¥{{ formatMoney(detail.paid_amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.payableAmount') }}</span>
                  <strong>¥{{ formatMoney(detail.payable_amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.billingCycle') }}</span>
                  <strong>{{ billingCycleLabel(detail.billing_cycle) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.createdAt') }}</span>
                  <strong>{{ detail.created_at || '--' }}</strong>
                </div>
                <div v-if="detail.due_date" class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.dueDate') }}</span>
                  <strong>{{ detail.due_date }}</strong>
                </div>
                <div v-if="detail.paid_at" class="detail-kv-item">
                  <span>{{ $t('client.invoiceDetail.paidAt') }}</span>
                  <strong>{{ detail.paid_at }}</strong>
                </div>
              </div>
            </t-tab-panel>

            <t-tab-panel value="related" :label="$t('client.invoiceDetail.relatedOrderTab')">
              <!-- 关联订单 -->
              <section v-if="detail.order" class="related-section">
                <h4>{{ $t('client.invoiceDetail.relatedOrderTitle') }}</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>{{ $t('client.invoiceDetail.orderNo') }}</span>
                    <t-button
                      variant="text"
                      theme="primary"
                      size="small"
                      @click="router.push(`/client/orders/${detail.order.id}`)"
                    >
                      {{ detail.order.order_no || '--' }}
                    </t-button>
                  </div>
                  <div class="detail-kv-item">
                    <span>{{ $t('client.invoiceDetail.orderType') }}</span>
                    <strong>{{ detail.order.type_label || '--' }}</strong>
                  </div>
                  <div class="detail-kv-item">
                    <span>{{ $t('client.invoiceDetail.orderStatus') }}</span>
                    <status-tag :status-map="ORDER_STATUS_MAP" :status="Number(detail.order.status)" />
                  </div>
                </div>
              </section>
              <div v-else class="related-empty">{{ $t('client.invoiceDetail.noRelatedOrder') }}</div>

              <!-- 优惠券 -->
              <section v-if="detail.coupon_code || detail.coupon" class="related-section">
                <h4>{{ $t('client.invoiceDetail.couponUsage') }}</h4>
                <div class="detail-kv-grid">
                  <div v-if="detail.coupon_code" class="detail-kv-item">
                    <span>{{ $t('client.invoiceDetail.couponCode') }}</span>
                    <strong>{{ detail.coupon_code }}</strong>
                  </div>
                  <div v-if="couponName" class="detail-kv-item">
                    <span>{{ $t('client.invoiceDetail.coupon') }}</span>
                    <strong>{{ couponName }}</strong>
                  </div>
                  <div v-if="couponDiscountLabel" class="detail-kv-item">
                    <span>{{ $t('client.invoiceDetail.couponDiscount') }}</span>
                    <strong>{{ couponDiscountLabel }}</strong>
                  </div>
                </div>
              </section>
            </t-tab-panel>

            <t-tab-panel value="items" :label="$t('client.invoiceDetail.itemsTab')">
              <t-table
                v-if="invoiceItems.length"
                :data="invoiceItems"
                :columns="itemColumns"
                :pagination="null"
                row-key="id"
                size="small"
                hover
              >
                <template #description="{ row }">{{ row.description || '--' }}</template>
                <template #quantity="{ row }">{{ formatQuantity(row.quantity) }}</template>
                <template #unitPrice="{ row }">¥{{ formatMoney(row.unit_price) }}</template>
                <template #discountAmount="{ row }">
                  {{ Number(row.discount_amount || 0) > 0 ? `-¥${formatMoney(row.discount_amount)}` : '--' }}
                </template>
                <template #amount="{ row }">¥{{ formatMoney(row.amount) }}</template>
              </t-table>
              <div v-else class="related-empty">{{ $t('client.invoiceDetail.noItems') }}</div>
            </t-tab-panel>

            <t-tab-panel value="payments" :label="$t('client.invoiceDetail.paymentsTab')">
              <div v-if="paymentRecords.length" class="payment-list">
                <t-table
                  :data="paymentRecords"
                  :columns="paymentColumns"
                  :pagination="null"
                  row-key="id"
                  size="small"
                  hover
                >
                  <template #amount="{ row }">¥{{ formatMoney(row.amount) }}</template>
                  <template #status="{ row }">
                    <status-tag :status-map="PAYMENT_STATUS_MAP" :status="Number(row.status)" />
                  </template>
                  <template #gateway="{ row }">{{ paymentGatewayDisplay(row) }}</template>
                </t-table>
              </div>
              <div v-else class="related-empty">{{ $t('client.invoiceDetail.noPayments') }}</div>
            </t-tab-panel>
          </t-tabs>
        </t-card>
      </template>

      <div v-else-if="!loading" class="detail-empty">
        <t-button variant="outline" @click="router.push('/client/invoices')">{{
          $t('client.invoiceDetail.backToInvoices')
        }}</t-button>
      </div>
    </t-loading>
  </section>
</template>
<script setup lang="ts">
import StatusTag from '@shared/user-v3/components/StatusTag.vue';
import { INVOICE_STATUS_MAP, ORDER_STATUS_MAP, PAYMENT_STATUS_MAP } from '@turaidc/shared/statusConfig';
import { RefreshIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { formatMoney, isPayableInvoice } from '@/domains/finance/useInvoices';
import { formatBillingCycle } from '@/domains/finance/useRecords';
import { t } from '@/locales';
import type { InvoiceItemInfo, InvoiceRecord } from '@/types/client';

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const detail = ref<InvoiceRecord | null>(null);
const activeTab = ref('basic');

const invoiceId = computed(() => Number(route.params.id || 0));

function isPayable(row: InvoiceRecord | null) {
  return isPayableInvoice(row);
}

function billingCycleLabel(value?: string) {
  return formatBillingCycle(value);
}

function typeLabel(type?: string) {
  const map: Record<string, string> = {
    new: t('invoiceDetail.typeNew'),
    normal: t('invoiceDetail.typeNew'),
    renew: t('invoiceDetail.typeRenew'),
    upgrade: t('invoiceDetail.typeUpgrade'),
    recharge: t('invoiceDetail.typeRecharge'),
    deduction: t('invoiceDetail.typeDeduction'),
    referral_credit: t('invoiceDetail.typeReferralCredit'),
    manual: t('invoiceDetail.typeManual'),
  };
  return type ? map[type] || type : '--';
}

const paymentRecords = computed(() => {
  const payments = detail.value?.payments;
  if (!Array.isArray(payments)) return [];
  return payments;
});

// 明细行由后端 InvoiceService::buildInvoiceItems 透出，
// 含数量 / 单价 / 行优惠；此前只在后端落库、前端从不展示。
const invoiceItems = computed<InvoiceItemInfo[]>(() => {
  const items = detail.value?.items;
  if (!Array.isArray(items)) return [];
  return items;
});

const couponName = computed(() => {
  const coupon = detail.value?.coupon;
  if (!coupon || typeof coupon !== 'object') return '';
  return String((coupon as Record<string, unknown>).name || '');
});

const couponDiscountLabel = computed(() => {
  const coupon = detail.value?.coupon;
  if (!coupon || typeof coupon !== 'object') return '';
  const record = coupon as Record<string, unknown>;
  const label = String(record.discount_label || '').trim();
  if (label) return label;
  const type = String(record.discount_type || '');
  const value = String(record.discount_value ?? record.discount_amount ?? '').trim();
  if (!type || !value) return '';
  return type === 'percent' ? `${value}%` : `¥${value}`;
});

function formatQuantity(value: unknown) {
  const num = Number(value || 0);
  return Number.isInteger(num) ? String(num) : num.toFixed(2);
}

const itemColumns: PrimaryTableCol[] = [
  { colKey: 'description', title: t('invoiceDetail.item'), minWidth: '14rem' },
  { colKey: 'quantity', title: t('invoiceDetail.quantity'), width: '7rem' },
  { colKey: 'unitPrice', title: t('invoiceDetail.unitPrice'), width: '8rem' },
  { colKey: 'discountAmount', title: t('invoiceDetail.discount'), width: '8rem' },
  { colKey: 'amount', title: t('invoiceDetail.subtotal'), width: '8rem' },
];

function paymentGatewayDisplay(row: Record<string, unknown>) {
  return String(row.gateway_label || row.gateway_key || row.gateway || '--');
}

const paymentColumns: PrimaryTableCol[] = [
  { colKey: 'payment_no', title: t('invoiceDetail.merchantOrderNo'), minWidth: '10rem' },
  { colKey: 'trade_no', title: t('invoiceDetail.thirdPartyOrderNo'), minWidth: '12rem' },
  { colKey: 'gateway', title: t('invoiceDetail.channel'), width: '8rem' },
  { colKey: 'amount', title: t('invoiceDetail.amount'), width: '8rem' },
  { colKey: 'status', title: t('common.status'), width: '8rem' },
  { colKey: 'paid_at', title: t('invoiceDetail.paidAt'), minWidth: '12rem' },
];

// 并发守卫：快速切换发票详情时丢弃晚返回的旧请求结果。
let requestSeq = 0;

async function loadInvoice(id = invoiceId.value) {
  const validId = Number(id);
  if (!Number.isInteger(validId) || validId <= 0) {
    // 无效 ID（0/负数/NaN）：失效进行中的请求并复位详情与加载态，避免调用接口。
    requestSeq += 1;
    detail.value = null;
    loading.value = false;
    return;
  }
  const seq = ++requestSeq;
  loading.value = true;
  try {
    const res = await clientApi.invoiceDetail(validId);
    if (seq !== requestSeq) {
      return;
    }
    detail.value = res.data || null;
  } catch {
    if (seq !== requestSeq) {
      return;
    }
    detail.value = null;
  } finally {
    if (seq === requestSeq) {
      loading.value = false;
    }
  }
}

watch(
  invoiceId,
  (id) => {
    requestSeq += 1; // 失效进行中的旧请求，避免其晚返回覆盖当前路由
    detail.value = null;
    if (id > 0) {
      void loadInvoice(id);
    } else {
      loading.value = false;
    }
  },
  { immediate: true },
);
</script>
<style scoped lang="less">
.invoice-breadcrumb {
  margin-bottom: var(--td-comp-margin-m);
}

.detail-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--td-comp-margin-m);

  &__actions {
    display: flex;
    gap: var(--td-comp-margin-s);
  }
}

.invoice-detail-tabs-card {
  min-height: 18.75rem;
}

.detail-kv-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-m) 0;
}

.detail-kv-item {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);

  span {
    font-size: var(--td-font-size-body-small);
    color: var(--td-text-color-secondary);
  }

  strong {
    font-weight: 500;
    color: var(--td-text-color-primary);
    word-break: break-all;
  }
}

// 优惠金额用危险色，与账单金额的常规主色区分，避免用户把折后价当实付价。
.detail-kv-discount {
  color: var(--td-error-color) !important;
}

.related-section {
  padding: var(--td-comp-paddingTB-m) 0;
  border-bottom: thin solid var(--td-border-level-1-color);

  &:last-of-type {
    border-bottom: none;
  }

  h4 {
    font-size: var(--td-font-size-body-medium);
    font-weight: 600;
    color: var(--td-text-color-primary);
    margin-bottom: var(--td-comp-margin-xs);
  }
}

.related-empty {
  padding: var(--td-comp-paddingTB-xl) 0;
  text-align: center;
  color: var(--td-text-color-placeholder);
}

.payment-list {
  padding: var(--td-comp-paddingTB-m) 0;
}

.detail-empty {
  padding: var(--td-comp-paddingTB-xl) 0;
  text-align: center;
}

@media (width <= 40rem) {
  .detail-kv-grid {
    grid-template-columns: 1fr;
  }

  .detail-toolbar {
    flex-direction: column;
    gap: var(--td-comp-margin-s);
    align-items: flex-start;
  }
}
</style>
