<template>
  <section class="console-panel-section">
    <data-state
      :loading-text="$t('shared.state.loading')"
      :empty-text="$t('shared.state.empty')"
      :loading="detailLoading"
      :empty="false"
      description=""
    >
      <!-- 流量概览：CDN / 虚拟主机唯一有真实用量的指标 -->
      <t-card
        v-if="trafficOverviewVisible"
        class="console-panel"
        :title="$t('services.console.trafficOverview')"
        :bordered="false"
      >
        <div class="detail-grid detail-grid--info">
          <info-cell :label="$t('services.console.usedTraffic')" :value="detail.traffic?.usage_label || '0G'" strong />
          <info-cell
            :label="$t('services.console.trafficLimit')"
            :value="detail.traffic?.limit_label || $t('services.console.unlimited')"
            strong
          />
          <info-cell
            :label="$t('services.console.remainingTraffic')"
            :value="detail.traffic?.remaining_label || $t('services.console.unlimited')"
            :warning="isTrafficOverWarning"
            strong
          />
          <info-cell
            :label="$t('services.console.usageRatio')"
            :value="trafficPercentText"
            :warning="isTrafficOverWarning"
            strong
          />
        </div>
        <t-progress
          v-if="trafficPercent !== null"
          class="panel-traffic-progress"
          :percentage="trafficPercent"
          :color="isTrafficOverWarning ? 'warning' : 'primary'"
          :label="false"
        />
      </t-card>

      <t-card class="console-panel" :title="$t('services.console.connectionInfo')" :bordered="false">
        <div class="detail-grid detail-grid--info">
          <info-cell
            :label="$t('services.console.instanceName')"
            :value="detail.name || `${$t('services.console.servicePrefix')} #${serviceId}`"
            strong
          />
          <info-cell
            :label="$t('services.console.productType')"
            :value="detail.machine_category?.label || detail.product?.type_label || '--'"
            strong
          />
          <info-cell
            :label="$t('services.console.instanceId')"
            :value="String(detail.id || '--')"
            copyable
            @copy="copyText"
          />
          <info-cell :label="primaryHostnameLabel" :value="primaryHostnameValue" copyable @copy="copyText" />
          <info-cell :label="$t('services.console.expireTime')" :value="detail.expires_at || '--'" strong />
          <info-cell :label="$t('services.console.billingCycle')" :value="detail.billing_cycle_label || '--'" strong />
        </div>
      </t-card>

      <!-- 面板入口：面板型产品没有开关机/重装，用户真正要的是「去哪登录、账号密码是多少」 -->
      <t-card v-if="panelVisible" class="console-panel" :title="$t('services.console.panelEntry')" :bordered="false">
        <p class="panel-group-hint">{{ panelHint }}</p>
        <div class="detail-grid detail-grid--info">
          <info-cell
            v-if="panelUrl"
            :label="$t('services.console.panelUrl')"
            :value="panelUrl"
            copyable
            @copy="copyText"
          />
          <info-cell
            v-if="panelUsername"
            :label="$t('services.console.panelAccount')"
            :value="panelUsername"
            copyable
            @copy="copyText"
          />
          <info-cell
            v-if="panelPassword"
            :label="$t('services.console.panelPassword')"
            :value="revealedPassword ? panelPassword : maskPassword(panelPassword)"
            copyable
            @copy="copyPanelPassword"
          />
        </div>
        <template v-if="panelUrl || panelPassword" #actions>
          <t-button v-if="revealedPassword" variant="outline" size="small" @click="revealedPassword = false">
            {{ $t('services.console.hidePassword') }}
          </t-button>
          <t-button v-else-if="panelPassword" variant="outline" size="small" @click="revealedPassword = true">
            {{ $t('services.console.showPassword') }}
          </t-button>
          <t-button v-if="panelUrl" theme="primary" size="small" @click="openPanel">
            <template #icon><jump-icon /></template>
            {{ $t('services.console.enterPanel') }}
          </t-button>
        </template>
      </t-card>

      <!-- 功能支持：CDN 的 WAF / 四层转发 / Websocket 等，值本身就是支持与否 -->
      <t-card
        v-if="capabilities.length"
        class="console-panel"
        :title="$t('services.console.featureSupport')"
        :bordered="false"
      >
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
      <t-card v-for="group in groups" :key="group.title" class="console-panel" :title="group.title" :bordered="false">
        <p class="panel-group-hint">{{ group.hint }}</p>
        <div class="detail-grid detail-grid--config">
          <info-cell v-for="item in group.items" :key="item.key" :label="item.label" :value="item.value" strong />
        </div>
      </t-card>

      <t-alert
        v-if="!groups.length && !capabilities.length && !panelVisible"
        theme="info"
        :title="$t('services.console.noConfigData')"
        :description="$t('services.console.noConfigDataTip')"
      />
    </data-state>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import { JumpIcon } from 'tdesign-icons-vue-next';
import { computed, ref } from 'vue';

import { t } from '@/locales';

import { useServiceConsoleContext } from '../context';
import { InfoCell } from '../InfoCell';
import type { PanelCapabilityState } from './panelSpecs';
import { splitPanelSpecs } from './panelSpecs';

const { detail, detailLoading, serviceId, copyText } = useServiceConsoleContext();

const panel = computed(() => detail.value.panel || {});
const panelUrl = computed(() => String(panel.value.panel_url || '').trim());
const panelUsername = computed(() => String(panel.value.panel_username || '').trim());
const panelPassword = computed(() => String(panel.value.panel_password || '').trim());
const panelVisible = computed(() => Boolean(panelUrl.value || panelUsername.value || panelPassword.value));

/** 密码默认打码，避免进屏时被旁人看到，也防止截图外泄 */
const revealedPassword = ref(false);

const PANEL_TYPE_LABELS: Record<string, string> = {
  get cdn() {
    return t('services.console.panelTypeCdn');
  },
  get panel() {
    return t('services.console.panelTypePanel');
  },
  get cpanel() {
    return t('services.console.panelTypeCpanel');
  },
  get directadmin() {
    return t('services.console.panelTypeDirectadmin');
  },
  get ftp() {
    return t('services.console.panelTypeFtp');
  },
};

const panelHint = computed(() => {
  const type = String(panel.value.panel_type || '').trim();
  return type && PANEL_TYPE_LABELS[type] ? PANEL_TYPE_LABELS[type] : t('services.console.panelEntryFallback');
});

/** 保留首尾各 2 位，中间固定长度打码，长度不足时全打码 */
function maskPassword(value: string): string {
  if (value.length <= 4) return '*'.repeat(value.length);
  return `${value.slice(0, 2)}${'*'.repeat(Math.max(value.length - 4, 6))}${value.slice(-2)}`;
}

/**
 * 复制面板密码。
 *
 * InfoCell 抛出的是显示值（未展开时是打码后的字符串），直接交给copyText
 * 复制到的是 `ab****yz`。这里忽略事件参数、始终复制真实密码。
 */
function copyPanelPassword() {
  void copyText(panelPassword.value);
}

function openPanel() {
  if (panelUrl.value) {
    window.open(panelUrl.value, '_blank', 'noopener,noreferrer');
  }
}

/** 面板型产品没有操作系统概念，主机名位置改展示实例业务标识 */
const primaryHostnameLabel = computed(() => {
  const type = String(detail.value.product?.type || '').toLowerCase();
  return type === 'cdn' ? t('services.console.cdnIdentifier') : t('services.console.hostIdentifier');
});

const primaryHostnameValue = computed(() => {
  const connection = detail.value.connection;
  return String(connection?.hostname || '').trim() || String(connection?.dedicated_ip || '').trim() || '--';
});

const panelSpecs = computed(() => splitPanelSpecs(detail.value.specs || []));
const capabilities = computed(() => panelSpecs.value.capabilities);
const groups = computed(() => panelSpecs.value.groups);

const trafficPercent = computed(() => {
  const percent = detail.value.traffic?.usage_percent;
  return typeof percent === 'number' && Number.isFinite(percent) ? percent : null;
});

const trafficPercentText = computed(() => (trafficPercent.value === null ? '--' : `${trafficPercent.value}%`));

/** 超过 80% 视为预警，与流量包购买阈值语义一致 */
const isTrafficOverWarning = computed(() => (trafficPercent.value ?? 0) >= 80);

const trafficOverviewVisible = computed(() => detail.value.traffic?.limited === true || trafficPercent.value !== null);

const _progressTheme = computed(() => (isTrafficOverWarning.value ? 'warning' : 'primary'));

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
