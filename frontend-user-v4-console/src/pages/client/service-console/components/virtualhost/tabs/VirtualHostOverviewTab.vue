<template>
  <section class="console-overview-grid is-virtual-host">
    <t-card
      class="console-panel console-panel-wide"
      :title="$t('serviceConsole.virtualHost.profile')"
      :bordered="false"
    >
      <div class="detail-grid detail-grid--stack">
        <info-cell :label="$t('serviceConsole.virtualHost.hostName')" :value="hostNameText" strong />
        <info-cell
          :label="$t('serviceConsole.virtualHost.instanceId')"
          :value="String(detail.id || '--')"
          copyable
          @copy="copyText"
        />
        <info-cell
          :label="$t('serviceConsole.virtualHost.hostAccount')"
          :value="hostAccountText"
          copyable
          @copy="copyText"
        />
        <div class="detail-cell">
          <span>{{ $t('serviceConsole.virtualHost.runStatus') }}</span>
          <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
        </div>
        <info-cell :label="$t('serviceConsole.virtualHost.idcRegion')" :value="regionText" strong />
        <info-cell :label="$t('serviceConsole.virtualHost.createdAt')" :value="detail.created_at || '--'" strong />
      </div>
    </t-card>

    <t-card
      class="console-panel console-panel-wide"
      :title="$t('serviceConsole.virtualHost.spaceAndSites')"
      :bordered="false"
    >
      <div class="detail-grid detail-grid--stack">
        <info-cell
          :label="$t('serviceConsole.virtualHost.webSpace')"
          :value="findSpecValue(['WEB空间', '网页空间', '网站空间', '磁盘'])"
          strong
        />
        <info-cell
          :label="$t('serviceConsole.virtualHost.databaseSpace')"
          :value="findSpecValue(['数据库空间', '数据库', 'MySQL'])"
          strong
        />
        <info-cell
          :label="$t('serviceConsole.virtualHost.monthlyTraffic')"
          :value="findSpecValue(['流量限制', '月流量', '流量'])"
          strong
        />
        <info-cell
          :label="$t('serviceConsole.virtualHost.bandwidth')"
          :value="findSpecValue(['带宽', '宽带'])"
          strong
        />
        <info-cell
          :label="$t('serviceConsole.virtualHost.bindableDomains')"
          :value="findSpecValue(['绑定域名数', '域名数', '域名绑定'])"
          strong
        />
      </div>
    </t-card>

    <!-- 面板登录信息：上游（智简魔方）把账号密码散落在 host_data / 自定义区域里，
         后端已用 PanelAccessExtractor 归一化，这里直接展示并支持一键复制 -->
    <t-card
      v-if="hasPanelAccess"
      class="console-panel console-panel-wide"
      :title="$t('serviceConsole.virtualHost.panelLogin')"
      :bordered="false"
    >
      <template #actions>
        <t-tag v-if="panelTypeName" variant="light" theme="primary">{{ panelTypeName }}</t-tag>
      </template>
      <div class="detail-grid detail-grid--stack">
        <info-cell :label="$t('serviceConsole.virtualHost.panelUrl')" :value="panelUrlText" copyable @copy="copyText" />
        <info-cell
          :label="$t('serviceConsole.virtualHost.panelAccount')"
          :value="panelUsernameText"
          copyable
          @copy="copyText"
        />
        <info-cell
          :label="$t('serviceConsole.virtualHost.panelPassword')"
          :value="panelPasswordText"
          copyable
          warning
          @copy="copyText"
        />
      </div>
      <p v-if="panelUrlText !== '--'" class="vh-panel-hint">
        {{ $t('serviceConsole.virtualHost.panelUrlHint') }}
      </p>
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
      <p v-if="!displaySpecs.length" class="vh-panel-hint">{{ $t('serviceConsole.virtualHost.noPlanSpecs') }}</p>
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
          :value="detail.expires_at || $t('serviceConsole.virtualHost.longTermValid')"
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

const { detail, instanceStatusText, instanceStatusTheme, renewPriceText, findSpecValue, openRenewDialog, copyText } =
  useServiceConsoleContext();

interface OverviewSpec {
  key?: unknown;
  label?: unknown;
  value?: unknown;
}

const specs = computed(() => (Array.isArray(detail.value.specs) ? detail.value.specs : []));

/** 试用机（上游下发 ontrial）不支持续费，隐藏续费入口 */
const isTrialMachine = computed(
  () =>
    String(detail.value.billing_cycle || '')
      .trim()
      .toLowerCase() === 'ontrial',
);

const hostNameText = computed(
  () =>
    String(
      detail.value.name ||
        detail.value.combined_display_name ||
        detail.value.product_display_name ||
        detail.value.product?.display_name ||
        '--',
    ) || t('serviceConsole.instanceHash', { id: detail.value.id }),
);

/**
 * 主机账号：智简魔方把主机标识放在 host_data.domain（形如 ser581037748091），
 * 它同时就是面板登录账号；没有时退回连接信息的主机名/用户名。
 */
const hostAccountText = computed(
  () =>
    String(detail.value.connection?.hostname || '').trim() ||
    String(detail.value.connection?.username || '').trim() ||
    findSpecValue(['主机账号', '账号', '主机名'], '--'),
);

const regionText = computed(() => {
  const matched = findSpecValue(['机房', '节点区域', '区域', '地区'], '');
  if (matched) return matched;
  return String(detail.value.machine_category?.label || '--');
});

const panel = computed(() => detail.value.panel || null);

const panelUrlText = computed(() => String(panel.value?.panel_url || '').trim() || '--');
const panelUsernameText = computed(
  () => String(panel.value?.panel_username || '').trim() || hostAccountText.value || '--',
);
const panelPasswordText = computed(() => String(panel.value?.panel_password || '').trim() || '--');

const hasPanelAccess = computed(
  () => panelUrlText.value !== '--' || panelUsernameText.value !== '--' || panelPasswordText.value !== '--',
);

/** 面板类型展示名（cpanel / btpanel / ftp 等） */
const panelTypeName = computed(() => String(panel.value?.panel_type || '').trim());

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
<style scoped lang="less">
.vh-panel-hint {
  margin: var(--td-comp-margin-s) 0 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
  line-height: 1.6;
}
</style>
