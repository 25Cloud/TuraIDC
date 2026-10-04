<template>
  <section class="cdn-overview">
    <data-state :loading="detailLoading" :empty="false" description="">
      <!-- 流量用量：CDN 唯一按量计费、也是用户最关心的指标 -->
      <t-card v-if="trafficVisible" class="console-panel" title="流量用量" :bordered="false">
        <div class="detail-grid detail-grid--info">
          <info-cell label="已用流量" :value="traffic.usage_label || '0G'" strong />
          <info-cell label="流量上限" :value="traffic.limited ? traffic.limit_label || '不限' : '不限'" strong />
          <info-cell
            label="剩余流量"
            :value="traffic.limited ? traffic.remaining_label || '--' : '不限'"
            :warning="isTrafficOverWarning"
            strong
          />
          <info-cell
            label="用量占比"
            :value="trafficPercentText"
            :warning="isTrafficOverWarning"
            strong
          />
        </div>
        <t-progress
          v-if="trafficPercent !== null"
          class="cdn-traffic-progress"
          :percentage="trafficPercent"
          :color="isTrafficOverWarning ? 'warning' : 'primary'"
          :label="false"
        />
        <template v-if="canBuyTrafficPackage" #actions>
          <t-button
            variant="text"
            theme="primary"
            size="small"
            :loading="trafficLoading"
            @click="openTrafficPackageDialog"
          >
            {{ traffic.button_text || '购买流量包' }}
          </t-button>
        </template>
      </t-card>

      <!-- 实例档案：去掉操作系统 / 公网 IP / CPU 内存等云主机字段 -->
      <t-card class="console-panel" title="实例档案" :bordered="false">
        <div class="detail-grid detail-grid--info">
          <info-cell label="实例名称" :value="detail.name || `服务 #${serviceId}`" strong />
          <info-cell label="实例 ID" :value="String(detail.id || '--')" copyable @copy="copyText" />
          <info-cell label="产品" :value="productText" strong />
          <info-cell label="加速区域" :value="regionText" strong />
          <info-cell label="计费周期" :value="detail.billing_cycle_label || '--'" strong />
          <info-cell label="到期时间" :value="detail.expires_at || '长期有效'" strong />
          <div class="detail-cell">
            <span>运行状态</span>
            <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
          </div>
          <div class="detail-cell">
            <span>自动续费</span>
            <strong>{{ autoRenewLabel }}</strong>
          </div>
        </div>
      </t-card>

      <!-- 加速能力：把 CDN 真正关心的节点/防护/功能开关提到最前 -->
      <t-card v-if="capabilities.length" class="console-panel" title="加速与防护能力" :bordered="false">
        <p class="cdn-group-hint">套餐已开通的加速与安全功能，未开通项如需使用请升级套餐</p>
        <div class="cdn-capability-grid">
          <div v-for="item in capabilities" :key="item.key" class="cdn-capability-item">
            <span class="cdn-capability-label">{{ item.label }}</span>
            <t-tag :theme="capabilityTheme(item.state)" variant="light" size="small">
              {{ item.value }}
            </t-tag>
          </div>
        </div>
      </t-card>

      <!-- 套餐参数：替代云主机的 CPU/内存/系统盘规格表 -->
      <t-card
        v-for="group in groups"
        :key="group.title"
        class="console-panel"
        :title="group.title"
        :bordered="false"
      >
        <p class="cdn-group-hint">{{ group.hint }}</p>
        <div class="detail-grid detail-grid--config">
          <info-cell
            v-for="item in group.items"
            :key="item.key"
            :label="item.label"
            :value="item.value"
            strong
          />
        </div>
      </t-card>

      <t-alert
        v-if="!groups.length && !capabilities.length"
        theme="info"
        title="暂无套餐配置数据"
        description="该实例的上游尚未回传配置项，具体加速参数以上游 CDN 面板为准。"
      />
    </data-state>
  </section>
</template>

<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import { computed } from 'vue';

import { fieldValue } from '@/utils/format';

import { InfoCell } from '../../console/InfoCell';
import { useServiceConsoleContext } from '../../console/context';
import { splitPanelSpecs } from '../../console/tabs/panelSpecs';
import type { PanelCapabilityState } from '../../console/tabs/panelSpecs';

const {
  detail,
  detailLoading,
  serviceId,
  autoRenewLabel,
  instanceStatusText,
  instanceStatusTheme,
  copyText,
  trafficLoading,
  openTrafficPackageDialog,
} = useServiceConsoleContext();

type Row = Record<string, any>;

const detailRow = computed(() => detail.value as unknown as Row);

const productText = computed(
  () =>
    detailRow.value.product_display_name ||
    detailRow.value.product_full_path ||
    detail.value.product?.display_name ||
    detail.value.product?.type_label ||
    '--',
);

/**
 * 加速区域：CDN 是一组节点而非单一机房，
 * 优先取规格里的加速区域，退化到 machine_category（分组名）。
 */
const regionText = computed(() => {
  const specs = Array.isArray(detail.value.specs) ? detail.value.specs : [];
  for (const alias of ['加速区域', '区域', '地区', '线路']) {
    const token = alias.replace(/\s/g, '');
    const hit = specs.find((spec: { key?: string; label?: string; value?: unknown }) => {
      const keys = [spec.key, spec.label].map((v) => String(v || '').replace(/\s/g, ''));
      return keys.some((k) => k.includes(token) || token.includes(k));
    });
    const value = String(hit?.value || '').trim();
    if (value) return value;
  }
  return fieldValue(detail.value.machine_category?.label);
});

const traffic = computed(() => detail.value.traffic || {});

const trafficPercent = computed(() => {
  const percent = traffic.value.usage_percent;
  return typeof percent === 'number' && Number.isFinite(percent) ? percent : null;
});

const trafficPercentText = computed(() => (trafficPercent.value === null ? '--' : `${trafficPercent.value}%`));

/** 超过 80% 视为预警，与流量包购买阈值语义一致 */
const isTrafficOverWarning = computed(() => (trafficPercent.value ?? 0) >= 80);

const trafficVisible = computed(() => traffic.value.limited === true || trafficPercent.value !== null);

const canBuyTrafficPackage = computed(
  () => Boolean(detail.value.actions?.traffic_package) && traffic.value.limited === true,
);

const panelSpecs = computed(() => splitPanelSpecs(detail.value.specs || []));
const capabilities = computed(() => panelSpecs.value.capabilities);
const groups = computed(() => panelSpecs.value.groups);

function capabilityTheme(state: PanelCapabilityState): 'success' | 'default' | 'warning' {
  if (state === 'supported') return 'success';
  if (state === 'unsupported') return 'default';
  return 'warning';
}
</script>

<style lang="less" scoped>
.cdn-group-hint {
  margin: 0 0 var(--td-comp-margin-s, 12px);
  color: var(--td-text-color-placeholder, #999);
  font-size: 0.875rem;
}

.cdn-traffic-progress {
  margin-top: var(--td-comp-margin-s, 12px);
}

.cdn-capability-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: var(--td-comp-margin-s, 12px);
}

.cdn-capability-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 12px;
  border: 1px solid var(--td-component-border, #e7e7e7);
  border-radius: var(--td-radius-small, 3px);
}

.cdn-capability-label {
  color: var(--td-text-color-secondary, #666);
  font-size: 0.875rem;
}
</style>
