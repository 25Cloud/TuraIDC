<template>
  <section class="console-overview-grid is-cdn">
    <t-card class="console-panel console-panel-wide" :title="$t('serviceConsole.cdn.trafficUsage')" :bordered="false">
      <template #actions>
        <t-button
          v-if="canBuyTrafficPackage"
          variant="text"
          theme="primary"
          :loading="trafficLoading"
          @click="openTrafficPackageDialog"
        >
          {{ detail.traffic?.button_text || $t('serviceConsole.traffic.title') }}
        </t-button>
      </template>
      <div class="detail-grid detail-grid--info">
        <info-cell :label="$t('serviceConsole.trafficUsed')" :value="trafficUsedText" strong />
        <info-cell :label="$t('serviceConsole.trafficLimit')" :value="trafficLimitText" strong />
        <info-cell :label="$t('serviceConsole.trafficRemaining')" :value="trafficRemainingText" strong />
        <info-cell :label="$t('serviceConsole.usageProgress')" :value="trafficPercentText" strong />
      </div>
      <t-progress
        v-if="detail.traffic?.limited && Number(detail.traffic.usage_percent) > 0"
        class="cdn-traffic-progress"
        :percentage="Number(detail.traffic.usage_percent)"
        theme="line"
        color="var(--td-brand-color)"
      />
    </t-card>

    <t-card class="console-panel" :title="$t('serviceConsole.instanceArchive')" :bordered="false">
      <div class="detail-grid detail-grid--stack">
        <info-cell
          :label="$t('serviceConsole.instanceName')"
          :value="detail.name || $t('serviceConsole.instanceHash', { id: serviceId })"
          strong
        />
        <info-cell
          :label="$t('serviceConsole.instanceId')"
          :value="String(detail.id || '--')"
          copyable
          @copy="copyText"
        />
        <info-cell :label="$t('serviceConsole.instanceSpec')" :value="instanceSpecText" strong />
        <div class="detail-cell">
          <span>{{ $t('serviceConsole.instanceStatus') }}</span>
          <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
        </div>
        <info-cell :label="$t('serviceConsole.createdAt')" :value="detail.created_at || '--'" strong />
      </div>
    </t-card>

    <t-card class="console-panel" :title="$t('serviceConsole.accelerationProtection')" :bordered="false">
      <div class="detail-grid detail-grid--stack">
        <info-cell :label="$t('serviceConsole.areaLabel.acceleration')" :value="regionText" strong />
        <info-cell :label="$t('serviceConsole.nodeCount')" :value="findSpecValue(['节点数', '节点数量'])" strong />
        <info-cell
          :label="$t('serviceConsole.protection')"
          :value="findSpecValue(['防护', '防御', 'DDOS', 'DDoS'])"
          strong
        />
        <info-cell :label="$t('serviceConsole.originLine')" :value="findSpecValue(['回源', '源站'])" strong />
      </div>
    </t-card>

    <t-card
      class="console-panel console-panel-wide"
      :title="$t('serviceConsole.virtualHost.planSpecs')"
      :bordered="false"
    >
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

    <t-card
      class="console-panel console-panel-wide"
      :title="$t('serviceConsole.virtualHost.billingInfo')"
      :bordered="false"
    >
      <template #actions>
        <t-button v-if="!isTrialMachine" variant="text" theme="primary" @click="openRenewDialog">
          {{ $t('serviceConsole.virtualHost.renewManage') }}
        </t-button>
      </template>
      <div class="detail-grid">
        <info-cell
          :label="$t('serviceConsole.virtualHost.billingCycle')"
          :value="detail.billing_cycle_label || '--'"
          strong
        />
        <info-cell :label="$t('serviceConsole.virtualHost.renewPrice')" :value="renewPriceText" strong />
        <info-cell
          :label="$t('serviceConsole.virtualHost.expiresAt')"
          :value="detail.expires_at || $t('client.services.longTermValid')"
          strong
          warning
        />
        <info-cell
          :label="$t('serviceConsole.virtualHost.orderNo')"
          :value="detail.invoice?.order_no || '--'"
          copyable
          @copy="copyText"
        />
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { computed } from 'vue';

import { t } from '@/locales';

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
const isTrialMachine = computed(
  () =>
    String(detail.value.billing_cycle || '')
      .trim()
      .toLowerCase() === 'ontrial',
);

const canBuyTrafficPackage = computed(() =>
  Boolean(detail.value.actions?.traffic_package && detail.value.traffic?.purchase_enabled !== false),
);

const trafficUsedText = computed(() => detail.value.traffic?.usage_label || '0G');
const trafficLimitText = computed(() => detail.value.traffic?.limit_label || t('serviceConsole.traffic.unlimited'));
const trafficRemainingText = computed(
  () => detail.value.traffic?.remaining_label || t('serviceConsole.traffic.unlimited'),
);
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
