<template>
  <section class="order-detail-page">
    <!-- 面包屑导航 -->
    <t-breadcrumb class="order-breadcrumb">
      <t-breadcrumb-item @click="router.push('/client/dashboard')">{{ $t('client.orderDetail.financeCenter') }}</t-breadcrumb-item>
      <t-breadcrumb-item @click="router.push('/client/orders')">{{ $t('client.orderDetail.orderRecords') }}</t-breadcrumb-item>
      <t-breadcrumb-item>{{ $t('client.orderDetail.orderDetail') }}</t-breadcrumb-item>
    </t-breadcrumb>

    <!-- 操作栏 -->
    <div class="detail-toolbar">
      <t-button variant="outline" @click="router.push('/client/orders')">{{ $t('client.orderDetail.backToList') }}</t-button>
      <div class="detail-toolbar__actions">
        <t-button variant="outline" :loading="loading" @click="loadDetail(orderId)">
          <template #icon><refresh-icon /></template>
          {{ $t('client.orderDetail.refresh') }}
        </t-button>
        <t-button v-if="canPayDetail" theme="primary" @click="goPayDetail">{{
            $t('client.orderDetail.goPay')
          }}</t-button>
        <t-button
          v-if="detail && Number(detail.status) === 0"
          theme="danger"
          variant="outline"
          :loading="canceling"
          @click="cancelOrder()"
        >
          {{ $t('client.orderDetail.cancelOrder') }}
        </t-button>
      </div>
    </div>

    <t-loading :loading="loading" :text="$t('client.orderDetail.loading')">
      <template v-if="detail">
        <!-- 标签页 -->
        <t-card :bordered="false" class="order-detail-tabs-card">
          <t-tabs v-model="activeTab">
            <t-tab-panel value="basic" :label="$t('client.orderDetail.basicTab')">
              <div class="detail-kv-grid">
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.orderNo') }}</span>
                  <strong>{{ detail.order_no || '--' }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.orderType') }}</span>
                  <strong>{{ detail.type_label || '--' }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.orderStatus') }}</span>
                  <status-tag :status-map="ORDER_STATUS_MAP" :status="Number(detail.status)" />
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.orderAmount') }}</span>
                  <strong>¥{{ formatMoney(detail.amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.discountAmount') }}</span>
                  <strong>¥{{ formatMoney(detail.discount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.paidAmount') }}</span>
                  <strong>¥{{ formatMoney(detail.paid_amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.productName') }}</span>
                  <strong>{{ orderProductDisplay(detail) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.billingCycle') }}</span>
                  <strong>{{ billingCycleLabel(detail.billing_cycle) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.quantity') }}</span>
                  <strong>{{ detail.quantity ?? 1 }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.createdAt') }}</span>
                  <strong>{{ detail.created_at || '--' }}</strong>
                </div>
                <div v-if="detail.paid_at" class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.paidAt') }}</span>
                  <strong>{{ detail.paid_at }}</strong>
                </div>
                <div v-if="detail.coupon_code" class="detail-kv-item">
                  <span>{{ $t('client.orderDetail.coupon') }}</span>
                  <strong>{{ detail.coupon?.name || detail.coupon_code }}</strong>
                </div>
                <div v-if="detail.remark" class="detail-kv-item detail-kv-item--wide">
                  <span>{{ $t('client.orderDetail.remark') }}</span>
                  <strong>{{ detail.remark }}</strong>
                </div>
              </div>
            </t-tab-panel>

            <t-tab-panel v-if="showConfigTab" value="config" :label="$t('client.orderDetail.configTab')">
              <div v-if="configSnapshotView.length" class="order-pricing-block">
                <section class="order-section">
                  <h4 class="order-section-title">{{ $t('client.orderDetail.configSnapshot') }}</h4>
                  <div class="snapshot-line-list">
                    <div v-for="item in configSnapshotView" :key="`cfg-${item.label}`" class="snapshot-line-item">
                      <span class="snapshot-line-label">{{ item.label }}</span>
                      <strong class="snapshot-line-value">{{ item.value }}</strong>
                    </div>
                  </div>
                </section>
              </div>
              <div v-if="pricingSnapshotView.length" class="order-pricing-block">
                <section class="order-section">
                  <h4 class="order-section-title">{{ $t('client.orderDetail.configPricing') }}</h4>
                  <div class="snapshot-line-list">
                    <div v-for="item in pricingSnapshotView" :key="`price-${item.label}`" class="snapshot-line-item">
                      <span class="snapshot-line-label">{{ item.label }}</span>
                      <strong class="snapshot-line-value">{{ item.value }}</strong>
                    </div>
                  </div>
                </section>
              </div>
              <div v-if="!configSnapshotView.length && !pricingSnapshotView.length" class="order-detail-empty">
                {{ $t('client.orderDetail.noConfigSnapshot') }}
              </div>
            </t-tab-panel>

            <t-tab-panel value="related" :label="$t('client.orderDetail.relatedTab')">
              <!-- 关联账单 -->
              <section v-if="detail.invoice" class="related-section">
                <h4>{{ $t('client.orderDetail.relatedInvoice') }}</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>{{ $t('client.orderDetail.invoiceNo') }}</span>
                    <t-button
                      variant="text"
                      theme="primary"
                      size="small"
                      @click="router.push(`/client/invoices/${detail.invoice.id}`)"
                    >
                      {{ detail.invoice.invoice_no || '--' }}
                    </t-button>
                  </div>
                  <div class="detail-kv-item">
                    <span>{{ $t('client.orderDetail.invoiceStatus') }}</span>
                    <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(detail.invoice.status)" />
                  </div>
                </div>
              </section>
              <div v-else class="related-empty">{{ $t('client.orderDetail.noRelatedInvoice') }}</div>

              <!-- 关联服务 -->
              <section v-if="detail.service" class="related-section">
                <h4>{{ $t('client.orderDetail.relatedService') }}</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>{{ $t('client.orderDetail.instanceId') }}</span>
                    <strong>{{ detail.service.instance_id || '--' }}</strong>
                  </div>
                  <div class="detail-kv-item">
                    <span>{{ $t('client.orderDetail.hostname') }}</span>
                    <strong>{{ detail.service.hostname || '--' }}</strong>
                  </div>
                </div>
              </section>
              <div v-else class="related-empty">{{ $t('client.orderDetail.noRelatedService') }}</div>

              <!-- 优惠券 -->
              <section v-if="detail.coupon" class="related-section">
                <h4>{{ $t('client.orderDetail.couponUsage') }}</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>{{ $t('client.orderDetail.couponCode') }}</span>
                    <strong>{{ detail.coupon.code || '--' }}</strong>
                  </div>
                  <div class="detail-kv-item">
                    <span>{{ $t('client.orderDetail.couponName') }}</span>
                    <strong>{{ detail.coupon.name || '--' }}</strong>
                  </div>
                </div>
              </section>
            </t-tab-panel>
          </t-tabs>
        </t-card>
      </template>

      <div v-else-if="!loading" class="detail-empty">
        <t-button variant="outline" @click="router.push('/client/orders')">{{
          $t('client.orderDetail.backToOrders')
        }}</t-button>
      </div>
    </t-loading>
  </section>
</template>
<script setup lang="ts">
import { billingCycleLabel as billingCycleLabelOf } from '@shared/billingCycle';
import StatusTag from '@shared/user-v3/components/StatusTag.vue';
import { INVOICE_STATUS_MAP, ORDER_STATUS_MAP } from '@turaidc/shared/statusConfig';
import { RefreshIcon } from 'tdesign-icons-vue-next';
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { formatMoney, orderProductDisplay, useOrderDetail } from '@/domains/finance/useOrders';
import { configValueLabelMap, flattenSnapshot } from '@/domains/finance/useRecords';

const route = useRoute();
const router = useRouter();
const activeTab = ref('basic');
const { loading, canceling, detail, loadDetail, invalidateDetail, cancelOrder } = useOrderDetail();

const showConfigTab = computed(() => {
  const type = String(detail.value?.type || '').toLowerCase();
  return ['new', 'normal'].includes(type);
});
const configSnapshotView = computed(() => {
  const row = detail.value;
  if (!row) return [];
  return flattenSnapshot(row.config_snapshot as Record<string, unknown> | undefined, configValueLabelMap(row));
});
const pricingSnapshotView = computed(() =>
  flattenSnapshot(detail.value?.config_pricing_snapshot as Record<string, unknown> | undefined),
);

const payableInvoiceId = computed(() => {
  const id = Number(detail.value?.invoice?.id || detail.value?.invoice_id || 0);
  return Number.isFinite(id) && id > 0 ? id : 0;
});
const canPayDetail = computed(() => Number(detail.value?.status) === 0 && payableInvoiceId.value > 0);

function goPayDetail() {
  if (payableInvoiceId.value > 0) {
    router.push(`/client/invoices/${payableInvoiceId.value}/pay`);
  }
}

const orderId = computed(() => Number(route.params.id || 0));

function billingCycleLabel(value?: string) {
  if (!value) return '--';
  return billingCycleLabelOf(value, value);
}

watch(
  orderId,
  (id) => {
    if (id > 0) {
      void loadDetail(id);
    } else {
      invalidateDetail(); // 失效进行中的旧请求并将详情/加载态复位
    }
  },
  { immediate: true },
);
</script>
<style scoped lang="less">
.order-breadcrumb {
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

.order-detail-tabs-card {
  min-height: 18.75rem;
}

.order-pricing-block {
  display: grid;
  margin-top: var(--td-comp-margin-l);
  padding-top: var(--td-comp-paddingTB-m);
}

.order-section {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  min-width: 0;
}

.order-section-title {
  position: relative;
  margin: 0;
  padding-left: 0.625rem;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
  font-weight: 600;

  &::before {
    position: absolute;
    top: 0.25rem;
    bottom: 0.25rem;
    left: 0;
    width: 0.1875rem;
    background: var(--td-brand-color);
    border-radius: 3px;
    content: '';
  }
}

.snapshot-line-list {
  display: flex;
  flex-direction: column;
  gap: 0.0625rem;
  overflow: hidden;
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
}

.snapshot-line-item {
  display: grid;
  grid-template-columns: minmax(8rem, 11rem) minmax(0, 1fr);
  gap: var(--td-comp-margin-s);
  align-items: start;
  min-width: 0;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-container);
}

.snapshot-line-label {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.snapshot-line-value {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  font-weight: 600;
  overflow-wrap: anywhere;
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

  &--wide {
    grid-column: 1 / -1;
  }
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

.order-detail-empty,
.detail-empty {
  padding: var(--td-comp-paddingTB-xl) 0;
  text-align: center;
  color: var(--td-text-color-placeholder);
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

  .snapshot-line-item {
    grid-template-columns: 1fr;
    gap: var(--td-comp-margin-xxs);
  }
}
</style>
