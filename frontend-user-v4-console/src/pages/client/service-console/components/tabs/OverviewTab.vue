<template>
  <section class="console-overview-grid">
    <t-card class="console-panel" title="基本信息" :bordered="false">
      <div class="detail-grid detail-grid--info">
        <info-cell label="实例名称" :value="detail.name || `服务 #${serviceId}`" strong />
        <info-cell
          label="实例规格"
          :value="
            String(
              detail.product_full_path ||
                detail.combined_display_name ||
                detail.product_display_name ||
                detail.product?.display_name ||
                detail.product?.type_label ||
                '--',
            )
          "
          strong
        />
        <info-cell label="实例 ID" :value="String(detail.id || '--')" copyable @copy="copyText" />
        <info-cell label="操作系统" :value="serviceOs" strong />
        <info-cell label="创建时间" :value="detail.created_at || '--'" strong />
        <div class="detail-cell">
          <span>实例状态</span>
          <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
        </div>
      </div>
    </t-card>

    <t-card class="console-panel console-panel-wide" title="配置信息" :bordered="false">
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
      <div class="detail-grid detail-grid--config">
        <info-cell
          label="流量"
          :value="
            detail.traffic?.limited
              ? `${detail.traffic.usage_label || '0G'} / ${detail.traffic.limit_label || '不限'}`
              : '不限'
          "
          strong
        />
        <info-cell label="区域" :value="serviceRegion" strong />
        <info-cell label="CPU" :value="findSpecValue(['CPU', '核心'])" strong />
        <info-cell label="内存" :value="findSpecValue(['内存', 'RAM'])" strong />
        <info-cell label="系统盘" :value="findSpecValue(['系统盘'])" strong />
        <info-cell label="带宽" :value="bandwidthText" strong />
        <info-cell label="IP数量" :value="serviceIpCount" strong />
        <info-cell label="数据盘" :value="findSpecValue(['数据盘'])" strong />
      </div>
    </t-card>

    <t-card class="console-panel" title="网络信息" :bordered="false">
      <div class="detail-grid detail-grid--stack">
        <div class="detail-cell connection-ip-cell">
          <span>{{ primaryConnectionLabel }}</span>
          <div class="detail-cell-value connection-ip-list">
            <template v-if="primaryConnectionValues.length">
              <span class="connection-ip-chip">
                <strong>{{ primaryConnectionValues[0] }}</strong>
                <button
                  type="button"
                  class="copy-link"
                  :aria-label="`复制${primaryConnectionValues[0]}`"
                  @click="copyText(primaryConnectionValues[0])"
                >
                  <copy-icon size="1rem" />
                </button>
              </span>
            </template>
            <strong v-else>--</strong>
          </div>
        </div>
        <div class="detail-cell">
          <span>内网 IP</span>
          <div class="detail-cell-value">
            <template v-if="detail.connection?.internal_ip">
              <strong>{{ detail.connection.internal_ip }}</strong>
              <button
                type="button"
                class="copy-link"
                :aria-label="`复制${detail.connection.internal_ip}`"
                @click="copyText(detail.connection.internal_ip)"
              >
                <copy-icon size="1rem" />
              </button>
            </template>
            <strong v-else>--</strong>
          </div>
        </div>
      </div>
    </t-card>

    <t-card class="console-panel" title="登录凭据" :bordered="false">
      <div class="credential-grid">
        <info-cell label="用户名" :value="detail.connection?.username || '--'" copyable @copy="copyText" />
        <div class="detail-cell">
          <span>密码</span>
          <div class="detail-cell-value credential-password-value">
            <strong class="credential-password-text">{{ resolvedPassword }}</strong>
            <t-tooltip v-if="hasConnectionPassword" :content="showPassword ? '隐藏密码' : '显示密码'">
              <button
                type="button"
                class="copy-link credential-icon-button"
                :aria-label="showPassword ? '隐藏密码' : '显示密码'"
                @click="showPassword = !showPassword"
              >
                <browse-off-icon v-if="showPassword" size="1.125rem" />
                <browse-icon v-else size="1.125rem" />
              </button>
            </t-tooltip>
            <t-tooltip v-if="hasConnectionPassword" content="复制密码">
              <button
                type="button"
                class="copy-link credential-icon-button"
                aria-label="复制密码"
                @click="copyText(detail.connection?.password || '')"
              >
                <copy-icon size="1.125rem" />
              </button>
            </t-tooltip>
            <t-button
              v-if="detail.actions?.password_reset"
              size="small"
              variant="text"
              theme="primary"
              @click="openPasswordDialog"
              >重置</t-button
            >
          </div>
        </div>
        <info-cell label="端口" :value="connectionPortText" strong copyable @copy="copyText" />
        <info-cell label="主机名" :value="connectionEndpointText" copyable @copy="copyText" />
      </div>
    </t-card>

    <t-card class="console-panel console-panel-wide" title="付费信息" :bordered="false">
      <template #actions>
        <t-button variant="text" theme="primary" @click="openRenewDialog">续费管理</t-button>
      </template>
      <div class="detail-grid">
        <info-cell label="计费方式" :value="detail.billing_cycle_label || '--'" strong />
        <info-cell label="续费价格" :value="renewPriceText" strong />
        <info-cell label="到期时间" :value="detail.expires_at || '长期有效'" strong warning />
        <info-cell label="订单号" :value="detail.invoice?.order_no || '--'" copyable @copy="copyText" />
      </div>
    </t-card>
  </section>

  <t-dialog
    v-model:visible="ipDialogVisible"
    :header="primaryConnectionLabel"
    width="min(24rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="ip-dialog-list">
      <div v-for="ip in primaryConnectionValues" :key="ip" class="ip-dialog-item">
        <strong>{{ ip }}</strong>
        <button type="button" class="copy-link" :aria-label="`复制${ip}`" @click="copyText(ip)">
          <copy-icon size="1rem" />
        </button>
      </div>
    </div>
    <template #footer>
      <t-button v-if="primaryConnectionValues.length > 1" variant="outline" @click="copyText(primaryConnectionText)"
        >复制全部</t-button
      >
      <t-button theme="primary" @click="ipDialogVisible = false">关闭</t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import { BrowseIcon, BrowseOffIcon, CopyIcon } from 'tdesign-icons-vue-next';
import { computed, ref } from 'vue';

import { useServiceConsoleContext } from '../context';
import { InfoCell } from '../InfoCell';

const {
  detail,
  serviceId,
  showPassword,
  serviceRegion,
  serviceOs,
  primaryConnectionLabel,
  primaryConnectionValues,
  primaryConnectionText,
  connectionEndpointText,
  connectionPortText,
  instanceStatusText,
  instanceStatusTheme,
  serviceIpCount,
  bandwidthText,
  renewPriceText,
  resolvedPassword,
  findSpecValue,
  openRenewDialog,
  openTrafficPackageDialog,
  openPasswordDialog,
  copyText,
  trafficLoading,
} = useServiceConsoleContext();

const ipDialogVisible = ref(false);

const canBuyTrafficPackage = computed(() =>
  Boolean(detail.value.actions?.traffic_package && detail.value.traffic?.purchase_enabled !== false),
);
const hasConnectionPassword = computed(() => String(detail.value.connection?.password || '').trim() !== '');
</script>
