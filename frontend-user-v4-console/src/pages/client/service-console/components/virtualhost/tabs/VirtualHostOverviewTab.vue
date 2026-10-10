<template>
  <section class="console-overview-grid is-virtual-host">
    <t-card class="console-panel console-panel-wide" title="主机档案" :bordered="false">
      <div class="detail-grid detail-grid--stack">
        <info-cell label="主机名称" :value="hostNameText" strong />
        <info-cell label="实例 ID" :value="String(detail.id || '--')" copyable @copy="copyText" />
        <info-cell label="主机账号" :value="hostAccountText" copyable @copy="copyText" />
        <div class="detail-cell">
          <span>运行状态</span>
          <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
        </div>
        <info-cell label="机房区域" :value="regionText" strong />
        <info-cell label="开通时间" :value="detail.created_at || '--'" strong />
      </div>
    </t-card>

    <t-card class="console-panel console-panel-wide" title="空间与站点" :bordered="false">
      <div class="detail-grid detail-grid--stack">
        <info-cell label="网页空间" :value="findSpecValue(['WEB空间', '网页空间', '网站空间', '磁盘'])" strong />
        <info-cell label="数据库空间" :value="findSpecValue(['数据库空间', '数据库', 'MySQL'])" strong />
        <info-cell label="月流量" :value="findSpecValue(['流量限制', '月流量', '流量'])" strong />
        <info-cell label="带宽" :value="findSpecValue(['带宽', '宽带'])" strong />
        <info-cell label="可绑域名" :value="findSpecValue(['绑定域名数', '域名数', '域名绑定'])" strong />
      </div>
    </t-card>

    <!-- 面板登录信息：上游（智简魔方）把账号密码散落在 host_data / 自定义区域里，
         后端已用 PanelAccessExtractor 归一化，这里直接展示并支持一键复制 -->
    <t-card
      v-if="hasPanelAccess"
      class="console-panel console-panel-wide"
      title="面板登录信息"
      :bordered="false"
    >
      <template #actions>
        <t-tag v-if="panelTypeName" variant="light" theme="primary">{{ panelTypeName }}</t-tag>
      </template>
      <div class="detail-grid detail-grid--stack">
        <info-cell label="面板地址" :value="panelUrlText" copyable @copy="copyText" />
        <info-cell label="面板账号" :value="panelUsernameText" copyable @copy="copyText" />
        <info-cell
          label="面板密码"
          :value="panelPasswordText"
          copyable
          warning
          @copy="copyText"
        />
      </div>
      <p v-if="panelUrlText !== '--'" class="vh-panel-hint">
        面板地址可能带一次性登录态，建议在新标签页打开；打不开可回到「主机与面板」重新进入。
      </p>
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
      <p v-if="!displaySpecs.length" class="vh-panel-hint">上游未下发额外的套餐参数。</p>
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
  instanceStatusText,
  instanceStatusTheme,
  renewPriceText,
  findSpecValue,
  openRenewDialog,
  copyText,
} = useServiceConsoleContext();

interface OverviewSpec {
  key?: unknown;
  label?: unknown;
  value?: unknown;
}

const specs = computed(() => (Array.isArray(detail.value.specs) ? detail.value.specs : []));

/** 试用机（上游下发 ontrial）不支持续费，隐藏续费入口 */
const isTrialMachine = computed(
  () => String(detail.value.billing_cycle || '').trim().toLowerCase() === 'ontrial',
);

const hostNameText = computed(
  () =>
    String(
      detail.value.name ||
        detail.value.combined_display_name ||
        detail.value.product_display_name ||
        detail.value.product?.display_name ||
        '--',
    ) || `服务 #${detail.value.id}`,
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
  () =>
    String(panel.value?.panel_username || '').trim() ||
    hostAccountText.value ||
    '--',
);
const panelPasswordText = computed(
  () => String(panel.value?.panel_password || '').trim() || '--',
);

const hasPanelAccess = computed(
  () =>
    panelUrlText.value !== '--' ||
    panelUsernameText.value !== '--' ||
    panelPasswordText.value !== '--',
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
