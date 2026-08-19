<template>
  <section class="order-detail-page">
    <!-- 面包屑导航 -->
    <t-breadcrumb class="order-breadcrumb">
      <t-breadcrumb-item @click="router.push('/client/dashboard')">财务中心</t-breadcrumb-item>
      <t-breadcrumb-item @click="router.push('/client/orders')">订单记录</t-breadcrumb-item>
      <t-breadcrumb-item>订单详情</t-breadcrumb-item>
    </t-breadcrumb>

    <!-- 操作栏 -->
    <div class="detail-toolbar">
      <t-button variant="outline" @click="router.push('/client/orders')">返回列表</t-button>
      <div class="detail-toolbar__actions">
        <t-button variant="outline" :loading="loading" @click="loadDetail(orderId)">
          <template #icon><refresh-icon /></template>
          刷新
        </t-button>
        <t-button v-if="canPayDetail" theme="primary" @click="goPayDetail"> 去支付 </t-button>
        <t-button
          v-if="detail && Number(detail.status) === 0"
          theme="danger"
          variant="outline"
          :loading="canceling"
          @click="cancelOrder()"
        >
          取消订单
        </t-button>
      </div>
    </div>

    <t-loading :loading="loading" text="正在加载订单详情">
      <template v-if="detail">
        <!-- 标签页 -->
        <t-card :bordered="false" class="order-detail-tabs-card">
          <t-tabs v-model="activeTab">
            <t-tab-panel value="basic" label="基础信息">
              <div class="detail-kv-grid">
                <div class="detail-kv-item">
                  <span>订单号</span>
                  <strong>{{ detail.order_no || '--' }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>订单类型</span>
                  <strong>{{ detail.type_label || '--' }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>订单状态</span>
                  <status-tag :status-map="ORDER_STATUS_MAP" :status="Number(detail.status)" />
                </div>
                <div class="detail-kv-item">
                  <span>订单金额</span>
                  <strong>¥{{ formatMoney(detail.amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>优惠金额</span>
                  <strong>¥{{ formatMoney(detail.discount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>已付金额</span>
                  <strong>¥{{ formatMoney(detail.paid_amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>开通商品</span>
                  <strong>{{ orderProductDisplay(detail) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>计费周期</span>
                  <strong>{{ billingCycleLabel(detail.billing_cycle) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>数量</span>
                  <strong>{{ detail.quantity ?? 1 }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>创建时间</span>
                  <strong>{{ detail.created_at || '--' }}</strong>
                </div>
                <div v-if="detail.paid_at" class="detail-kv-item">
                  <span>支付时间</span>
                  <strong>{{ detail.paid_at }}</strong>
                </div>
                <div v-if="detail.coupon_code" class="detail-kv-item">
                  <span>优惠券</span>
                  <strong>{{ detail.coupon?.name || detail.coupon_code }}</strong>
                </div>
                <div v-if="detail.remark" class="detail-kv-item detail-kv-item--wide">
                  <span>备注</span>
                  <strong>{{ detail.remark }}</strong>
                </div>
              </div>
            </t-tab-panel>

            <t-tab-panel v-if="showConfigTab" value="config" label="产品配置">
              <div v-if="configSnapshotView.length" class="order-pricing-block">
                <section class="order-section">
                  <h4 class="order-section-title">配置快照</h4>
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
                  <h4 class="order-section-title">配置定价</h4>
                  <div class="snapshot-line-list">
                    <div v-for="item in pricingSnapshotView" :key="`price-${item.label}`" class="snapshot-line-item">
                      <span class="snapshot-line-label">{{ item.label }}</span>
                      <strong class="snapshot-line-value">{{ item.value }}</strong>
                    </div>
                  </div>
                </section>
              </div>
              <div v-if="!configSnapshotView.length && !pricingSnapshotView.length" class="order-detail-empty">
                暂无配置快照
              </div>
            </t-tab-panel>

            <t-tab-panel value="related" label="关联信息">
              <!-- 关联账单 -->
              <section v-if="detail.invoice" class="related-section">
                <h4>关联账单</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>账单号</span>
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
                    <span>账单状态</span>
                    <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(detail.invoice.status)" />
                  </div>
                </div>
              </section>
              <div v-else class="related-empty">暂无关联账单</div>

              <!-- 关联服务 -->
              <section v-if="detail.service" class="related-section">
                <h4>关联服务</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>实例ID</span>
                    <strong>{{ detail.service.instance_id || '--' }}</strong>
                  </div>
                  <div class="detail-kv-item">
                    <span>主机名</span>
                    <strong>{{ detail.service.hostname || '--' }}</strong>
                  </div>
                </div>
              </section>
              <div v-else class="related-empty">暂无关联服务</div>

              <!-- 优惠券 -->
              <section v-if="detail.coupon" class="related-section">
                <h4>使用优惠券</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>优惠券码</span>
                    <strong>{{ detail.coupon.code || '--' }}</strong>
                  </div>
                  <div class="detail-kv-item">
                    <span>优惠券名称</span>
                    <strong>{{ detail.coupon.name || '--' }}</strong>
                  </div>
                </div>
              </section>
            </t-tab-panel>
          </t-tabs>
        </t-card>
      </template>

      <div v-else-if="!loading" class="detail-empty">
        <t-button variant="outline" @click="router.push('/client/orders')">返回订单列表</t-button>
      </div>
    </t-loading>
  </section>
</template>
<script setup lang="ts">
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

const BILLING_CYCLE_MAP: Record<string, string> = {
  monthly: '月付',
  quarterly: '季付',
  semiannually: '半年付',
  annually: '年付',
  biennially: '两年付',
  triennially: '三年付',
  one_time: '一次性',
  free: '免费',
};

function billingCycleLabel(value?: string) {
  if (!value) return '--';
  return BILLING_CYCLE_MAP[value] || value;
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
    border-radius: 0.125rem;
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
