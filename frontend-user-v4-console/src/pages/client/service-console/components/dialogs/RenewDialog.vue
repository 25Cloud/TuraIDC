<template>
  <t-dialog
      v-model:visible="renewVisible"
      :header="$t('serviceConsole.renewDialog.title')"
      width="min(34rem, calc(100vw - 2rem))"
      destroy-on-close
    >
    <loading-state :loading="renewLoading" :text="$t('serviceConsole.renewDialog.loading')" compact>
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
          <span>{{ $t('serviceConsole.renewDialog.couponDiscount') }}</span>
          <t-select
            :model-value="renewForm.user_coupon_id || undefined"
            clearable
            :placeholder="$t('serviceConsole.renewDialog.couponPlaceholder')"
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
          <span class="renew-original-amount">{{ $t('serviceConsole.renewDialog.originalPrice') }} ¥{{ renewOriginalAmount }}</span>
          <span class="renew-agent-label"
            >{{ $t('serviceConsole.renewDialog.agentDiscount') }}（{{ renewAgentGroupName }}）{{
              renewAgentDiscountRate / 10
            }}{{ $t('serviceConsole.renewDialog.discountSuffix') }}</span
          >
        </div>
        <div class="renew-total-line">
          <span>{{ $t('serviceConsole.renewDialog.totalDue') }}</span>
          <strong>¥{{ renewAmount }}</strong>
        </div>
      </template>
      <t-empty v-else-if="!renewLoading" :description="$t('serviceConsole.renewDialog.noRenewCycle')" />
    </loading-state>
    <template #footer>
      <t-button variant="outline" @click="renewVisible = false">{{ $t('serviceConsole.renewDialog.cancel') }}</t-button>
      <t-button theme="primary" :loading="renewSubmitting" :disabled="!renewForm.billing_cycle" @click="submitRenew"
        >{{ $t('serviceConsole.renewDialog.createInvoice') }}</t-button
      >
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
