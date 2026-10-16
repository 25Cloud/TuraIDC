import { computed, ref, watch } from 'vue';

import type { AdminUser } from '@/api/user';
import { userApi } from '@/api/user';
import { t } from '@/locales';
import { formatMoney } from '@/utils/format';

import type { ConsoleServiceDetail } from '../console/types';

type Row = Record<string, any>;

export interface UseServicePriceFactsOptions {
  userId: string;
  detail: { value: ConsoleServiceDetail };
}

/**
 * 折扣比例标签。与后端 ResolvesSiteAgentDiscount::siteAgentDiscountPercent 同口径：
 * 87.50 → 8.8折、90 → 9折（去掉多余的 0），与站点商品卡片观感一致。
 */
export function formatDiscountPercent(rate: number): string {
  const value = rate / 10;
  const text = value.toFixed(1).replace(/0+$/, '').replace(/\.$/, '');
  return `${text}折`;
}

/**
 * 顶部商务区的价格事实。
 *
 * 成交价口径：管理端续费预览（/v2/admin/users/{user}/services/{service}/renewals）返回的
 * 每个计费周期都带 original_amount（原价）/ amount（代理折后成交价）/ agent_discount_rate /
 * agent_group_name，与续费订单 config_snapshot 里写入的 local_renew_amount、original_renew_amount、
 * agent_discount_rate 完全同源同算法（AgentDiscountService::apply），因此与订单快照一致。
 */
export function useServicePriceFacts(options: UseServicePriceFactsOptions) {
  const { userId, detail } = options;

  const owner = ref<AdminUser | null>(null);
  const renewPreview = ref<Row | null>(null);
  const agentRate = ref<number>(100);
  const agentPrice = ref<number | null>(null);
  const agentOriginalPrice = ref<number | null>(null);
  const agentGroupName = ref<string>('');

  const detailRow = computed(() => detail.value as unknown as Row);

  /** 所属用户：只用于首屏展示与代理身份判断，取不到不阻塞页面。 */
  const ownerName = computed(() => {
    const name = String(owner.value?.display_name || owner.value?.nickname || '').trim();
    return name || `${t('services.index.user')} #${userId}`;
  });

  /** 是否代理客户（命中代理折扣）。只有真正拿到小于 100 的折扣率才算。 */
  const hasAgentDiscount = computed(() => agentRate.value > 0 && agentRate.value < 100);

  /** 服务金额即续费原价基准（未打代理折扣、未减优惠券），沿用既有口径。 */
  const renewPriceText = computed(() => formatMoney(detailRow.value.amount));

  /** 实际成交价：有代理折扣时取折后价，否则与原价一致。 */
  const dealPriceText = computed(() => {
    if (!hasAgentDiscount.value || agentPrice.value === null) return renewPriceText.value;
    return formatMoney(agentPrice.value);
  });

  /** 原价（划线展示用）。 */
  const originalPriceText = computed(() => {
    const value = hasAgentDiscount.value
      ? (agentOriginalPrice.value ?? detailRow.value.amount)
      : detailRow.value.amount;
    return formatMoney(value);
  });

  /** 折扣比例标签，如 8.8折；与站点商品卡片观感一致。 */
  const agentDiscountPercent = computed(() => (hasAgentDiscount.value ? formatDiscountPercent(agentRate.value) : ''));

  async function loadOwner() {
    if (Number(userId) <= 0) return;
    try {
      const response = await userApi.detail(userId);
      owner.value = (response?.user as AdminUser) || null;
    } catch {
      owner.value = null;
    }
  }

  /**
   * 取当前计费周期的续费预览，抽出代理折扣三元组。
   * 失败时静默回退到「只显示原价」，不打断页面首屏。
   */
  async function loadRenewPricing() {
    const serviceId = Number(detail.value.id || 0);
    if (Number(userId) <= 0 || serviceId <= 0) return;
    try {
      const response = await userApi.serviceRenewPreview(userId, serviceId);
      const preview = ((response as Row)?.preview || {}) as Row;
      renewPreview.value = preview;
      const cycles = Array.isArray(preview.cycles) ? (preview.cycles as Row[]) : [];
      const billingCycle = String(preview.billing_cycle || detail.value.billing_cycle || '');
      const cycle = cycles.find((item) => String(item.billing_cycle || '') === billingCycle) || cycles[0];
      const rate = Number(cycle?.agent_discount_rate);
      agentRate.value = Number.isFinite(rate) ? rate : 100;
      agentGroupName.value = String(cycle?.agent_group_name || '').trim();
      agentPrice.value = cycle?.amount === undefined || cycle?.amount === null ? null : Number(cycle.amount);
      agentOriginalPrice.value =
        cycle?.original_amount === undefined || cycle?.original_amount === null ? null : Number(cycle.original_amount);
    } catch {
      renewPreview.value = null;
      agentRate.value = 100;
      agentGroupName.value = '';
      agentPrice.value = null;
      agentOriginalPrice.value = null;
    }
  }

  watch(
    () => [userId, detail.value.id, detail.value.billing_cycle].join('|'),
    () => {
      void loadOwner();
      void loadRenewPricing();
    },
    { immediate: true },
  );

  return {
    owner,
    ownerName,
    hasAgentDiscount,
    renewPriceText,
    dealPriceText,
    originalPriceText,
    agentDiscountPercent,
    agentGroupName,
  };
}
