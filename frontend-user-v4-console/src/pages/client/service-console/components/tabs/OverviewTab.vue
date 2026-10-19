<template>
  <section class="console-overview-grid">
    <t-card class="console-panel" :title="$t('serviceConsole.basicInfo')" :bordered="false">
      <div class="detail-grid detail-grid--info">
        <info-cell
          :label="$t('serviceConsole.instanceName')"
          :value="detail.name || $t('serviceConsole.instanceHash', { id: serviceId })"
          strong
        />
        <info-cell
          :label="$t('serviceConsole.instanceSpec')"
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
        <info-cell
          :label="$t('serviceConsole.instanceId')"
          :value="String(detail.id || '--')"
          copyable
          @copy="copyText"
        />
        <info-cell :label="$t('serviceConsole.os')" :value="serviceOs" strong />
        <info-cell :label="$t('serviceConsole.createdAt')" :value="detail.created_at || '--'" strong />
        <div class="detail-cell">
          <span>{{ $t('serviceConsole.instanceStatus') }}</span>
          <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
        </div>
      </div>
    </t-card>

    <t-card class="console-panel console-panel-wide" :title="$t('serviceConsole.configInfo')" :bordered="false">
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
      <div class="detail-grid detail-grid--config">
        <info-cell
          :label="$t('serviceConsole.trafficLabel')"
          :value="
            detail.traffic?.limited
              ? `${detail.traffic.usage_label || '0G'} / ${detail.traffic.limit_label || $t('serviceConsole.traffic.unlimited')}`
              : $t('serviceConsole.traffic.unlimited')
          "
          strong
        />
        <info-cell :label="$t('serviceConsole.regionLabel')" :value="serviceRegion" strong />
        <info-cell label="CPU" :value="findSpecValue(['CPU', '核心'])" strong />
        <info-cell :label="$t('serviceConsole.memoryLabel')" :value="findSpecValue(['内存', 'RAM'])" strong />
        <info-cell :label="$t('serviceConsole.systemDisk')" :value="findSpecValue(['系统盘'])" strong />
        <info-cell :label="$t('serviceConsole.bandwidthLabel')" :value="bandwidthText" strong />
        <info-cell :label="$t('serviceConsole.ipCount')" :value="serviceIpCount" strong />
        <info-cell :label="$t('serviceConsole.dataDisk')" :value="findSpecValue(['数据盘'])" strong />
      </div>
    </t-card>

    <t-card class="console-panel" :title="$t('serviceConsole.networkInfo')" :bordered="false">
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
                  :aria-label="$t('serviceConsole.copyIp', { ip: primaryConnectionValues[0] })"
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
          <span>{{ $t('serviceConsole.internalIp') }}</span>
          <div class="detail-cell-value">
            <template v-if="detail.connection?.internal_ip">
              <strong>{{ detail.connection.internal_ip }}</strong>
              <button
                type="button"
                class="copy-link"
                :aria-label="$t('serviceConsole.copyIp', { ip: detail.connection.internal_ip })"
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

    <t-card class="console-panel" :title="$t('serviceConsole.loginCredentials')" :bordered="false">
      <div class="credential-grid">
        <info-cell
          :label="$t('serviceConsole.usernameLabel')"
          :value="detail.connection?.username || '--'"
          copyable
          @copy="copyText"
        />
        <div class="detail-cell">
          <span>{{ $t('serviceConsole.passwordLabel') }}</span>
          <div class="detail-cell-value credential-password-value">
            <strong class="credential-password-text">{{ resolvedPassword }}</strong>
            <t-tooltip
              v-if="hasConnectionPassword"
              :content="showPassword ? $t('serviceConsole.hidePassword') : $t('serviceConsole.showPassword')"
            >
              <button
                type="button"
                class="copy-link credential-icon-button"
                :aria-label="showPassword ? $t('serviceConsole.hidePassword') : $t('serviceConsole.showPassword')"
                @click="showPassword = !showPassword"
              >
                <browse-off-icon v-if="showPassword" size="1.125rem" />
                <browse-icon v-else size="1.125rem" />
              </button>
            </t-tooltip>
            <t-tooltip v-if="hasConnectionPassword" :content="$t('serviceConsole.copyPassword')">
              <button
                type="button"
                class="copy-link credential-icon-button"
                :aria-label="$t('serviceConsole.copyPassword')"
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
              >{{ $t('serviceConsole.reset') }}</t-button
            >
          </div>
        </div>
        <info-cell
          :label="$t('serviceConsole.portLabel')"
          :value="connectionPortText"
          strong
          copyable
          @copy="copyText"
        />
        <info-cell
          :label="$t('serviceConsole.hostnameLabel')"
          :value="connectionEndpointText"
          copyable
          @copy="copyText"
        />
      </div>
    </t-card>

    <t-card
      class="console-panel console-panel-wide"
      :title="$t('serviceConsole.virtualHost.billingInfo')"
      :bordered="false"
    >
      <template #actions>
        <t-button variant="text" theme="primary" @click="openRenewDialog">{{
          $t('serviceConsole.virtualHost.renewManage')
        }}</t-button>
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

  <t-dialog
    v-model:visible="ipDialogVisible"
    :header="primaryConnectionLabel"
    width="min(24rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <div class="ip-dialog-list">
      <div v-for="ip in primaryConnectionValues" :key="ip" class="ip-dialog-item">
        <strong>{{ ip }}</strong>
        <button type="button" class="copy-link" :aria-label="$t('serviceConsole.copyIp', { ip })" @click="copyText(ip)">
          <copy-icon size="1rem" />
        </button>
      </div>
    </div>
    <template #footer>
      <t-button v-if="primaryConnectionValues.length > 1" variant="outline" @click="copyText(primaryConnectionText)">{{
        $t('serviceConsole.copyAll')
      }}</t-button>
      <t-button theme="primary" @click="ipDialogVisible = false">{{ $t('serviceConsole.close') }}</t-button>
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
