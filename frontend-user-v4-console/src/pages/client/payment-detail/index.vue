<template>
  <section class="payment-detail-page">
    <!-- 面包屑导航 -->
    <t-breadcrumb class="payment-breadcrumb">
      <t-breadcrumb-item @click="router.push('/client/dashboard')">{{ $t('paymentDetail.title') }}</t-breadcrumb-item>
      <t-breadcrumb-item @click="router.push('/client/payments')">{{ $t('paymentDetail.records') }}</t-breadcrumb-item>
      <t-breadcrumb-item>{{ $t('paymentDetail.recordDetail') }}</t-breadcrumb-item>
    </t-breadcrumb>

    <!-- 操作栏 -->
    <div class="detail-toolbar">
      <t-button variant="outline" @click="router.push('/client/payments')">{{ $t('paymentDetail.back') }}</t-button>
      <div class="detail-toolbar__actions">
        <t-button variant="outline" :loading="loading" @click="loadPayment()">
          <template #icon><refresh-icon /></template>
          {{ $t('paymentDetail.refresh') }}
        </t-button>
      </div>
    </div>

    <t-loading :loading="loading" :text="$t('paymentDetail.loading')">
      <template v-if="detail">
        <!-- 详情卡片 -->
        <t-card :bordered="false" class="payment-detail-card">
          <template #title>{{ $t('paymentDetail.paymentInfo') }}</template>
          <div class="detail-kv-grid">
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.merchantOrderNo') }}</span>
              <strong>{{ detail.payment_no || '--' }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.thirdPartyOrderNo') }}</span>
              <strong>{{ detail.trade_no || '--' }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.paymentAmount') }}</span>
              <strong>¥{{ formatMoney(detail.amount) }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.gateway') }}</span>
              <strong>{{ detail.gateway_label || detail.gateway_key || detail.gateway || '--' }}</strong>
            </div>
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.status') }}</span>
              <status-tag :status-map="PAYMENT_STATUS_MAP" :status="Number(detail.status)" />
            </div>
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.createdAt') }}</span>
              <strong>{{ detail.created_at || '--' }}</strong>
            </div>
            <div v-if="detail.paid_at" class="detail-kv-item">
              <span>{{ $t('paymentDetail.paidAt') }}</span>
              <strong>{{ detail.paid_at }}</strong>
            </div>
          </div>
        </t-card>

        <!-- 关联账单 -->
        <t-card v-if="detail.invoice" :bordered="false" class="payment-related-card">
          <template #title>{{ $t('paymentDetail.relatedInvoice') }}</template>
          <div class="detail-kv-grid">
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.invoiceNo') }}</span>
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
              <span>{{ $t('paymentDetail.invoiceStatus') }}</span>
              <status-tag :status-map="INVOICE_STATUS_MAP" :status="Number(detail.invoice.status)" />
            </div>
            <div class="detail-kv-item">
              <span>{{ $t('paymentDetail.invoiceAmount') }}</span>
              <strong>¥{{ formatMoney(detail.invoice.amount) }}</strong>
            </div>
            <div v-if="detail.invoice.paid_amount" class="detail-kv-item">
              <span>{{ $t('paymentDetail.paidAmount') }}</span>
              <strong>¥{{ formatMoney(detail.invoice.paid_amount) }}</strong>
            </div>
          </div>
        </t-card>
      </template>

      <div v-else-if="!loading" class="detail-empty">
        <t-button variant="outline" @click="router.push('/client/payments')">{{
          $t('paymentDetail.backToPayments')
        }}</t-button>
      </div>
    </t-loading>
  </section>
</template>
<script setup lang="ts">
import { INVOICE_STATUS_MAP, PAYMENT_STATUS_MAP } from '@shared/statusConfig';
import StatusTag from '@shared/user-v3/components/StatusTag.vue';
import { RefreshIcon } from 'tdesign-icons-vue-next';
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { formatMoney } from '@/domains/finance/useRecords';
import type { PaymentRecord } from '@/types/client';

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const detail = ref<PaymentRecord | null>(null);

const paymentId = computed(() => Number(route.params.id || 0));

// 并发守卫：快速切换支付详情时丢弃晚返回的旧请求结果。
let requestSeq = 0;

async function loadPayment(id = paymentId.value) {
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
    const res = await clientApi.paymentDetail(validId);
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
  paymentId,
  (id) => {
    requestSeq += 1; // 失效进行中的旧请求，避免其晚返回覆盖当前路由
    detail.value = null;
    if (id > 0) {
      void loadPayment(id);
    } else {
      loading.value = false;
    }
  },
  { immediate: true },
);
</script>
<style scoped lang="less">
.payment-breadcrumb {
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

.payment-detail-card,
.payment-related-card {
  margin-bottom: var(--td-comp-margin-m);
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
