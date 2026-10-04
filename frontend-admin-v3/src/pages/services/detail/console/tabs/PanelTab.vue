<template>
  <section class="console-panel-section">
    <data-state :loading="detailLoading" :empty="false" description="">
      <!-- 流量概览：CDN / 虚拟主机唯一有真实用量的指标 -->
      <t-card v-if="trafficOverviewVisible" class="console-panel" title="流量概览" :bordered="false">
        <div class="detail-grid detail-grid--info">
          <info-cell
            label="已用流量"
            :value="detail.traffic?.usage_label || '0G'"
            strong
          />
          <info-cell
            label="流量上限"
            :value="detail.traffic?.limit_label || '不限'"
            strong
          />
          <info-cell
            label="剩余流量"
            :value="detail.traffic?.remaining_label || '不限'"
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
          class="panel-traffic-progress"
          :percentage="trafficPercent"
          :theme="progressTheme"
          :label="false"
        />
      </t-card>

      <t-card class="console-panel" title="接入信息" :bordered="false">
        <div class="detail-grid detail-grid--info">
          <info-cell
            label="实例名称"
            :value="detail.name || `服务 #${serviceId}`"
            strong
          />
          <info-cell
            label="产品类型"
            :value="detail.machine_category?.label || detail.product?.type_label || '--'"
            strong
          />
          <info-cell
            label="实例 ID"
            :value="String(detail.id || '--')"
            copyable
            @copy="copyText"
          />
          <info-cell
            :label="primaryHostnameLabel"
            :value="primaryHostnameValue"
            copyable
            @copy="copyText"
          />
          <info-cell label="到期时间" :value="detail.expires_at || '--'" strong />
          <info-cell label="计费周期" :value="detail.billing_cycle_label || '--'" strong />
        </div>
      </t-card>

      <!-- 能力开关：CDN 的 WAF / 四层转发 / Websocket 等，值本身就是支持与否 -->
      <t-card v-if="capabilities.length" class="console-panel" title="功能支持" :bordered="false">
        <div class="panel-capability-grid">
          <div v-for="item in capabilities" :key="item.key" class="panel-capability-item">
            <span class="panel-capability-label">{{ item.label }}</span>
            <t-tag :theme="capabilityTheme(item.state)" variant="light" size="small">
              {{ item.value }}
            </t-tag>
          </div>
        </div>
      </t-card>

      <!-- 配置分组：替代云主机的CPU / 内存 / 系统盘规格表 -->
      <t-card
        v-for="group in groups"
        :key="group.title"
        class="console-panel"
        :title="group.title"
        :bordered="false"
      >
        <p class="panel-group-hint">{{ group.hint }}</p>
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
        title="暂无配置数据"
        description="该实例的上游尚未回传配置项，配置详情以上游面板为准。"
      />
    </data-state>
  </section>
</template>

<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import { computed } from 'vue';

import { InfoCell } from '../InfoCell';
import { useServiceConsoleContext } from '../context';
import { splitPanelSpecs } from './panelSpecs';
import type { PanelCapabilityState } from './panelSpecs';

const { detail, detailLoading, serviceId, copyText } = useServiceConsoleContext();

/** 面板型产品没有操作系统概念，主机名位置改展示实例业务标识 */
const primaryHostnameLabel = computed(() => {
  const type = String(detail.value.product?.type || '').toLowerCase();
  return type === 'cdn' ? '实例标识' : '主机标识';
});

const primaryHostnameValue = computed(() => {
  const connection = detail.value.connection;
  return (
    String(connection?.hostname || '').trim() ||
    String(connection?.dedicated_ip || '').trim() ||
    '--'
  );
});

const { capabilities, groups } = computed(() => splitPanelSpecs(detail.value.specs || []));

const trafficPercent = computed(() => {
  const percent = detail.value.traffic?.usage_percent;
  return typeof percent === 'number' && Number.isFinite(percent) ? percent : null;
});

const trafficPercentText = computed(() => (trafficPercent.value === null ? '--' : `${trafficPercent.value}%`));

/** 超过 80% 视为预警，与流量包购买阈值语义一致 */
const isTrafficOverWarning = computed(() => (trafficPercent.value ?? 0) >= 80);

const trafficOverviewVisible = computed(() => detail.value.traffic?.limited === true || trafficPercent.value !== null);

const progressTheme = computed(() => (isTrafficOverWarning.value ? 'warning' : 'primary'));

function capabilityTheme(state: PanelCapabilityState): 'success' | 'default' | 'warning' {
  if (state === 'supported') return 'success';
  if (state === 'unsupported') return 'default';
  return 'warning';
}
</script>

<style lang="less" scoped>
.panel-group-hint {
  margin: 0 0 var(--td-comp-margin-s, 12px);
  color: var(--td-text-color-placeholder, #999);
  font-size: 0.875rem;
}

.panel-traffic-progress {
  margin-top: var(--td-comp-margin-s, 12px);
}

.panel-capability-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: var(--td-comp-margin-s, 12px);
}

.panel-capability-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 12px;
  border: 1px solid var(--td-component-border, #e7e7e7);
  border-radius: var(--td-radius-small, 3px);
}

.panel-capability-label {
  color: var(--td-text-color-secondary, #666);
  font-size: 0.875rem;
}
</style>
