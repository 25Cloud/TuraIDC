<template>
  <t-dialog
    v-model:visible="renewVisible"
    :header="$t('services.console.renewDialog.title')"
    width="min(34rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <loading-state :loading="renewLoading" :text="$t('services.console.renewDialog.loading')" compact>
      <template v-if="renewData">
        <t-radio-group v-model="renewForm.billing_cycle" class="renew-cycle-group" @change="handleRenewCycleChange">
          <t-radio-button
            v-for="cycle in renewData.cycles || []"
            :key="cycle.billing_cycle"
            :value="cycle.billing_cycle"
          >
            {{ cycle.billing_cycle_label }} · ¥{{ formatMoney(cycle.amount) }}
          </t-radio-button>
        </t-radio-group>
        <div v-if="renewCoupons.length" class="renew-coupon-row">
          <span>{{ $t('services.console.renewDialog.discount') }}</span>
          <t-select
            :model-value="renewForm.user_coupon_id || undefined"
            clearable
            :placeholder="$t('services.console.renewDialog.couponPlaceholder')"
            @change="handleRenewCouponChange"
          >
            <t-option
              v-for="coupon in renewCoupons"
              :key="coupon.id"
              :label="`${coupon.name} · ${coupon.discount_label}`"
              :value="coupon.id"
            />
          </t-select>
        </div>
        <div v-if="renewHasAgentDiscount" class="renew-agent-discount">
          <span class="renew-original-amount"
            >{{ $t('services.console.renewDialog.originalPrice') }} ¥{{ renewOriginalAmount }}</span
          >
          <span class="renew-agent-label">{{
            $t('services.console.renewDialog.agentDiscount', {
              group: renewAgentGroupName,
              rate: renewAgentDiscountRate / 10,
            })
          }}</span>
        </div>
        <div class="renew-total-line">
          <span>{{ $t('services.console.renewDialog.payableNow') }}</span>
          <strong>¥{{ renewAmount }}</strong>
        </div>
      </template>
      <t-empty v-else-if="!renewLoading" :description="$t('services.console.renewDialog.noCycle')" />
    </loading-state>
    <template #footer>
      <t-button variant="outline" @click="renewVisible = false">
        {{ $t('services.console.renewDialog.cancel') }}
      </t-button>
      <t-button theme="primary" :loading="renewSubmitting" :disabled="!renewForm.billing_cycle" @click="submitRenew">{{
        $t('services.console.renewDialog.createBill')
      }}</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';

import { useServiceConsoleContext } from '../context';

const {
  renewVisible,
  renewLoading,
  renewSubmitting,
  renewData,
  renewForm,
  renewAmount,
  renewOriginalAmount,
  renewAgentDiscountRate,
  renewAgentGroupName,
  renewHasAgentDiscount,
  renewCoupons,
  formatMoney,
  handleRenewCycleChange,
  handleRenewCouponChange,
  submitRenew,
} = useServiceConsoleContext();
</script>
<style scoped lang="less">
.renew-agent-discount {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  justify-content: space-between;
  margin-top: 1rem;

  .renew-original-amount {
    color: var(--td-text-color-placeholder);
    font-size: 0.8125rem;
    text-decoration: line-through;
  }

  .renew-agent-label {
    color: var(--td-brand-color);
    font-size: 0.8125rem;
    font-weight: 600;
  }
}
</style>
