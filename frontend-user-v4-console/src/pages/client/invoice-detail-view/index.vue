<template>
  <section class="invoice-detail-page">
    <!-- 面包屑导航 -->
    <t-breadcrumb class="invoice-breadcrumb">
      <t-breadcrumb-item @click="router.push('/client/dashboard')">财务中心</t-breadcrumb-item>
      <t-breadcrumb-item @click="router.push('/client/invoices')">账单记录</t-breadcrumb-item>
      <t-breadcrumb-item>账单详情</t-breadcrumb-item>
    </t-breadcrumb>

    <!-- 操作栏 -->
    <div class="detail-toolbar">
      <t-button variant="outline" @click="router.push('/client/invoices')">返回列表</t-button>
      <div class="detail-toolbar__actions">
        <t-button variant="outline" :loading="loading" @click="loadInvoice()">
          <template #icon><refresh-icon /></template>
          刷新
        </t-button>
        <t-button
          v-if="detail && isPayable(detail)"
          theme="primary"
          @click="router.push(`/client/invoices/${detail.id}/pay`)"
        >
          去支付
        </t-button>
      </div>
    </div>

    <t-loading :loading="loading" text="正在加载账单详情">
      <template v-if="detail">
        <!-- 标签页 -->
        <t-card :bordered="false" class="invoice-detail-tabs-card">
          <t-tabs v-model="activeTab">
            <t-tab-panel value="basic" label="基础信息">
              <div class="detail-kv-grid">
                <div class="detail-kv-item">
                  <span>账单号</span>
                  <strong>{{ detail.invoice_no || '--' }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>账单类型</span>
                  <strong>{{ detail.type_label || typeLabel(detail.type) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>账单状态</span>
                  <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(detail.status)" />
                </div>
                <div class="detail-kv-item">
                  <span>账单金额</span>
                  <strong>¥{{ formatMoney(detail.amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>已付金额</span>
                  <strong>¥{{ formatMoney(detail.paid_amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>待付金额</span>
                  <strong>¥{{ formatMoney(detail.payable_amount) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>计费周期</span>
                  <strong>{{ billingCycleLabel(detail.billing_cycle) }}</strong>
                </div>
                <div class="detail-kv-item">
                  <span>创建时间</span>
                  <strong>{{ detail.created_at || '--' }}</strong>
                </div>
                <div v-if="detail.due_date" class="detail-kv-item">
                  <span>截止时间</span>
                  <strong>{{ detail.due_date }}</strong>
                </div>
                <div v-if="detail.paid_at" class="detail-kv-item">
                  <span>支付时间</span>
                  <strong>{{ detail.paid_at }}</strong>
                </div>
              </div>
            </t-tab-panel>

            <t-tab-panel value="related" label="关联订单">
              <!-- 关联订单 -->
              <section v-if="detail.order" class="related-section">
                <h4>关联订单</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>订单号</span>
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
                    <span>订单类型</span>
                    <strong>{{ detail.order.type_label || '--' }}</strong>
                  </div>
                  <div class="detail-kv-item">
                    <span>订单状态</span>
                    <status-tag :status-map="ORDER_STATUS_MAP" :status="Number(detail.order.status)" />
                  </div>
                </div>
              </section>
              <div v-else class="related-empty">暂无关联订单</div>

              <!-- 优惠券 -->
              <section v-if="detail.coupon_code" class="related-section">
                <h4>使用优惠券</h4>
                <div class="detail-kv-grid">
                  <div class="detail-kv-item">
                    <span>优惠券码</span>
                    <strong>{{ detail.coupon_code }}</strong>
                  </div>
                </div>
              </section>
            </t-tab-panel>

            <t-tab-panel value="payments" label="关联支付">
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
              <div v-else class="related-empty">暂无支付记录</div>
            </t-tab-panel>
          </t-tabs>
        </t-card>
      </template>

      <div v-else-if="!loading" class="detail-empty">
        <t-button variant="outline" @click="router.push('/client/invoices')">返回账单列表</t-button>
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
import type { InvoiceRecord } from '@/types/client';

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
    new: '新购',
    normal: '新购',
    renew: '续费',
    upgrade: '升降级',
    recharge: '充值',
    deduction: '扣款',
    referral_credit: '推荐奖励',
    manual: '手工账单',
  };
  return type ? map[type] || type : '--';
}

const paymentRecords = computed(() => {
  const payments = detail.value?.payments;
  if (!Array.isArray(payments)) return [];
  return payments;
});

function paymentGatewayDisplay(row: Record<string, unknown>) {
  return String(row.gateway_label || row.gateway_key || row.gateway || '--');
}

const paymentColumns: PrimaryTableCol[] = [
  { colKey: 'payment_no', title: '商家订单号', minWidth: '10rem' },
  { colKey: 'trade_no', title: '第三方订单号', minWidth: '12rem' },
  { colKey: 'gateway', title: '渠道', width: '8rem' },
  { colKey: 'amount', title: '金额', width: '8rem' },
  { colKey: 'status', title: '状态', width: '8rem' },
  { colKey: 'paid_at', title: '支付时间', minWidth: '12rem' },
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
