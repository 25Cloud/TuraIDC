<template>
  <section class="invoice-pay-page">
    <div class="pay-toolbar">
      <t-button variant="outline" @click="router.push('/client/invoices')">返回账单</t-button>
      <div class="pay-toolbar__actions">
        <t-button variant="outline" :loading="loading" @click="loadDetail">
          <template #icon><refresh-icon /></template>
          刷新详情
        </t-button>
        <t-button v-if="showPayActions" theme="danger" variant="outline" :loading="canceling" @click="handleCancel">
          取消账单
        </t-button>
      </div>
    </div>

    <t-loading :loading="loading" text="正在加载账单详情">
      <template v-if="detail">
        <section class="pay-shell">
          <main class="pay-main-column">
            <t-card v-if="isRenewInvoiceView" class="renew-info-card" :bordered="false">
              <template #title>续费信息</template>

              <div class="renew-service-panel">
                <div>
                  <span>续费服务</span>
                  <strong>{{ renewServiceName(detail) }}</strong>
                  <p>支付完成后系统会自动处理续费，到期时间以服务控制台更新为准。</p>
                </div>
                <t-button
                  v-if="renewServiceId(detail)"
                  variant="outline"
                  theme="primary"
                  @click="router.push(`/client/services/${renewServiceId(detail)}`)"
                >
                  查看服务
                </t-button>
              </div>

              <div class="renew-kv-grid">
                <div v-for="item in renewInfoItemsView" :key="item.label" class="renew-kv-item">
                  <span>{{ item.label }}</span>
                  <strong>{{ item.value }}</strong>
                </div>
              </div>

              <section class="renew-flow-section">
                <h4>支付后处理</h4>
                <div class="renew-flow-list">
                  <div v-for="step in renewFlowItems" :key="step.label" class="renew-flow-item">
                    <span class="renew-flow-item__mark">{{ step.index }}</span>
                    <div>
                      <strong>{{ step.label }}</strong>
                      <p>{{ step.description }}</p>
                    </div>
                  </div>
                </div>
              </section>
            </t-card>

            <t-card v-if="isNewPurchaseInvoice(detail)" class="order-info-card" :bordered="false">
              <template #title>账单信息</template>
              <div class="order-summary-grid">
                <div class="order-kv-item">
                  <span>账单号</span>
                  <strong>{{ detail.invoice_no || '--' }}</strong>
                </div>
                <div class="order-kv-item">
                  <span>账单类型</span>
                  <strong>{{ detail.type_label || (detail.type === 'renew' ? '续费' : '新购') }}</strong>
                </div>
                <div class="order-kv-item">
                  <span>账单金额</span>
                  <strong>¥{{ formatMoney(detail.amount) }}</strong>
                </div>
                <div class="order-kv-item">
                  <span>计费周期</span>
                  <strong>{{ orderBillingCycle(detail) }}</strong>
                </div>
                <div class="order-kv-item order-summary-grid__wide">
                  <span>产品链路</span>
                  <strong>{{ productPathView }}</strong>
                </div>
              </div>

              <div v-if="pricingItemsView.length" class="order-pricing-block">
                <section class="order-section">
                  <h4>配置定价</h4>
                  <div class="snapshot-line-list">
                    <div v-for="item in pricingItemsView" :key="`pricing-${item.label}`" class="snapshot-line-item">
                      <span>{{ item.label }}</span>
                      <strong>{{ item.value }}</strong>
                    </div>
                  </div>
                </section>
              </div>
            </t-card>

            <t-card v-if="detail.payment_summary" class="payment-record-card" :bordered="false">
              <template #title>关联支付记录</template>
              <div class="payment-record-grid">
                <div>
                  <span>支付方式</span>
                  <strong>{{
                    detail.payment_summary.gateway_label ||
                    detail.payment_summary.gateway_key ||
                    detail.payment_summary.gateway ||
                    '--'
                  }}</strong>
                </div>
                <div>
                  <span>支付状态</span>
                  <strong>{{ resolvePaymentStatusLabel(detail.payment_summary.status) }}</strong>
                </div>
                <div>
                  <span>商家订单号</span>
                  <strong>{{ detail.payment_summary.payment_no || '--' }}</strong>
                </div>
                <div>
                  <span>第三方订单号</span>
                  <strong>{{ detail.payment_summary.trade_no || '--' }}</strong>
                </div>
                <div>
                  <span>支付金额</span>
                  <strong>¥{{ formatMoney(detail.payment_summary.amount) }}</strong>
                </div>
                <div v-if="hasAppliedBalanceDeduction">
                  <span>余额抵扣</span>
                  <strong>¥{{ appliedDeductionAmountText }}</strong>
                </div>
              </div>
            </t-card>
          </main>

          <aside class="invoice-summary-column">
            <t-card class="invoice-summary-card" :bordered="false">
              <template #title>账单摘要</template>
              <div class="summary-total">
                <span>本次应付</span>
                <strong>¥{{ formatMoney(detail.payable_amount) }}</strong>
              </div>
              <div v-if="sessionExpiresAt" class="summary-session">
                <t-tag variant="outline" theme="primary">会话截止 {{ formattedSessionExpiresAt }}</t-tag>
                <span class="summary-session__countdown" :class="{ 'is-expired': sessionExpired }">
                  {{ sessionCountdownText }}
                </span>
              </div>
              <div class="summary-list">
                <div>
                  <span>账单号</span>
                  <strong>{{ resolveInvoiceNo(detail) }}</strong>
                </div>
                <div>
                  <span>账单类型</span>
                  <strong>{{ detail.type_label || '--' }}</strong>
                </div>
                <div class="summary-status-row">
                  <span>状态</span>
                  <status-tag
                    class="summary-status-tag"
                    :status-map="INVOICE_STATUS_MAP"
                    :status="Number(detail.status)"
                  />
                </div>
                <div>
                  <span>账单金额</span>
                  <strong>¥{{ formatMoney(detail.amount) }}</strong>
                </div>
                <div>
                  <span>优惠金额</span>
                  <strong>¥{{ formatMoney(detail.discount) }}</strong>
                </div>
                <div>
                  <span>已支付</span>
                  <strong>¥{{ formatMoney(detail.paid_amount) }}</strong>
                </div>
                <div>
                  <span>创建时间</span>
                  <strong>{{ detail.created_at || '--' }}</strong>
                </div>
                <div>
                  <span>截止时间</span>
                  <strong>{{ detail.due_date || '--' }}</strong>
                </div>
              </div>
            </t-card>

            <t-card v-if="showPayActions && hasPayMethods" class="pay-work-card" :bordered="false">
              <div class="pay-work-head">
                <div>
                  <h2>选择支付方式</h2>
                  <p>确认渠道后继续完成当前账单支付</p>
                </div>
              </div>

              <div class="pay-method-list">
                <button
                  v-for="method in payMethods"
                  :key="paymentOptionKey(method)"
                  type="button"
                  class="pay-method-card"
                  :class="{ 'is-active': selectedPayMethod === paymentOptionKey(method) }"
                  :disabled="!canPay || paying"
                  :aria-pressed="selectedPayMethod === paymentOptionKey(method)"
                  :aria-label="method.name"
                  :title="method.name"
                  @click="selectPayMethod(paymentOptionKey(method))"
                >
                  <span class="pay-method-card__icon">
                    <component :is="paymentMethodIcon(method)" />
                  </span>
                  <span class="pay-method-card__text">
                    <strong>{{ method.name || method.label || '支付' }}</strong>
                    <small>{{ method.label || method.key || '扫码支付' }}</small>
                  </span>
                  <span class="pay-method-card__check">
                    <check-circle-icon v-if="selectedPayMethod === paymentOptionKey(method)" />
                    <span v-else />
                  </span>
                </button>
              </div>

              <div v-if="showBalanceDeductionOption" class="deduction-panel">
                <div class="deduction-panel__head">
                  <strong>余额抵扣</strong>
                  <span>最多可抵扣 ¥{{ autoDeductionAmountText }}</span>
                </div>
                <t-checkbox v-model="allowBalanceDeduction" @change="handleDeductionToggle">
                  支付宝支付时使用余额抵扣
                </t-checkbox>
                <div v-if="allowBalanceDeduction" class="deduction-summary">
                  <span>当前余额 {{ balanceText }}</span>
                  <span>预计抵扣 ¥{{ autoDeductionAmountText }}</span>
                  <span>支付宝待付 ¥{{ estimatedAlipayAmountText }}</span>
                </div>
              </div>

              <div class="pay-actions">
                <t-button
                  v-if="selectedPayMethod === 'balance'"
                  theme="primary"
                  size="large"
                  :loading="paying"
                  :disabled="!canPay"
                  @click="handlePayByBalance"
                >
                  确认余额支付
                </t-button>
                <t-button
                  v-else-if="selectedPayMethod && selectedPayMethod !== 'free'"
                  theme="primary"
                  size="large"
                  :loading="paying"
                  :disabled="!canPay"
                  @click="handlePayByAlipay"
                >
                  {{
                    allowBalanceDeduction && balanceAmount >= payableAmount
                      ? '使用余额完成支付'
                      : `生成${selectedPayMethodName}二维码`
                  }}
                </t-button>
                <t-button v-else-if="selectedPayMethod === 'free'" theme="primary" size="large" disabled>
                  零元账单无需操作
                </t-button>
                <t-button variant="outline" size="large" :disabled="paying || loading" @click="loadDetail"
                  >刷新</t-button
                >
              </div>

              <t-alert v-if="payTip" theme="info" :message="payTip" />
            </t-card>
          </aside>
        </section>
      </template>

      <t-empty v-else description="未找到账单详情" />
    </t-loading>

    <t-dialog
      v-model:visible="alipayDialogVisible"
      header="支付宝支付"
      width="min(24rem, calc(100vw - 2rem))"
      destroy-on-close
    >
      <div class="dialog-qrcode">
        <qrcode-vue v-if="alipayQrCode" :value="alipayQrCode" :size="180" level="H" render-as="svg" />
      </div>
      <div class="dialog-meta">
        <p>金额 ¥{{ alipayPayableAmount }}</p>
        <p>商家订单号 {{ alipayPaymentNo || '--' }}</p>
      </div>
      <template #footer>
        <t-button theme="primary" :loading="polling" :disabled="!alipayPollingReady" @click="pollAlipayStatus(false)">
          我已完成支付，刷新状态
        </t-button>
      </template>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import {
  CheckCircleIcon,
  CreditcardIcon,
  LogoAlipayFilledIcon,
  LogoWechatpayFilledIcon,
  RefreshIcon,
  WalletIcon,
} from 'tdesign-icons-vue-next';
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const QrcodeVue = defineAsyncComponent(() => import('qrcode.vue'));

import { INVOICE_STATUS_MAP } from '@shared/statusConfig';
import StatusTag from '@shared/user-v3/components/StatusTag.vue';

import { formatMoney, resolveInvoiceNo, useInvoiceDetail } from '@/domains/finance/useInvoices';
import { flattenSnapshot, formatBillingCycle, resolvePaymentStatusLabel } from '@/domains/finance/useRecords';
import type { InvoicePaymentMethod, InvoiceRecord } from '@/types/client';

const {
  router,
  loading,
  canceling,
  paying,
  polling,
  detail,
  selectedPayMethod,
  allowBalanceDeduction,
  alipayDialogVisible,
  alipayQrCode,
  alipayPaymentNo,
  appliedDeductionAmountText,
  hasAppliedBalanceDeduction,
  alipayPayableAmount,
  payMethods,
  hasPayMethods,
  canPay,
  alipayPollingReady,
  balanceAmount,
  payableAmount,
  balanceText,
  autoDeductionAmountText,
  estimatedAlipayAmountText,
  selectedPayMethodName,
  showBalanceDeductionOption,
  showPayActions,
  payTip,
  loadDetail,
  handleCancel,
  handlePayByBalance,
  handlePayByAlipay,
  handleDeductionToggle,
  pollAlipayStatus,
  selectPayMethod,
} = useInvoiceDetail();

const now = ref(Date.now());
let sessionCountdownTimer: number | null = null;

const sessionExpiresAt = computed(() => String(detail.value?.payment_security?.expires_at || '').trim());
const sessionExpiresTime = computed(() => parseSessionExpiresTime(sessionExpiresAt.value));

// 记忆化模板函数：避免每秒倒计时 tick（now 变更）触发整模板重算这些纯函数
const isRenewInvoiceView = computed(() => isRenewInvoice(detail.value));
const renewInfoItemsView = computed(() => renewInfoItems(detail.value));
const pricingItemsView = computed(() => pricingItems(detail.value));
const productPathView = computed(() => productPath(detail.value));
const formattedSessionExpiresAt = computed(() =>
  formatSessionExpiresAt(sessionExpiresTime.value, sessionExpiresAt.value),
);
const sessionRemainingMs = computed(() => (sessionExpiresTime.value ? sessionExpiresTime.value - now.value : 0));
const sessionExpired = computed(() => Boolean(sessionExpiresTime.value) && sessionRemainingMs.value <= 0);
const sessionCountdownText = computed(() => {
  if (!sessionExpiresTime.value) return '剩余 --';
  if (sessionRemainingMs.value <= 0) return '已过期';
  return `剩余 ${formatCountdown(sessionRemainingMs.value)}`;
});
const renewFlowItems = [
  {
    index: '1',
    label: '完成支付',
    description: '余额或支付宝支付成功后，账单状态会自动更新。',
  },
  {
    index: '2',
    label: '执行续费',
    description: '系统按当前续费周期处理服务有效期。',
  },
  {
    index: '3',
    label: '同步服务',
    description: '处理完成后可在服务控制台查看新的到期时间。',
  },
];

function paymentOptionKey(method: InvoicePaymentMethod) {
  return String(method.option_key || method.key || '').trim();
}

function paymentMethodIcon(method: InvoicePaymentMethod) {
  const key = String(method.key || '').trim();
  const paymentType = String(method.payment_type || '').trim();
  if (key === 'balance') return WalletIcon;
  if (key === 'alipay' || paymentType === 'alipay') return LogoAlipayFilledIcon;
  if (key === 'wechat' || paymentType === 'wxpay') return LogoWechatpayFilledIcon;
  return CreditcardIcon;
}

function serviceRecord(row: InvoiceRecord | null | undefined) {
  return row?.service || null;
}

function isNewPurchaseInvoice(row: InvoiceRecord | null | undefined) {
  const type = String(row?.type || '').toLowerCase();
  return ['new', 'normal'].includes(type);
}

function isRenewInvoice(row: InvoiceRecord | null | undefined) {
  const type = String(row?.type || '').toLowerCase();
  return type === 'renew';
}

function productPath(row: InvoiceRecord | null | undefined) {
  return row?.combined_display_name || row?.product_display_name || row?.product_spec_display || '--';
}

function orderBillingCycle(row: InvoiceRecord | null | undefined) {
  return formatBillingCycle(row?.billing_cycle);
}

function renewServiceId(row: InvoiceRecord | null | undefined) {
  return Number(serviceRecord(row)?.id || row?.service_id || 0);
}

function renewServiceName(row: InvoiceRecord | null | undefined) {
  const service = serviceRecord(row);
  const serviceId = renewServiceId(row);
  const productName = productPath(row);
  return service?.name || (productName !== '--' ? productName : '') || (serviceId > 0 ? `服务 #${serviceId}` : '--');
}

function currentExpiresAt(row: InvoiceRecord | null | undefined) {
  const service = serviceRecord(row);
  return service?.expires_at || '--';
}

function renewedExpiresAt(row: InvoiceRecord | null | undefined) {
  const current = currentExpiresAt(row);
  const currentTime = current === '--' ? 0 : parseSessionExpiresTime(String(current));
  const baseTime = currentTime > Date.now() ? currentTime : Date.now();
  const date = new Date(baseTime);
  const cycle = String(row?.billing_cycle || '')
    .trim()
    .toLowerCase();

  if (cycle === 'monthly') date.setMonth(date.getMonth() + 1);
  else if (cycle === 'quarterly') date.setMonth(date.getMonth() + 3);
  else if (cycle === 'semiannually') date.setMonth(date.getMonth() + 6);
  else if (cycle === 'annually' || cycle === 'yearly') date.setFullYear(date.getFullYear() + 1);
  else if (cycle === 'biennially') date.setFullYear(date.getFullYear() + 2);
  else if (cycle === 'triennially') date.setFullYear(date.getFullYear() + 3);
  else return currentTime ? formatSessionExpiresAt(baseTime, '--') : '--';

  return formatSessionExpiresAt(date.getTime(), '--');
}

function renewInfoItems(row: InvoiceRecord | null | undefined) {
  const serviceId = renewServiceId(row);
  return [
    { label: '服务 ID', value: serviceId > 0 ? `#${serviceId}` : '--' },
    { label: '当前到期', value: currentExpiresAt(row) },
    { label: '续费周期', value: orderBillingCycle(row) },
    { label: '续费金额', value: `¥${formatMoney(row?.amount)}` },
    { label: '续费后到期', value: renewedExpiresAt(row) },
    { label: '本次应付', value: `¥${formatMoney(row?.payable_amount)}` },
  ];
}

function pricingItems(row: InvoiceRecord | null | undefined) {
  return flattenSnapshot(row?.config_pricing_snapshot);
}

function parseSessionExpiresTime(value: string) {
  if (!value) return 0;
  const direct = Date.parse(value);
  if (Number.isFinite(direct)) return direct;
  const normalized = value.replace(/-/g, '/').replace('T', ' ');
  const fallback = Date.parse(normalized);
  return Number.isFinite(fallback) ? fallback : 0;
}

function formatSessionExpiresAt(timestamp: number, fallback: string) {
  if (!timestamp) return fallback || '--';
  const date = new Date(timestamp);
  const pad = (num: number) => String(num).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(
    date.getMinutes(),
  )}:${pad(date.getSeconds())}`;
}

function formatCountdown(value: number) {
  const totalSeconds = Math.max(0, Math.floor(value / 1000));
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const timeText = [hours, minutes, seconds].map((item) => String(item).padStart(2, '0')).join(':');
  return days > 0 ? `${days}天 ${timeText}` : timeText;
}

function startSessionCountdown() {
  if (sessionCountdownTimer !== null) return;
  sessionCountdownTimer = window.setInterval(() => {
    now.value = Date.now();
  }, 1000);
}

function stopSessionCountdown() {
  if (sessionCountdownTimer === null) return;
  window.clearInterval(sessionCountdownTimer);
  sessionCountdownTimer = null;
}

watch(
  sessionExpiresAt,
  (value) => {
    now.value = Date.now();
    if (value) {
      startSessionCountdown();
    } else {
      stopSessionCountdown();
    }
  },
  { immediate: true },
);

onMounted(() => {
  if (sessionExpiresAt.value) startSessionCountdown();
});

onBeforeUnmount(() => {
  stopSessionCountdown();
});
</script>
<style scoped lang="less">
.invoice-pay-page {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  // padding 由 Starter 布局层统一提供
}

.pay-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--td-comp-margin-m);
}

.pay-toolbar__main {
  display: flex;
  gap: var(--td-comp-margin-m);
  align-items: center;
  min-width: 0;

  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-large);
    font-weight: 600;
  }

  p {
    margin: 0.125rem 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.pay-toolbar__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  justify-content: flex-end;
}

.payment-overview {
  display: grid;
  grid-template-columns: minmax(18rem, 0.9fr) minmax(24rem, 1.1fr);
  gap: var(--td-comp-margin-l);
  align-items: stretch;
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.payment-overview__amount {
  display: grid;
  gap: var(--td-comp-margin-xs);
  align-content: center;
  min-width: 0;

  > span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  > strong {
    color: var(--td-brand-color);
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.1;
  }
}

.overview-status-tag {
  width: fit-content;
  min-width: 4rem;
  justify-content: center;
  font-weight: 600;
  border-radius: 62.4375rem;
}

.overview-session {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  margin-top: var(--td-comp-margin-xs);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);

  b {
    display: inline-flex;
    min-height: 1.5rem;
    align-items: center;
    padding: 0 0.5rem;
    color: var(--td-warning-color);
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    background: var(--td-warning-color-light);
    border: thin solid var(--td-warning-color-3);
    border-radius: 62.4375rem;

    &.is-expired {
      color: var(--td-error-color);
      background: var(--td-error-color-light);
      border-color: var(--td-error-color-3);
    }
  }
}

.payment-flow {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--td-comp-margin-s);
}

.payment-flow__item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: var(--td-comp-margin-s);
  align-items: center;
  min-width: 0;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  strong,
  small {
    display: block;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
  }

  small {
    margin-top: 0.125rem;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  &.is-active {
    background: var(--td-brand-color-light);
    border-color: var(--td-brand-color);
  }

  &.is-pending {
    color: var(--td-text-color-secondary);
  }
}

.payment-flow__mark {
  display: inline-flex;
  width: 1.75rem;
  height: 1.75rem;
  align-items: center;
  justify-content: center;
  color: var(--td-brand-color);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: 50%;

  > span {
    width: 0.5rem;
    height: 0.5rem;
    background: currentcolor;
    border-radius: 50%;
  }

  svg {
    width: 1rem;
    height: 1rem;
  }
}

.pay-shell {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(20rem, 23rem);
  gap: var(--td-comp-margin-l);
  align-items: start;
}

.pay-main-column,
.invoice-summary-column,
.invoice-detail-column,
.pay-action-column {
  display: grid;
  gap: var(--td-comp-margin-m);
  min-width: 0;
}

.invoice-summary-column,
.pay-action-column {
  position: sticky;
  top: var(--td-comp-margin-l);
}

.panel-card,
.pay-work-card,
.payment-record-card,
.renew-info-card,
.order-info-card,
.invoice-summary-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.panel-heading {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: flex-start;
  margin-bottom: var(--td-comp-margin-m);

  h2 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);
    font-weight: 600;
  }

  p {
    margin: 0.125rem 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.panel-heading__icon {
  display: inline-flex;
  width: 2rem;
  height: 2rem;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  color: var(--td-brand-color);
  background: var(--td-brand-color-light);
  border-radius: var(--td-radius-medium);

  svg {
    width: 1rem;
    height: 1rem;
  }
}

.pay-work-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--td-comp-margin-m);
  margin-bottom: var(--td-comp-margin-m);

  h2 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);
    font-weight: 600;
  }

  p {
    margin: var(--td-comp-margin-xs) 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.pay-method-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--td-comp-margin-s);
}

.pay-method-card {
  position: relative;
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: flex-start;
  min-height: 4.25rem;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-primary);
  cursor: pointer;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  transition:
    border-color 0.2s ease,
    background-color 0.2s ease,
    box-shadow 0.2s ease,
    transform 0.2s ease;

  &:hover:not(:disabled),
  &:focus-visible {
    border-color: var(--td-brand-color);
    box-shadow: 0 0 0 0.1875rem var(--td-brand-color-light);
    outline: 0;
  }

  &:hover:not(:disabled) {
    transform: translateY(-0.0625rem);
  }

  &:disabled {
    color: var(--td-text-color-disabled);
    cursor: not-allowed;
    background: var(--td-bg-color-component-disabled);
  }

  &.is-active {
    background: var(--td-brand-color-light);
    border-color: var(--td-brand-color);
    box-shadow: inset 0 0 0 0.0625rem var(--td-brand-color);
  }
}

.pay-method-card__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  color: var(--td-brand-color);
  background: var(--td-brand-color-light);
  border-radius: var(--td-radius-medium);

  svg {
    width: 1.25rem;
    height: 1.25rem;
  }
}

.pay-method-card__text {
  display: grid;
  min-width: 0;
  text-align: left;

  strong,
  small {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
  }

  small {
    margin-top: 0.125rem;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.pay-method-card__check {
  position: absolute;
  right: var(--td-comp-paddingLR-s);
  bottom: var(--td-comp-paddingTB-s);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--td-brand-color);

  > span {
    width: 0.625rem;
    height: 0.625rem;
    border: thin solid var(--td-border-color);
    border-radius: 50%;
  }

  svg {
    width: 1rem;
    height: 1rem;
  }
}

.pay-method-card.is-active .pay-method-card__check > span {
  background: var(--td-brand-color);
  border-color: var(--td-brand-color);
  box-shadow: inset 0 0 0 0.1875rem var(--td-bg-color-container);
}

.deduction-panel {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  margin-top: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
}

.deduction-panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--td-comp-margin-s);

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.deduction-summary {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s) var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  padding-top: var(--td-comp-paddingTB-s);
  border-top: thin solid var(--td-border-color);
}

.pay-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  margin-top: var(--td-comp-margin-l);

  :deep(.t-button) {
    min-width: 8rem;
  }
}

.pay-note-card {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: var(--td-comp-margin-s);
  align-items: flex-start;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-secondary);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  > svg {
    width: 1rem;
    height: 1rem;
    margin-top: 0.125rem;
    color: var(--td-brand-color);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
  }

  p {
    margin: 0.25rem 0 0;
    font: var(--td-font-body-small);
    line-height: 1.6;
  }
}

.renew-service-panel {
  display: flex;
  gap: var(--td-comp-margin-m);
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  > div {
    min-width: 0;
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    display: block;
    margin-top: var(--td-comp-margin-xxs);
    color: var(--td-text-color-primary);
    font: var(--td-font-title-small);
    font-weight: 600;
    overflow-wrap: anywhere;
  }

  p {
    margin: var(--td-comp-margin-xs) 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
    line-height: 1.6;
  }
}

.renew-kv-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.0625rem;
  overflow: hidden;
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
}

.renew-kv-item {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-container);

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
    overflow-wrap: anywhere;
  }
}

.renew-flow-section {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  margin-top: var(--td-comp-margin-l);

  h4 {
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
}

.renew-flow-list {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--td-comp-margin-s);
}

.renew-flow-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: var(--td-comp-margin-s);
  align-items: flex-start;
  min-width: 0;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
  }

  p {
    margin: 0.25rem 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
    line-height: 1.55;
  }
}

.renew-flow-item__mark {
  display: inline-flex;
  width: 1.5rem;
  height: 1.5rem;
  align-items: center;
  justify-content: center;
  color: var(--td-brand-color);
  font: var(--td-font-body-small);
  font-weight: 600;
  background: var(--td-brand-color-light);
  border: thin solid var(--td-brand-color);
  border-radius: 50%;
}

.invoice-kv-grid,
.order-summary-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.0625rem;
  overflow: hidden;
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
}

.invoice-kv-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.invoice-kv-item,
.order-kv-item {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  min-width: 0;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-container);

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
    overflow-wrap: anywhere;
  }
}

.invoice-kv-item--wide,
.order-summary-grid__wide {
  grid-column: 1 / -1;
}

.product-route-strip {
  display: grid;
  gap: var(--td-comp-margin-xxs);
  margin-bottom: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-title-small);
    font-weight: 600;
    overflow-wrap: anywhere;
  }
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

  h4 {
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

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
    overflow-wrap: anywhere;
  }
}

.payment-record-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.0625rem;
  overflow: hidden;
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  div {
    display: flex;
    flex-direction: column;
    gap: var(--td-comp-margin-xxs);
    min-width: 0;
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
    background: var(--td-bg-color-container);
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
    overflow-wrap: anywhere;
  }
}

.summary-total {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-s) 0 var(--td-comp-paddingTB-m);
  border-bottom: thin solid var(--td-border-color);

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-brand-color);
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.15;
  }
}

.summary-session {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  margin: var(--td-comp-margin-s) 0 var(--td-comp-margin-m);

  :deep(.t-tag) {
    max-width: 100%;
    height: auto;
    min-height: 1.5rem;
    white-space: normal;
  }
}

.summary-session__countdown {
  display: inline-flex;
  align-items: center;
  min-height: 1.5rem;
  padding: 0 0.5rem;
  color: var(--td-warning-color);
  font: var(--td-font-body-small);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  background: var(--td-warning-color-light);
  border: thin solid var(--td-warning-color-3);
  border-radius: var(--td-radius-small);

  &.is-expired {
    color: var(--td-error-color);
    background: var(--td-error-color-light);
    border-color: var(--td-error-color-3);
  }
}

.summary-list {
  display: grid;
  gap: var(--td-comp-margin-s);

  div {
    display: grid;
    grid-template-columns: 5rem minmax(0, 1fr);
    gap: var(--td-comp-margin-m);
    align-items: center;
    min-height: 2rem;
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 500;
    text-align: right;
    overflow-wrap: anywhere;
  }
}

.summary-status-tag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  justify-self: end;
  width: fit-content;
  min-width: 4rem;
  height: 1.5rem;
  padding: 0 0.625rem;
  font-weight: 600;
  line-height: 1;
  text-align: center;
  border-radius: 62.4375rem;
}

.dialog-qrcode {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 13rem;
  padding: var(--td-comp-paddingTB-s);
}

.dialog-meta {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs);
  padding: 0 var(--td-comp-paddingLR-s);
  text-align: center;

  p {
    margin: 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
    word-break: break-all;
  }
}

@media (max-width: 60rem) {
  .payment-overview,
  .payment-flow,
  .pay-shell,
  .pay-method-list,
  .invoice-kv-grid,
  .renew-kv-grid,
  .renew-flow-list,
  .order-summary-grid,
  .payment-record-grid {
    grid-template-columns: 1fr;
  }

  .invoice-summary-column,
  .pay-action-column {
    position: static;
  }
}

@media (max-width: @screen-sm-rem) {
  .pay-toolbar {
    align-items: flex-start;
    flex-direction: column;
  }

  .pay-toolbar__main {
    align-items: flex-start;
    flex-direction: column;
    gap: var(--td-comp-margin-s);
  }

  .pay-toolbar__actions,
  .pay-actions {
    width: 100%;

    :deep(.t-button) {
      min-width: 0;
      flex: 1 1 10rem;
      margin-left: 0;
    }
  }

  .payment-overview {
    padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  }

  .payment-flow__item {
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  }

  .renew-service-panel {
    align-items: flex-start;
    flex-direction: column;
  }

  .summary-list div {
    grid-template-columns: 1fr;
    gap: var(--td-comp-margin-xxs);

    strong {
      text-align: left;
    }
  }

  .summary-status-tag {
    justify-self: start;
  }

  .snapshot-line-item {
    grid-template-columns: 1fr;
    gap: var(--td-comp-margin-xxs);
  }
}

@media (prefers-reduced-motion: reduce) {
  .pay-method-card,
  .payment-flow__item {
    transition: none;

    &:hover:not(:disabled) {
      transform: none;
    }
  }
}
</style>
