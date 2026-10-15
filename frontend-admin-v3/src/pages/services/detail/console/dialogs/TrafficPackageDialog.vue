<template>
  <t-dialog
    v-model:visible="trafficVisible"
    :header="$t('services.console.trafficPackageTitle')"
    width="min(36rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <loading-state :loading="trafficLoading" :text="$t('services.console.loadingTrafficPackages')" compact>
      <template v-if="trafficData?.supported !== false && trafficPackages.length">
        <div class="traffic-summary">
          <div>
            <span>{{ $t('services.console.currentTraffic') }}</span>
            <strong
              >{{ trafficData?.traffic?.usage_label || detail.traffic?.usage_label || '0G' }} /
              {{ trafficData?.traffic?.limit_label || detail.traffic?.limit_label || $t('services.console.unlimited') }}</strong
            >
          </div>
          <div>
            <span>{{ $t('services.console.remainingTraffic') }}</span>
            <strong>{{ trafficData?.traffic?.remaining_label || detail.traffic?.remaining_label || $t('services.console.unlimited') }}</strong>
          </div>
        </div>
        <t-radio-group
          v-model="trafficForm.target_value"
          class="traffic-package-group"
          @change="handleTrafficPackageChange"
        >
          <t-radio-button
            v-for="item in trafficPackages"
            :key="String(item.target_value)"
            :value="Number(item.target_value || 0)"
          >
            <span class="traffic-package-option">
              <strong>{{ item.target_label || item.label || `${item.target_value}G` }}</strong>
              <em>¥{{ formatMoney(item.price || 0) }}</em>
            </span>
          </t-radio-button>
        </t-radio-group>
        <div class="traffic-total-line">
          <span>{{ selectedTrafficPackage?.target_label || selectedTrafficPackage?.label || $t('services.console.selectedTier') }}</span>
          <strong>{{ trafficQuoting ? $t('services.console.quoting') : `¥${trafficPayableAmount}` }}</strong>
        </div>
      </template>
      <t-empty v-else-if="!trafficLoading" :description="trafficData?.message || $t('services.console.noTrafficPackage')" />
    </loading-state>
    <template #footer>
      <t-button variant="outline" @click="trafficVisible = false">{{ $t('services.console.cancel') }}</t-button>
      <t-button
        theme="primary"
        :loading="trafficSubmitting"
        :disabled="trafficLoading || trafficQuoting || !trafficForm.target_value || !trafficPackages.length"
        @click="submitTrafficPackageOrder"
      >
        {{ $t('services.console.createBill') }}
      </t-button>
    </template>
  </t-dialog>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';

import { useServiceConsoleContext } from '../context';

const {
  detail,
  trafficVisible,
  trafficLoading,
  trafficQuoting,
  trafficSubmitting,
  trafficData,
  trafficForm,
  trafficPackages,
  selectedTrafficPackage,
  trafficPayableAmount,
  formatMoney,
  handleTrafficPackageChange,
  submitTrafficPackageOrder,
} = useServiceConsoleContext();
</script>
