<template>
  <t-dialog
    :visible="visible"
    :header="$t('products.pricingSync.title')"
    width="620px"
    :confirm-btn="{
      content: $t('products.pricingSync.confirm'),
      loading: submitting,
      disabled: !canSubmit,
    }"
    :cancel-btn="$t('common.cancel')"
    @update:visible="(value: boolean) => emit('update:visible', value)"
    @confirm="submit"
  >
    <p class="pricing-sync-source">
      {{ $t('products.pricingSync.sourceLine', { amount: monthlyPriceText }) }}
    </p>

    <!-- 预览：勾选即参与同步，现值 → 新值逐周期列出，覆盖前先看清楚 -->
    <t-checkbox-group v-model="selectedCycles" class="pricing-sync-cycles">
      <div v-for="row in rows" :key="row.cycle" class="pricing-sync-row">
        <t-checkbox :value="row.cycle" :label="row.label" />
        <span class="pricing-sync-row__months">
          {{ $t('products.pricingSync.monthsHint', { months: row.months }) }}
        </span>
        <span class="pricing-sync-row__current">{{ row.current }}</span>
        <span class="pricing-sync-row__arrow">→</span>
        <strong class="pricing-sync-row__next">{{ row.next }}</strong>
        <t-tag v-if="row.hasCurrent" size="small" theme="warning" variant="light">
          {{ $t('products.pricingSync.existingTag') }}
        </t-tag>
      </div>
    </t-checkbox-group>

    <div class="pricing-sync-overwrite">
      <t-switch v-model="overwrite" />
      <div class="pricing-sync-overwrite__text">
        <strong>{{ $t('products.pricingSync.overwrite') }}</strong>
        <span>{{ $t('products.pricingSync.overwriteHint') }}</span>
      </div>
    </div>

    <!-- 复制不了的东西必须写明白：不许静默丢弃 -->
    <div class="pricing-sync-notice">
      <strong>{{ $t('products.pricingSync.discountTitle') }}</strong>
      <ul>
        <li>{{ $t('products.pricingSync.discountShared') }}</li>
        <li>{{ $t('products.pricingSync.discountCoupon') }}</li>
        <li>{{ $t('products.pricingSync.discountPreset') }}</li>
      </ul>
    </div>

    <p v-if="!monthlyValid" class="pricing-sync-blocked">{{ $t('products.pricingSync.monthlyRequired') }}</p>
  </t-dialog>
</template>
<script setup lang="ts">
import { BILLING_CYCLE, billingCycleMonths, RENEWABLE_BILLING_CYCLES } from '@shared/billingCycle';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, ref, watch } from 'vue';

import type { ProductPricingSyncResult } from '@/api/product';
import { productApi } from '@/api/product';
import { t } from '@/locales';
import { errorMessage } from '@/utils/userMessage';

/**
 * 「按月价同步到其他周期」预览确认弹窗（Issue #77）。
 *
 * 口径：
 * - 一次性动作，不建立持续联动；月数是 `BillingCycle` 的（shared/billingCycle 与后端同一张表）；
 * - 换算 = 月付价 × 周期月数，落到分；这里为了给出**同步前**的预览，用整数分乘法本地算一遍，
 *   真正落库的金额仍然只由后端 `Money` 算，提交后以接口返回的 `pricing` 回填表单；
 * - 覆盖前必须二次确认：默认勾选季/半年/年，逐行给出「现值 → 新值」；
 * - 折扣与优惠券的处理在弹窗里明说，能共享的和复制不了的都写清楚。
 */
const props = withDefaults(
  defineProps<{
    visible?: boolean;
    productId?: number | string | null;
    /** 来源周期（月付）当前价 */
    monthlyPrice?: number | string | null;
    /** 各目标周期当前价，用于展示现值与「已有价格」标记 */
    currentPrices?: Record<string, number | string | null | undefined>;
  }>(),
  { visible: false, productId: null, monthlyPrice: 0, currentPrices: () => ({}) },
);

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
  (event: 'synced', result: ProductPricingSyncResult): void;
}>();

/** 目标周期：在售周期里除月付之外的三档，顺序取共享常量，不另写映射 */
const targetCycles = RENEWABLE_BILLING_CYCLES.filter((cycle) => cycle !== BILLING_CYCLE.MONTHLY);

const CYCLE_LABEL_KEYS: Record<string, string> = {
  [BILLING_CYCLE.QUARTERLY]: 'products.pricingSync.cycles.quarterly',
  [BILLING_CYCLE.SEMIANNUALLY]: 'products.pricingSync.cycles.semiannually',
  [BILLING_CYCLE.ANNUALLY]: 'products.pricingSync.cycles.annually',
};

const selectedCycles = ref<string[]>([...targetCycles]);
const overwrite = ref(true);
const submitting = ref(false);

/** 元 → 整数分：全程整数运算，不出现 0.1 + 0.2 这类浮点尾巴 */
function toCents(value: unknown): number {
  const parsed = Number(value ?? 0);

  return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
}

/** 整数分 → 两位小数字符串 */
function centsToText(cents: number): string {
  const sign = cents < 0 ? '-' : '';
  const abs = Math.abs(cents);

  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

const monthlyValid = computed(() => toCents(props.monthlyPrice) > 0);
const monthlyPriceText = computed(() => centsToText(toCents(props.monthlyPrice)));
const canSubmit = computed(() => Boolean(props.productId) && monthlyValid.value && selectedCycles.value.length > 0);

const rows = computed(() =>
  targetCycles.map((cycle) => {
    const months = billingCycleMonths(cycle) ?? 0;
    const currentCents = toCents(props.currentPrices?.[cycle]);

    return {
      cycle,
      label: t(CYCLE_LABEL_KEYS[cycle] ?? cycle),
      months,
      // 整数分 × 整数月数 = 整数分，天然就是「四舍五入到分」后的结果
      next: centsToText(toCents(props.monthlyPrice) * months),
      current: centsToText(currentCents),
      hasCurrent: currentCents > 0,
    };
  }),
);

// 每次打开都回到「默认全选 + 覆盖」：上一次的勾选不该悄悄延续到下一次覆盖
watch(
  () => props.visible,
  (visible) => {
    if (!visible) return;

    selectedCycles.value = [...targetCycles];
    overwrite.value = true;
  },
);

async function submit() {
  if (!props.productId) return;
  if (!monthlyValid.value) {
    MessagePlugin.warning(t('products.pricingSync.monthlyRequired'));

    return;
  }

  const toCycles = targetCycles.filter((cycle) => selectedCycles.value.includes(cycle));
  if (!toCycles.length) {
    MessagePlugin.warning(t('products.pricingSync.selectRequired'));

    return;
  }

  submitting.value = true;
  try {
    const result = await productApi.copyPricing(props.productId, {
      from_cycle: BILLING_CYCLE.MONTHLY,
      to_cycles: toCycles,
      overwrite: overwrite.value,
    });
    emit('synced', result);
    emit('update:visible', false);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.pricingSync.failed')));
  } finally {
    submitting.value = false;
  }
}
</script>
<style lang="less" scoped>
.pricing-sync-source {
  margin: 0 0 12px;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-2, 13px);
  line-height: 1.6;
}

.pricing-sync-cycles {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-medium, 6px);
  background: var(--td-bg-color-container);
}

.pricing-sync-row {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  font-variant-numeric: tabular-nums;
}

.pricing-sync-row__months {
  min-width: 72px;
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-size-1, 12px);
}

.pricing-sync-row__current {
  color: var(--td-text-color-secondary);
  text-decoration: line-through;
}

.pricing-sync-row__arrow {
  color: var(--td-text-color-placeholder);
}

.pricing-sync-row__next {
  color: var(--td-brand-color);
  font-weight: 650;
}

.pricing-sync-overwrite {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-top: 14px;
}

.pricing-sync-overwrite__text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.pricing-sync-overwrite__text strong {
  font-size: var(--td-font-size-size-2, 13px);
  font-weight: 600;
}

.pricing-sync-overwrite__text span {
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 1.5;
}

.pricing-sync-notice {
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: var(--td-radius-medium, 6px);
  background: var(--td-bg-color-secondarycontainer);
}

.pricing-sync-notice strong {
  font-size: var(--td-font-size-size-2, 13px);
  font-weight: 600;
}

.pricing-sync-notice ul {
  margin: 6px 0 0;
  padding-left: 18px;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 1.7;
}

.pricing-sync-blocked {
  margin: 10px 0 0;
  color: var(--td-error-color);
  font-size: var(--td-font-size-size-2, 13px);
}

@media (width <= 768px) {
  .pricing-sync-row {
    flex-wrap: wrap;
  }
}
</style>
