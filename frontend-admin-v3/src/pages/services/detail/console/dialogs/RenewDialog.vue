<template>
  <t-dialog v-model:visible="renewVisible" header="服务续费" width="min(34rem, calc(100vw - 2rem))" destroy-on-close>
    <loading-state :loading="renewLoading" text="正在加载续费信息" compact>
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
          <span>续费优惠</span>
          <t-select
            :model-value="renewForm.user_coupon_id || undefined"
            clearable
            placeholder="选择优惠券"
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
          <span class="renew-original-amount">原价 ¥{{ renewOriginalAmount }}</span>
          <span class="renew-agent-label"
            >代理折扣（{{ renewAgentGroupName }}）{{ renewAgentDiscountRate / 10 }}折</span
          >
        </div>
        <div class="renew-total-line">
          <span>本次应付</span>
          <strong>¥{{ renewAmount }}</strong>
        </div>
      </template>
      <t-empty v-else-if="!renewLoading" description="未获取到可续费周期" />
    </loading-state>
    <template #footer>
      <t-button variant="outline" @click="renewVisible = false">取消</t-button>
      <t-button theme="primary" :loading="renewSubmitting" :disabled="!renewForm.billing_cycle" @click="submitRenew"
        >创建续费账单</t-button
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
