import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import { formatMoney } from '@/utils/format';

import type { ServiceTrafficPackageOption, ServiceTrafficPackagePreview, ServiceTrafficPackageQuote } from '../types';
import { createServiceConsoleApi } from './adminServiceApi';
import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleTrafficPackagesOptions {
  userId: number | string;
  serviceId: { value: number };
}

export function useConsoleTrafficPackages(options: UseConsoleTrafficPackagesOptions) {
  const { userId, serviceId } = options;

  const clientApi = createServiceConsoleApi(userId);

  const trafficVisible = ref(false);
  const trafficLoading = ref(false);
  const trafficQuoting = ref(false);
  const trafficSubmitting = ref(false);
  const trafficData = ref<ServiceTrafficPackagePreview | null>(null);
  const trafficQuote = ref<ServiceTrafficPackageQuote | null>(null);
  const trafficForm = reactive({ target_value: 0 });

  const trafficPackages = computed<ServiceTrafficPackageOption[]>(() =>
    Array.isArray(trafficData.value?.packages) ? trafficData.value.packages : [],
  );

  const selectedTrafficPackage = computed<ServiceTrafficPackageOption | null>(() => {
    const targetValue = Number(trafficForm.target_value || 0);
    return trafficPackages.value.find((item) => Number(item.target_value || 0) === targetValue) || null;
  });

  const trafficPayableAmount = computed(() =>
    formatMoney(trafficQuote.value?.pricing?.amount ?? selectedTrafficPackage.value?.price ?? 0),
  );

  async function loadTrafficPackages() {
    trafficLoading.value = true;
    trafficQuote.value = null;
    try {
      const res = await clientApi.serviceTrafficPackages(serviceId.value);
      trafficData.value = res.data || null;

      const packages = Array.isArray(res.data?.packages) ? res.data.packages : [];
      const firstTargetValue = Number(packages[0]?.target_value || 0);
      trafficForm.target_value = firstTargetValue;

      if (firstTargetValue > 0) {
        await quoteTrafficPackage(firstTargetValue);
      }
    } catch (error: unknown) {
      trafficData.value = null;
      MessagePlugin.error(resolveErrorMessage(error, '加载流量包失败'));
    } finally {
      trafficLoading.value = false;
    }
  }

  async function quoteTrafficPackage(value?: unknown) {
    const targetValue = Number(value ?? trafficForm.target_value ?? 0);
    trafficForm.target_value = targetValue;
    trafficQuote.value = null;

    if (targetValue <= 0) {
      return;
    }

    trafficQuoting.value = true;
    try {
      const res = await clientApi.quoteTrafficPackage(serviceId.value, {
        target_value: targetValue,
      });
      trafficQuote.value = res.data || null;
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '获取流量包报价失败'));
    } finally {
      trafficQuoting.value = false;
    }
  }

  async function openTrafficPackageDialog() {
    trafficVisible.value = true;
    trafficData.value = null;
    trafficQuote.value = null;
    trafficForm.target_value = 0;
    await loadTrafficPackages();
  }

  async function handleTrafficPackageChange(value: unknown) {
    await quoteTrafficPackage(value);
  }

  async function submitTrafficPackageOrder() {
    const targetValue = Number(trafficForm.target_value || 0);
    if (targetValue <= 0) {
      MessagePlugin.warning('请选择流量包档位');
      return;
    }

    trafficSubmitting.value = true;
    try {
      const res = await clientApi.createTrafficPackageOrder(serviceId.value, {
        target_value: targetValue,
      });
      const payload = (res.data || {}) as { message?: string; invoice_no?: string };
      trafficVisible.value = false;
      MessagePlugin.success(
        String(payload.message || `流量包账单已创建${payload.invoice_no ? `（${payload.invoice_no}）` : ''}`),
      );
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '创建流量包账单失败'));
    } finally {
      trafficSubmitting.value = false;
    }
  }

  return {
    trafficVisible,
    trafficLoading,
    trafficQuoting,
    trafficSubmitting,
    trafficData,
    trafficQuote,
    trafficForm,
    trafficPackages,
    selectedTrafficPackage,
    trafficPayableAmount,
    openTrafficPackageDialog,
    handleTrafficPackageChange,
    submitTrafficPackageOrder,
  };
}
