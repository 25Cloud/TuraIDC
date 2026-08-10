<template>
  <section class="client-recharge">
    <t-card class="recharge-stage" :bordered="false">
      <div class="recharge-panel">
        <template v-if="isMobileScreen">
          <div class="recharge-mobile">
            <header class="recharge-mobile__hero">
              <h1>在线充值</h1>
            </header>

            <section class="mobile-balance-card">
              <span class="mobile-balance-card__label">当前余额</span>
              <strong class="mobile-balance-card__value">{{ mobileBalanceText }}</strong>
            </section>

            <section class="recharge-mobile__section">
              <label class="field-label recharge-mobile__label">充值金额</label>
              <div class="mobile-amount-grid">
                <button
                  v-for="item in RECHARGE_PRESET_AMOUNTS"
                  :key="item"
                  type="button"
                  class="mobile-select-card mobile-select-card--amount"
                  :class="{ 'is-active': !mobileCustomSelected && activePreset === item }"
                  @click="handleMobilePresetSelect(item)"
                >
                  <span class="mobile-select-card__value">¥{{ item }}</span>
                  <check-icon v-if="!mobileCustomSelected && activePreset === item" class="mobile-select-card__mark" />
                </button>
                <button
                  type="button"
                  class="mobile-select-card mobile-select-card--amount"
                  :class="{ 'is-active': mobileCustomSelected }"
                  @click="activateMobileCustomAmount"
                >
                  <span class="mobile-select-card__value">自定义</span>
                  <check-icon v-if="mobileCustomSelected" class="mobile-select-card__mark" />
                </button>
              </div>

              <div v-if="mobileCustomSelected" class="field-block recharge-mobile__custom-field">
                <t-input-number
                  v-model="inputAmount"
                  class="amount-input amount-input--mobile"
                  :min="1"
                  :max="50000"
                  :step="10"
                  :decimal-places="0"
                  theme="column"
                  suffix="元"
                  @change="handleAmountChange"
                />
              </div>

              <div class="amount-payable amount-payable--mobile">
                <span>实付金额</span>
                <strong>{{ amountText }} 元</strong>
              </div>
            </section>

            <section v-if="hasPaymentGateways" class="recharge-mobile__section">
              <label class="field-label recharge-mobile__label">支付方式</label>
              <div class="mobile-method-list">
                <button
                  v-for="method in paymentGateways"
                  :key="paymentOptionKey(method)"
                  type="button"
                  class="pay-method-card pay-method-card--mobile"
                  :class="{ 'is-active': selectedGateway === paymentOptionKey(method) }"
                  :aria-pressed="selectedGateway === paymentOptionKey(method)"
                  @click="selectPaymentGateway(paymentOptionKey(method))"
                >
                  <span class="pay-method-card__icon">
                    <component :is="paymentMethodIcon(method)" />
                  </span>
                  <span class="pay-method-card__text">
                    <strong>{{ method.name || method.label }}</strong>
                    <small>{{ method.label || '扫码支付' }}</small>
                  </span>
                  <span v-if="selectedGateway === paymentOptionKey(method)" class="pay-method-card__check">
                    <check-icon />
                  </span>
                </button>
              </div>
            </section>
            <t-alert
              v-else
              class="recharge-empty-payment"
              theme="warning"
              :message="paymentGatewaysLoading ? '支付方式加载中' : '暂无可用支付方式，请联系管理员开启支付渠道'"
            />

            <div class="mobile-agreement">
              <t-checkbox v-model="mobileAgreementChecked">我已了解充值说明</t-checkbox>
              <t-button variant="text" theme="primary" size="small" @click="router.push('/client/help')"
                >帮助中心</t-button
              >
            </div>

            <t-button
              theme="primary"
              size="large"
              block
              class="mobile-submit-button"
              :loading="submitting"
              :disabled="!mobileAgreementChecked || !hasPaymentGateways || paymentGatewaysLoading"
              @click="handleCreateOrder(true)"
            >
              {{ mobileSubmitText }}
            </t-button>

            <div v-if="paymentNo || rechargePaid" class="mobile-payment-meta">
              <div class="meta-row">
                <span>支付状态</span>
                <strong>{{ rechargePaid ? '充值成功' : '待支付' }}</strong>
              </div>
              <div class="meta-row">
                <span>商家订单号</span>
                <strong>{{ paymentNo || '--' }}</strong>
              </div>
              <div class="meta-row">
                <span>应付金额</span>
                <strong>{{ amountText }} 元</strong>
              </div>
            </div>

            <div v-if="qrCodeValue && !rechargePaid" class="mobile-pay-helper">
              <p>{{ mobilePayHelperText }}</p>
              <t-button size="small" theme="primary" variant="outline" @click="copyPayUrl">复制支付链接</t-button>
            </div>
          </div>
        </template>

        <template v-else>
          <div class="panel-main">
            <div class="amount-presets">
              <t-button
                v-for="item in RECHARGE_PRESET_AMOUNTS"
                :key="item"
                :theme="activePreset === item ? 'primary' : 'default'"
                :variant="activePreset === item ? 'base' : 'outline'"
                class="preset-chip"
                @click="selectPreset(item)"
              >
                ¥{{ item }}
              </t-button>
            </div>

            <div class="field-block">
              <label class="field-label">充值数量</label>
              <div class="amount-row">
                <t-input-number
                  v-model="inputAmount"
                  class="amount-input"
                  :min="1"
                  :max="50000"
                  :step="10"
                  :decimal-places="0"
                  theme="column"
                  suffix="元"
                  @change="handleAmountChange"
                />
                <div class="amount-payable">
                  <span>实付金额：</span>
                  <strong>{{ amountText }} 元</strong>
                </div>
              </div>
            </div>

            <div v-if="hasPaymentGateways" class="field-block">
              <label class="field-label">选择支付方式</label>
              <div class="pay-methods">
                <t-button
                  v-for="method in paymentGateways"
                  :key="paymentOptionKey(method)"
                  :theme="selectedGateway === paymentOptionKey(method) ? 'primary' : 'default'"
                  :variant="selectedGateway === paymentOptionKey(method) ? 'base' : 'outline'"
                  class="pay-method"
                  :loading="submitting && selectedGateway === paymentOptionKey(method)"
                  :disabled="submitting || paymentGatewaysLoading"
                  @click="handleGatewayCreate(method)"
                >
                  <template #icon><component :is="paymentMethodIcon(method)" /></template>
                  {{
                    selectedGateway === paymentOptionKey(method)
                      ? paymentButtonText
                      : `生成${method.name || method.label || '支付'}二维码`
                  }}
                </t-button>
              </div>
            </div>
            <t-alert
              v-else
              class="recharge-empty-payment"
              theme="warning"
              :message="paymentGatewaysLoading ? '支付方式加载中' : '暂无可用支付方式，请联系管理员开启支付渠道'"
            />
          </div>

          <aside class="qrcode-panel">
            <div class="qrcode-frame" :class="{ 'is-ready': qrCodeValue, 'is-paid': rechargePaid }">
              <qrcode-vue
                v-if="qrCodeValue"
                class="qrcode-svg"
                :class="{ 'is-muted': rechargePaid }"
                :value="qrCodeValue"
                :size="160"
                level="H"
                render-as="svg"
              />
              <transition name="payment-success">
                <div v-if="rechargePaid" class="qrcode-success" aria-live="polite">
                  <span class="success-icon">
                    <check-circle-icon />
                  </span>
                  <strong>充值成功</strong>
                  <small>余额已刷新</small>
                </div>
              </transition>
              <div v-if="!qrCodeValue" class="qrcode-empty">
                <span class="empty-icon">¥</span>
                <p>选择金额后生成支付二维码</p>
              </div>
            </div>

            <div class="qrcode-meta">
              <p class="qrcode-title">{{ qrCodeTitle }}</p>
              <p v-if="qrCodeSubtitle" class="qrcode-subtitle">{{ qrCodeSubtitle }}</p>
              <div class="meta-row">
                <span>商家订单号</span>
                <strong>{{ paymentNo || '--' }}</strong>
              </div>
              <div class="meta-row">
                <span>应付金额</span>
                <strong>{{ amountText }} 元</strong>
              </div>
            </div>
          </aside>
        </template>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import {
  CheckCircleIcon,
  CheckIcon,
  CreditcardIcon,
  LogoAlipayFilledIcon,
  LogoWechatpayFilledIcon,
} from 'tdesign-icons-vue-next';
import { computed, defineAsyncComponent, onMounted, ref } from 'vue';

import { formatMoney, RECHARGE_PRESET_AMOUNTS, useRecharge } from '@/domains/finance/useRecharge';
import type { RechargeGatewayOption } from '@/types/client';

const QrcodeVue = defineAsyncComponent(() => import('qrcode.vue'));

const isMobileScreen = computed(() => {
  if (typeof window === 'undefined') return false;
  return window.matchMedia('(max-width: 48rem)').matches;
});
const mobileCustomMode = ref(false);
const mobileAgreementChecked = ref(true);

const {
  router,
  userStore,
  inputAmount,
  activePreset,
  submitting,
  rechargePaid,
  paymentGatewaysLoading,
  selectedGateway,
  paymentGateways,
  rechargeSummary,
  amountText,
  qrCodeValue,
  paymentNo,
  paymentButtonText,
  qrCodeTitle,
  qrCodeSubtitle,
  hasPaymentGateways,
  selectedPaymentGateway,
  selectPreset,
  selectPaymentGateway,
  handleAmountChange,
  loadPaymentGateways,
  handleCreateOrder,
  copyPayUrl,
} = useRecharge();

const mobileCustomSelected = computed(() => mobileCustomMode.value || activePreset.value === null);
const mobileSubmitText = computed(() => (rechargePaid.value ? '继续充值' : '立即充值'));
const mobilePayHelperText = computed(() => {
  if (selectedPaymentGateway.value?.payment_type === 'wxpay') {
    return '请复制支付链接后在微信或手机浏览器中继续支付。';
  }

  return '若未能自动打开支付宝，请复制支付链接后在手机浏览器中继续支付。';
});

const mobileBalanceText = computed(() => {
  if (rechargePaid.value) {
    return `¥${rechargeSummary.cashBalance || formatMoney(userStore.info?.cash_balance || 0)}`;
  }

  const storeBalance = userStore.info?.cash_balance;
  if (storeBalance !== undefined && storeBalance !== null && String(storeBalance).trim() !== '') {
    return `¥${formatMoney(storeBalance)}`;
  }

  return `¥${rechargeSummary.cashBalance || '0.00'}`;
});

function handleMobilePresetSelect(value: number) {
  mobileCustomMode.value = false;
  selectPreset(value);
}

function activateMobileCustomAmount() {
  mobileCustomMode.value = true;
}

function paymentOptionKey(method: RechargeGatewayOption) {
  return String(method.option_key || method.key || '').trim();
}

function paymentMethodIcon(method: RechargeGatewayOption) {
  if (method.key === 'alipay' || method.payment_type === 'alipay') return LogoAlipayFilledIcon;
  if (method.key === 'wechat' || method.payment_type === 'wxpay') return LogoWechatpayFilledIcon;
  return CreditcardIcon;
}

async function handleGatewayCreate(method: RechargeGatewayOption) {
  selectPaymentGateway(paymentOptionKey(method));
  await handleCreateOrder(false);
}

onMounted(() => {
  void loadPaymentGateways();
});
</script>
<style scoped lang="less">
.client-recharge {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  // padding 由 Starter 布局层统一提供
}

.recharge-stage {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.recharge-panel {
  display: grid;
  grid-template-columns: minmax(0, 1.6fr) minmax(17rem, 0.75fr);
  gap: var(--td-comp-margin-xl);
  align-items: start;
  min-height: 21rem;
}

.panel-main {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-l);
}

.amount-presets {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
}

.preset-chip {
  min-width: 4.875rem;
}

.field-block {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
}

.field-label {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  font-weight: 600;
}

.amount-row,
.pay-methods {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.amount-input {
  width: min(100%, 18rem);
}

.amount-payable {
  display: inline-flex;
  gap: var(--td-comp-margin-xs);
  align-items: baseline;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);

  strong {
    color: var(--td-warning-color);
    font: var(--td-font-body-large);
    font-weight: 700;
  }
}

.pay-method {
  min-width: 13rem;
}

.recharge-empty-payment {
  max-width: 30rem;
}

.recharge-mobile {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-l);
  max-width: 100%;
}

.recharge-mobile__hero {
  padding: var(--td-comp-paddingTB-l) 0 var(--td-comp-paddingTB-s);
  text-align: center;

  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.2;
  }
}

.recharge-mobile__section {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
}

.mobile-balance-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-brand-color-light);
  border: thin solid var(--td-brand-color);
  border-radius: var(--td-radius-medium);
}

.mobile-balance-card__label {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-medium);
}

.mobile-balance-card__value {
  color: var(--td-brand-color);
  font: var(--td-font-title-large);
  font-weight: 700;
}

.recharge-mobile__label {
  font-size: 1.125rem;
  font-weight: 600;
}

.mobile-amount-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--td-comp-margin-s);
}

.mobile-select-card {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 4.75rem;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
  color: var(--td-text-color-primary);
  cursor: pointer;
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  transition:
    border-color var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    background-color var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    box-shadow var(--td-anim-duration-base) var(--td-anim-time-fn-easing);

  &:hover,
  &:focus-visible {
    border-color: var(--td-brand-color);
    outline: 0;
  }

  &.is-active {
    background: var(--td-brand-color-light);
    border-color: var(--td-brand-color);
    box-shadow: inset 0 0 0 0.0625rem var(--td-brand-color);
  }
}

.mobile-select-card__value {
  font-size: 1.125rem;
  font-weight: 600;
}

.mobile-select-card__mark {
  position: absolute;
  top: 0.625rem;
  right: 0.625rem;
  font-size: 1rem;
}

.recharge-mobile__custom-field {
  margin-top: calc(var(--td-comp-margin-xs) * -1);
}

.amount-input--mobile {
  width: 100%;
}

.amount-payable--mobile {
  justify-content: space-between;
  width: 100%;
  padding: var(--td-comp-paddingTB-s) 0 0;

  strong {
    font-size: 1.125rem;
  }
}

.mobile-method-list {
  display: grid;
  gap: var(--td-comp-margin-s);
}

.pay-method-card {
  position: relative;
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  width: 100%;
  min-height: 4.75rem;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-primary);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  &.is-active {
    background: var(--td-brand-color-light);
    border-color: var(--td-brand-color);
    box-shadow: inset 0 0 0 0.0625rem var(--td-brand-color);
  }
}

.pay-method-card--mobile {
  justify-content: flex-start;
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
    width: 1.375rem;
    height: 1.375rem;
  }
}

.pay-method-card__text {
  display: grid;
  gap: 0.125rem;
  min-width: 0;
  text-align: left;

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
  }

  small {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.pay-method-card__check {
  position: absolute;
  top: 50%;
  right: var(--td-comp-paddingLR-m);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--td-brand-color);
  transform: translateY(-50%);
}

.mobile-agreement {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
}

.mobile-agreement :deep(.t-checkbox) {
  flex: 1;
  min-width: 0;
}

.mobile-submit-button {
  min-height: 3.25rem;
}

.mobile-payment-meta {
  padding: var(--td-comp-paddingTB-s) 0 0;
}

.mobile-pay-helper {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  color: var(--td-brand-color);
  background: var(--td-brand-color-light);
  border: thin dashed var(--td-brand-color);
  border-radius: var(--td-radius-medium);

  p {
    margin: 0;
    font: var(--td-font-body-small);
  }
}

.qrcode-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--td-comp-margin-m);
}

.qrcode-frame {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 13.75rem;
  height: 13.75rem;
  padding: var(--td-comp-paddingTB-m);
  overflow: hidden;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-2);
  transition:
    border-color var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    box-shadow var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    transform var(--td-anim-duration-base) var(--td-anim-time-fn-easing);

  &.is-ready {
    border-color: var(--td-brand-color);
  }

  &.is-paid {
    border-color: var(--td-success-color);
    box-shadow: var(--td-shadow-3);
    transform: translateY(calc(-1 * var(--td-comp-margin-xxs)));
  }
}

.qrcode-svg {
  position: relative;
  z-index: 1;
  width: 100%;
  height: 100%;
  transition:
    opacity var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    transform var(--td-anim-duration-base) var(--td-anim-time-fn-easing);

  &.is-muted {
    opacity: 0.12;
    transform: scale(0.92);
  }
}

.qrcode-success {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  justify-content: center;
  color: var(--td-success-color);
  text-align: center;

  strong {
    color: var(--td-success-color);
    font: var(--td-font-title-large);
    font-weight: 700;
  }

  small {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.success-icon,
.empty-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--td-radius-round);
}

.success-icon {
  width: var(--td-comp-size-xxxl);
  height: var(--td-comp-size-xxxl);
  color: var(--td-bg-color-container);
  font: var(--td-font-headline-large);
  background: var(--td-success-color);
  box-shadow: var(--td-shadow-2);
}

.qrcode-empty {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  align-items: center;
  color: var(--td-text-color-secondary);
  text-align: center;

  p {
    max-width: 9.5rem;
    margin: 0;
    font: var(--td-font-body-small);
  }
}

.empty-icon {
  width: var(--td-comp-size-xxxl);
  height: var(--td-comp-size-xxxl);
  color: var(--td-brand-color);
  font: var(--td-font-headline-large);
  background: var(--td-brand-color-light);
}

.qrcode-meta {
  width: 100%;
  max-width: 17.5rem;
}

.qrcode-title,
.qrcode-subtitle {
  text-align: center;
}

.qrcode-title {
  margin: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  font-weight: 600;
}

.qrcode-subtitle {
  margin: var(--td-comp-margin-xxs) 0 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.meta-row {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: space-between;
  padding: var(--td-comp-paddingTB-s) 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  border-bottom: thin dashed var(--td-border-color);

  strong {
    min-width: 0;
    overflow: hidden;
    color: var(--td-text-color-primary);
    text-align: right;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.payment-success-enter-active,
.payment-success-leave-active {
  transition:
    opacity var(--td-anim-duration-base) var(--td-anim-time-fn-easing),
    transform var(--td-anim-duration-base) var(--td-anim-time-fn-easing);
}

.payment-success-enter-from,
.payment-success-leave-to {
  opacity: 0;
  transform: translateY(var(--td-comp-margin-xs)) scale(0.96);
}

@media (max-width: @screen-lg-rem) {
  .recharge-panel {
    grid-template-columns: 1fr;
  }
}

@media (max-width: @screen-sm-rem) {
  .recharge-panel {
    grid-template-columns: 1fr;
  }

  .recharge-stage :deep(.t-card__body) {
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  }

  .recharge-mobile__hero {
    padding-top: 0;
  }
}

@media (max-width: 30rem) {
  .recharge-stage :deep(.t-card__body) {
    padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  }

  .recharge-mobile {
    gap: var(--td-comp-margin-m);
  }

  .recharge-mobile__hero h1 {
    font-size: 1.75rem;
  }
}

@media (max-width: 22rem) {
  .mobile-amount-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .mobile-agreement {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
