import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { formatMoney } from '@/domains/services/useServiceCenter';
import type { CouponOption, RenewCycleOption, ServiceRenewPreview } from '@/types/client';

import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleRenewOptions {
  serviceId: { value: number };
}

export function useConsoleRenew(options: UseConsoleRenewOptions) {
  const { serviceId } = options;
  const router = useRouter();

  const renewVisible = ref(false);
  const renewLoading = ref(false);
  const renewSubmitting = ref(false);
  const renewData = ref<ServiceRenewPreview | null>(null);
  const renewForm = reactive({ billing_cycle: '', user_coupon_id: 0 });

  const currentRenewCycle = computed<RenewCycleOption | undefined>(() => {
    const cycles: RenewCycleOption[] = Array.isArray(renewData.value?.cycles) ? renewData.value.cycles : [];
    return cycles.find((item) => item.billing_cycle === renewForm.billing_cycle);
  });

  const renewAmount = computed(() => formatMoney(currentRenewCycle.value?.amount || 0));

  const renewOriginalAmount = computed(() => formatMoney(currentRenewCycle.value?.original_amount || 0));

  const renewAgentDiscountRate = computed(() => {
    const rate = Number(currentRenewCycle.value?.agent_discount_rate);
    return Number.isFinite(rate) ? rate : 0;
  });

  const renewAgentGroupName = computed(() => String(currentRenewCycle.value?.agent_group_name || ''));

  const renewHasAgentDiscount = computed(() => {
    const rate = renewAgentDiscountRate.value;
    return rate > 0 && rate < 100;
  });

  const renewCoupons = computed<CouponOption[]>(() =>
    Array.isArray(renewData.value?.available_coupons) ? renewData.value.available_coupons : [],
  );

  async function loadRenewPreview() {
    renewLoading.value = true;
    try {
      const res = await clientApi.serviceRenewPreview(serviceId.value, {
        billing_cycle: renewForm.billing_cycle || undefined,
        user_coupon_id: renewForm.user_coupon_id || undefined,
      });
      renewData.value = res.data || null;
      renewForm.billing_cycle = String(
        res.data?.default_cycle || res.data?.billing_cycle || res.data?.cycles?.[0]?.billing_cycle || '',
      );
      renewForm.user_coupon_id = Number(res.data?.selected_user_coupon_id || 0);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '加载续费信息失败'));
    } finally {
      renewLoading.value = false;
    }
  }

  async function openRenewDialog() {
    renewVisible.value = true;
    renewData.value = null;
    renewForm.billing_cycle = '';
    renewForm.user_coupon_id = 0;
    await loadRenewPreview();
  }

  async function handleRenewCycleChange(value: unknown) {
    renewForm.billing_cycle = String(value || '');
    await loadRenewPreview();
  }

  async function handleRenewCouponChange(value: unknown) {
    renewForm.user_coupon_id = Number(value || 0);
    await loadRenewPreview();
  }

  async function submitRenew() {
    if (!renewForm.billing_cycle) return;
    renewSubmitting.value = true;
    try {
      const res = await clientApi.createRenewOrder(serviceId.value, {
        billing_cycle: renewForm.billing_cycle,
        user_coupon_id: renewForm.user_coupon_id || undefined,
      });
      const invoiceId = Number(res.data?.id || 0);
      renewVisible.value = false;
      MessagePlugin.success('续费账单已创建，正在跳转支付');
      router.push(invoiceId > 0 ? `/client/invoices/${invoiceId}/pay` : '/client/invoices');
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '创建续费账单失败'));
    } finally {
      renewSubmitting.value = false;
    }
  }

  return {
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
    openRenewDialog,
    handleRenewCycleChange,
    handleRenewCouponChange,
    submitRenew,
  };
}
