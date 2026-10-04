<template>
  <section class="console-overview-grid is-cdn">
    <t-card class="console-panel console-panel-wide" title="流量用量" :bordered="false">
      <template #actions>
        <t-button
          v-if="canBuyTrafficPackage"
          variant="text"
          theme="primary"
          :loading="trafficLoading"
          @click="openTrafficPackageDialog"
        >
          {{ detail.traffic?.button_text || '购买流量包' }}
        </t-button>
      </template>
      <div class="detail-grid detail-grid--info">
        <info-cell label="已用流量" :value="trafficUsedText" strong />
        <info-cell label="流量上限" :value="trafficLimitText" strong />
        <info-cell label="剩余流量" :value="trafficRemainingText" strong />
        <info-cell label="使用进度" :value="trafficPercentText" strong />
      </div>
      <t-progress
        v-if="detail.traffic?.limited && Number(detail.traffic.usage_percent) > 0"
        class="cdn-traffic-progress"
        :percentage="Number(detail.traffic.usage_percent)"
        theme="line"
        color="var(--td-brand-color)"
      />
    </t-card>

    <t-card class="console-panel" title="实例档案" :bordered="false">
      <div class="detail-grid detail-grid--stack">
        <info-cell label="实例名称" :value="detail.name || `服务 #${serviceId}`" strong />
        <info-cell label="实例 ID" :value="String(detail.id || '--')" copyable @copy="copyText" />
        <info-cell label="实例规格" :value="instanceSpecText" strong />
        <div class="detail-cell">
          <span>实例状态</span>
          <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
        </div>
        <info-cell label="创建时间" :value="detail.created_at || '--'" strong />
      </div>
    </t-card>

    <t-card class="console-panel" title="加速与防护" :bordered="false">
      <div class="detail-grid detail-grid--stack">
        <info-cell label="加速区域" :value="regionText" strong />
        <info-cell label="节点数" :value="findSpecValue(['节点数', '节点数量'])" strong />
        <info-cell label="防护能力" :value="findSpecValue(['防护', '防御', 'DDOS', 'DDoS'])" strong />
        <info-cell label="回源线路" :value="findSpecValue(['回源', '源站'])" strong />
      </div>
    </t-card>

    <t-card class="console-panel console-panel-wide" title="套餐参数" :bordered="false">
      <div class="detail-grid detail-grid--config">
        <info-cell
          v-for="spec in displaySpecs"
          :key="String(spec.key || spec.label || '')"
          :label="String(spec.label || spec.key || '--')"
          :value="String(spec.value ?? '--')"
          strong
        />
      </div>
    </t-card>

    <t-card class="console-panel console-panel-wide" title="付费信息" :bordered="false">
      <template #actions>
        <t-button v-if="!isTrialMachine" variant="text" theme="primary" @click="openRenewDialog">
          续费管理
        </t-button>
      </template>
      <div class="detail-grid">
        <info-cell label="计费方式" :value="detail.billing_cycle_label || '--'" strong />
        <info-cell label="续费价格" :value="renewPriceText" strong />
        <info-cell label="到期时间" :value="detail.expires_at || '长期有效'" strong warning />
        <info-cell label="订单号" :value="detail.invoice?.order_no || '--'" copyable @copy="copyText" />
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { computed } from 'vue';

import { useServiceConsoleContext } from '../../context';
import { InfoCell } from '../../InfoCell';

const {
  detail,
  serviceId,
  instanceStatusText,
  instanceStatusTheme,
  renewPriceText,
  trafficLoading,
  findSpecValue,
  openRenewDialog,
  openTrafficPackageDialog,
  copyText,
} = useServiceConsoleContext();

const specs = computed(() => (Array.isArray(detail.value.specs) ? detail.value.specs : []));

/** 试用机（上游下发 ontrial）不支持续费，隐藏续费入口 */
const isTrialMachine = computed(() =>
  String(detail.value.billing_cycle || '')
    .trim()
    .toLowerCase() === 'ontrial',
);

const canBuyTrafficPackage = computed(() =>
  Boolean(detail.value.actions?.traffic_package && detail.value.traffic?.purchase_enabled !== false),
);

const trafficUsedText = computed(() => detail.value.traffic?.usage_label || '0G');
const trafficLimitText = computed(() => detail.value.traffic?.limit_label || '不限');
const trafficRemainingText = computed(() => detail.value.traffic?.remaining_label || '不限');
const trafficPercentText = computed(() => {
  const percent = Number(detail.value.traffic?.usage_percent);
  if (!detail.value.traffic?.limited || !Number.isFinite(percent) || percent <= 0) return '--';
  return `${percent.toFixed(percent % 1 === 0 ? 0 : 1)}%`;
});

const instanceSpecText = computed(() =>
  String(
    detail.value.product_full_path ||
      detail.value.combined_display_name ||
      detail.value.product_display_name ||
      detail.value.product?.display_name ||
      detail.value.product?.type_label ||
      '--',
  ),
);

/**
 * 节点区域：上游下发的 label 是「节点区域」，另一些实例用「加速区域/区域/地区」，
 * 这里按包含匹配（findSpecValue 内部是 includes），「节点区域」能命中「区域」。
 */
const regionText = computed(() => {
  const matched = findSpecValue(['节点区域', '加速区域', '区域', '地区'], '');
  if (matched) return matched;
  return String(detail.value.machine_category?.label || '--');
});

interface OverviewSpec {
  key?: unknown;
  label?: unknown;
  value?: unknown;
}

/** 套餐参数：只展示有值的规格项，避免满屏 '--' */
const displaySpecs = computed<OverviewSpec[]>(() =>
  (specs.value as OverviewSpec[])
    .filter((spec) => {
      const value = String(spec?.value ?? '').trim();
      return value !== '' && value !== '--';
    })
    .slice(0, 12),
);
</script>
