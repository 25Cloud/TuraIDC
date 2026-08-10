<template>
  <t-dialog
    v-model:visible="trafficVisible"
    header="购买流量包"
    width="min(36rem, calc(100vw - 2rem))"
    destroy-on-close
  >
    <loading-state :loading="trafficLoading" text="正在加载流量包" compact>
      <template v-if="trafficData?.supported !== false && trafficPackages.length">
        <div class="traffic-summary">
          <div>
            <span>当前流量</span>
            <strong
              >{{ trafficData?.traffic?.usage_label || detail.traffic?.usage_label || '0G' }} /
              {{ trafficData?.traffic?.limit_label || detail.traffic?.limit_label || '不限' }}</strong
            >
          </div>
          <div>
            <span>剩余流量</span>
            <strong>{{ trafficData?.traffic?.remaining_label || detail.traffic?.remaining_label || '不限' }}</strong>
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
          <span>{{ selectedTrafficPackage?.target_label || selectedTrafficPackage?.label || '已选档位' }}</span>
          <strong>{{ trafficQuoting ? '报价中' : `¥${trafficPayableAmount}` }}</strong>
        </div>
      </template>
      <t-empty v-else-if="!trafficLoading" :description="trafficData?.message || '暂无可购买流量包'" />
    </loading-state>
    <template #footer>
      <t-button variant="outline" @click="trafficVisible = false">取消</t-button>
      <t-button
        theme="primary"
        :loading="trafficSubmitting"
        :disabled="trafficLoading || trafficQuoting || !trafficForm.target_value || !trafficPackages.length"
        @click="submitTrafficPackageOrder"
      >
        创建账单
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
