<template>
  <div class="service-console cdn-console">
    <t-loading :loading="detailLoading" size="small">
      <service-header-panel
        :user-id="props.userId"
        :display-name="displayName"
        :product-text="fieldValue(productText)"
        :refunded-text="$t('services.cdnConsole.refunded')"
        :spec-chips="specChips"
      >
        <template #badges>
          <t-tag variant="outline">CDN</t-tag>
        </template>
      </service-header-panel>

      <t-alert
        v-if="detail.upstream?.remote_error"
        theme="warning"
        class="console-header-alert"
        :message="String(detail.upstream.remote_error)"
      />

      <t-card class="console-actions-card" :bordered="false">
        <div class="console-header-body">
          <div class="console-header-main">
            <!-- CDN 只有账务与上游操作：开关机 / 重装 / 重置密码 / VNC 等云主机动作一律不出现 -->
            <div class="drawer-actions">
              <t-button
                theme="default"
                size="small"
                :disabled="!canSyncStatus"
                :loading="statusSyncing"
                @click="handleSyncStatus"
                >{{ $t('services.cdnConsole.syncInfo') }}</t-button
              >
              <t-button
                v-if="canRenewService"
                theme="primary"
                variant="outline"
                size="small"
                @click="openRenewDialog"
                >{{ $t('services.cdnConsole.renew') }}</t-button
              >
              <t-button
                v-if="canSuspendService"
                theme="warning"
                size="small"
                :loading="adminActionLoading === 'suspend'"
                @click="openSuspendDialog"
                >{{ $t('services.cdnConsole.suspend') }}</t-button
              >
              <t-button
                v-if="canUnsuspendService"
                theme="success"
                variant="outline"
                size="small"
                :loading="adminActionLoading === 'suspend'"
                @click="handleUnsuspendService"
                >{{ $t('services.cdnConsole.unsuspend') }}</t-button
              >
              <t-button theme="default" size="small" @click="openServiceUpstreamDialog">
                {{ $t('services.cdnConsole.upstreamBinding') }}
              </t-button>
              <t-button theme="default" size="small" @click="openServicePricingDialog">
                {{ $t('services.cdnConsole.adjustPrice') }}
              </t-button>
              <t-button theme="default" size="small" @click="openNameDialog">
                {{ $t('services.cdnConsole.renameService') }}
              </t-button>
              <t-button
                v-if="detail.actions?.upstream_provision"
                theme="primary"
                size="small"
                :loading="adminActionLoading === 'upstream-provision'"
                @click="openUpstreamProvisionDialog"
                >{{ $t('services.cdnConsole.upstreamProvision') }}</t-button
              >
              <t-button
                v-if="canRefundService"
                theme="danger"
                size="small"
                :loading="adminActionLoading === 'refund'"
                @click="openServiceRefundDialog"
                >{{ $t('services.cdnConsole.refund') }}</t-button
              >
            </div>
          </div>
        </div>
      </t-card>
    </t-loading>

    <console-alerts />

    <t-tabs v-model="activeTabModel" theme="normal" class="console-tabs">
      <t-tab-panel v-for="nav in consoleNavItems" :key="nav.key" :value="nav.key" :label="nav.label">
        <component :is="resolveCdnTabComponent(nav.key)" />
      </t-tab-panel>
    </t-tabs>

    <console-dialogs />

    <t-dialog
      v-model:visible="upstreamProvisionVisible"
      :header="$t('services.cdnConsole.upstreamProvision')"
      width="480px"
      :confirm-btn="{
        content: $t('services.cdnConsole.upstreamProvisionConfirm'),
        theme: 'primary',
        loading: adminActionLoading === 'upstream-provision',
      }"
      @cancel="upstreamProvisionVisible = false"
      @confirm="handleUpstreamProvision"
    >
      <div class="upstream-provision">
        <p class="upstream-provision__hint">{{ $t('services.cdnConsole.upstreamProvisionHint') }}</p>
        <t-checkbox v-model="upstreamProvisionForm.force">{{
          $t('services.cdnConsole.upstreamProvisionForce')
        }}</t-checkbox>
        <t-input
          v-model="upstreamProvisionForm.remark"
          :placeholder="$t('services.cdnConsole.upstreamProvisionRemark')"
          :maxlength="200"
        />
      </div>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceUpstreamVisible"
      :header="$t('services.cdnConsole.upstreamBinding')"
      width="520px"
      :confirm-btn="{ content: $t('services.cdnConsole.save'), loading: serviceUpstreamSubmitting }"
      @cancel="serviceUpstreamVisible = false"
      @confirm="submitServiceUpstream"
    >
      <t-form ref="serviceUpstreamFormRef" :data="serviceUpstreamForm" :rules="serviceUpstreamRules" label-align="top">
        <t-form-item :label="$t('services.cdnConsole.upstreamInterface')" name="supplier_id">
          <t-select
            v-model="serviceUpstreamForm.supplier_id"
            clearable
            filterable
            :loading="serviceUpstreamLoading"
            :placeholder="$t('services.cdnConsole.selectUpstreamInterface')"
          >
            <t-option v-for="item in serviceUpstreamOptions" :key="item.id" :label="item.label" :value="item.id" />
          </t-select>
        </t-form-item>
        <t-form-item :label="$t('services.cdnConsole.upstreamInstanceId')" name="upstream_host_id">
          <t-input-number v-model="serviceUpstreamForm.upstream_host_id" :min="1" style="width: 100%" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="servicePricingVisible"
      :header="$t('services.cdnConsole.adjustPriceTitle')"
      width="620px"
      :confirm-btn="{ content: $t('services.cdnConsole.save'), loading: servicePricingSubmitting }"
      @cancel="servicePricingVisible = false"
      @confirm="submitServicePricing"
    >
      <t-form ref="servicePricingFormRef" :data="servicePricingForm" :rules="servicePricingRules" label-align="top">
        <t-form-item :label="$t('services.cdnConsole.purchasePrice')" name="amount">
          <t-input-number v-model="servicePricingForm.amount" :min="0" :decimal-places="2" style="width: 100%" />
        </t-form-item>
        <div v-if="servicePricingEntries.length" class="pricing-list">
          <div v-for="item in servicePricingEntries" :key="item.cycle" class="pricing-row">
            <div>
              <strong>{{ item.label }}</strong>
              <span>
                {{ $t('services.cdnConsole.basePrice')
                }}{{ item.base_amount ? formatMoney(item.base_amount) : $t('services.cdnConsole.notConfigured') }}
              </span>
            </div>
            <t-switch v-model="servicePricingForm.locked_pricing[item.cycle].enabled" />
            <t-input-number
              v-model="servicePricingForm.locked_pricing[item.cycle].manual_amount"
              :min="0"
              :decimal-places="2"
              :placeholder="$t('services.cdnConsole.manualPrice')"
            />
          </div>
        </div>
        <t-checkbox v-model="servicePricingForm.clear_locked_pricing">
          {{ $t('services.cdnConsole.restoreDefaultRenewPrice') }}
        </t-checkbox>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceRefundVisible"
      :header="$t('services.cdnConsole.refundTitle')"
      width="500px"
      :confirm-btn="{
        content: $t('services.cdnConsole.confirmRefund'),
        theme: 'danger',
        loading: adminActionLoading === 'refund',
      }"
      @cancel="serviceRefundVisible = false"
      @confirm="handleServiceRefund"
    >
      <t-alert theme="warning" :message="$t('services.cdnConsole.refundWarning')" />
      <t-form
        ref="serviceRefundFormRef"
        :data="serviceRefundForm"
        :rules="refundRules"
        label-align="top"
        class="dialog-form"
      >
        <t-form-item :label="$t('services.cdnConsole.refundAmount')">
          <t-input :value="formatMoney(serviceRefundAmount)" disabled />
        </t-form-item>
        <t-form-item :label="$t('services.cdnConsole.refundMethod')" name="refund_method">
          <t-radio-group v-model="serviceRefundForm.refund_method">
            <t-radio value="balance">{{ $t('services.cdnConsole.refundToBalance') }}</t-radio>
            <t-radio value="original" :disabled="!canOriginalServiceRefund">
              {{ $t('services.cdnConsole.refundToOriginal') }}
            </t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item :label="$t('services.cdnConsole.refundReason')" name="remark">
          <t-textarea
            v-model="serviceRefundForm.remark"
            :maxlength="200"
            :placeholder="$t('services.cdnConsole.refundReasonPlaceholder')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="suspendVisible"
      :header="$t('services.cdnConsole.suspendTitle')"
      width="420px"
      :confirm-btn="{
        content: $t('services.cdnConsole.confirmSuspend'),
        theme: 'warning',
        loading: adminActionLoading === 'suspend',
      }"
      @cancel="suspendVisible = false"
      @confirm="handleSuspendService"
    >
      <t-alert theme="warning" :message="$t('services.cdnConsole.suspendWarning')" class="dialog-alert" />
      <t-form label-align="top">
        <t-form-item :label="$t('services.cdnConsole.suspendReason')">
          <t-textarea
            v-model="suspendReason"
            :maxlength="255"
            :placeholder="$t('services.cdnConsole.suspendReasonPlaceholder')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import '../console/styles.less';

import { billingCycleLabel as billingCycleLabelOf } from '@shared/billingCycle';
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import { supplierApi } from '@/api/supplier';
import { userApi } from '@/api/user';
import { t } from '@/locales';
import { fieldValue, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { isSlowResponseError } from '@/utils/request';
import { errorMessage } from '@/utils/userMessage';

import ServiceHeaderPanel from '../components/ServiceHeaderPanel.vue';
import ConsoleAlerts from '../console/ConsoleAlerts.vue';
import ConsoleDialogs from '../console/ConsoleDialogs.vue';
import { provideServiceConsoleContext } from '../console/context';
import { useServiceConsole } from '../console/useServiceConsole';
import { resolveCdnNavItems, resolveCdnTabComponent } from './registry';

defineOptions({ name: 'AdminCdnConsolePanel' });

const props = defineProps<{ userId: string }>();

type Row = Record<string, any>;

const serviceConsole = useServiceConsole(props.userId);
provideServiceConsoleContext(serviceConsole);

const {
  detail,
  detailLoading,
  statusSyncing,
  activeTab,
  availableTabs,
  consoleAreaLabels,
  canSyncStatus,
  handleSyncStatus,
  openRenewDialog,
  openNameDialog,
  loadDetailBase,
  startBindSyncPolling,
} = serviceConsole;

const activeTabModel = computed({
  get: () => activeTab.value,
  set: (value: string) => {
    activeTab.value = value;
  },
});

const consoleNavItems = computed(() => resolveCdnNavItems(availableTabs.value, consoleAreaLabels.value));

const detailRow = computed(() => detail.value as unknown as Row);
const displayName = computed(() => {
  const d = detailRow.value;
  return (
    d.custom_service_name ||
    d.name ||
    d.product_display_name ||
    d.product_full_path ||
    d.product?.display_name ||
    d.domain ||
    `CDN #${detail.value.id}`
  );
});
const productText = computed(
  () => detailRow.value.product_display_name || detailRow.value.product_full_path || detail.value.product?.display_name,
);
/** 只保留 3 条以内的速览：节点数 / 流量上限 / 加速区域，其余进「套餐与面板」 */
const specChips = computed(() =>
  (Array.isArray(detail.value.specs) ? detail.value.specs : []).slice(0, 4).map((item) => ({
    label: String(item.label || item.name || '-'),
    value: String(item.value ?? '-'),
  })),
);
const canSuspendService = computed(
  () => Number(detail.value.status) === 1 && Number(detail.value.upstream?.host_id || 0) > 0,
);
const canUnsuspendService = computed(
  () => Number(detail.value.status) === 2 && Number(detail.value.upstream?.host_id || 0) > 0,
);
const canRenewService = computed(() => ![0, 4, 5, 6].includes(Number(detail.value.status)));
const canRefundService = computed(() => {
  const status = Number(detail.value.status);
  if ([0, 5, 6].includes(status)) return false;
  const available = detail.value.actions?.available;
  return !Array.isArray(available) || available.includes('refund');
});

// ==================== 管理端专属操作 ====================
const adminActionLoading = ref('');

const upstreamProvisionVisible = ref(false);
const upstreamProvisionForm = reactive({ force: false, remark: '' });

const serviceUpstreamVisible = ref(false);
const serviceUpstreamLoading = ref(false);
const serviceUpstreamSubmitting = ref(false);
const serviceUpstreamFormRef = ref<FormInstanceFunctions>();
const serviceUpstreamForm = reactive({
  supplier_id: undefined as number | undefined,
  upstream_host_id: undefined as number | undefined,
});
const serviceUpstreamOptions = ref<Array<{ id: number; label: string }>>([]);
const serviceUpstreamRules: Record<string, FormRule[]> = {
  supplier_id: [
    { validator: validateUpstreamPair, message: t('services.cdnConsole.upstreamInstanceIdRequired'), type: 'error' },
  ],
  upstream_host_id: [
    { validator: validateUpstreamPair, message: t('services.cdnConsole.upstreamInterfaceRequired'), type: 'error' },
  ],
};

const servicePricingVisible = ref(false);
const servicePricingSubmitting = ref(false);
const servicePricingFormRef = ref<FormInstanceFunctions>();
const servicePricingForm = reactive({
  amount: 0,
  locked_pricing: {} as Record<
    string,
    { enabled: boolean; base_amount?: number | string | null; manual_amount?: number | null | '' }
  >,
  clear_locked_pricing: false,
});
const servicePricingRules: Record<string, FormRule[]> = {
  amount: [required(t('services.console.purchasePriceRequired'))],
};
const servicePricingEntries = computed(() =>
  Object.entries(servicePricingForm.locked_pricing || {}).map(([cycle, item]) => ({
    cycle,
    label: billingCycleLabel(cycle),
    base_amount: item?.base_amount || null,
  })),
);

const serviceRefundVisible = ref(false);
const _serviceRefundSubmitting = ref(false);
const serviceRefundFormRef = ref<FormInstanceFunctions>();
const serviceRefundForm = reactive({ refund_method: 'balance' as 'balance' | 'original', remark: '' });
const refundRules: Record<string, FormRule[]> = {
  refund_method: [required(t('services.console.refundMethodRequired'))],
  remark: [required(t('services.console.refundReasonRequired'))],
};
const canOriginalServiceRefund = computed(() => (detailRow.value.refund as Row | undefined)?.can_original !== false);
const serviceRefundAmount = computed(
  () =>
    (detailRow.value.refund as Row | undefined)?.amount ??
    detail.value.amount ??
    (detailRow.value.order as Row | undefined)?.amount ??
    0,
);

const suspendVisible = ref(false);
const suspendReason = ref('');

function openUpstreamProvisionDialog() {
  upstreamProvisionForm.force = false;
  upstreamProvisionForm.remark = '';
  upstreamProvisionVisible.value = true;
}

async function handleUpstreamProvision() {
  adminActionLoading.value = 'upstream-provision';
  try {
    await userApi.provisionUpstreamService(props.userId, detail.value.id, {
      force: upstreamProvisionForm.force,
      remark: upstreamProvisionForm.remark || undefined,
    });
    MessagePlugin.success(t('services.cdnConsole.upstreamProvisionSuccess'));
    upstreamProvisionVisible.value = false;
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('services.cdnConsole.upstreamProvisionFailed')));
  } finally {
    adminActionLoading.value = '';
  }
}

async function openServiceUpstreamDialog() {
  await loadServiceUpstreamOptions();
  serviceUpstreamForm.supplier_id = Number((detail.value.upstream as Row | undefined)?.supplier_id || 0) || undefined;
  serviceUpstreamForm.upstream_host_id = Number(detail.value.upstream?.host_id || 0) || undefined;
  serviceUpstreamVisible.value = true;
  serviceUpstreamFormRef.value?.clearValidate?.();
}

async function loadServiceUpstreamOptions() {
  if (serviceUpstreamOptions.value.length) return;
  serviceUpstreamLoading.value = true;
  try {
    const response = await supplierApi.list({ status: 1, page: 1, page_size: 100 });
    serviceUpstreamOptions.value = (Array.isArray(response.list) ? response.list : [])
      .map((item: Row) => {
        const id = Number(item.id || 0);
        const upstreamBinding =
          item.upstream_binding && typeof item.upstream_binding === 'object' ? (item.upstream_binding as Row) : {};
        const type = item.provider_label || upstreamBinding.provider_key || t('services.console.upstreamFallback');
        return { id, label: `${item.name || `接口 #${item.id}`} · ${type}` };
      })
      .filter((item) => item.id > 0);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('services.console.loadUpstreamFailed')));
  } finally {
    serviceUpstreamLoading.value = false;
  }
}

async function submitServiceUpstream() {
  const result = await serviceUpstreamFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  serviceUpstreamSubmitting.value = true;
  try {
    await userApi.updateServiceMeta(props.userId, detail.value.id, {
      supplier_id: serviceUpstreamForm.supplier_id ? Number(serviceUpstreamForm.supplier_id) : null,
      upstream_host_id: serviceUpstreamForm.upstream_host_id ? Number(serviceUpstreamForm.upstream_host_id) : null,
    });
    serviceUpstreamVisible.value = false;
    // 上游派生字段要等后台同步任务回填，此刻还没到，先提示「正在同步」
    MessagePlugin.info(t('services.console.upstreamBindSyncing'));
    await loadDetailBase();
    // 上游数据由后台同步任务回填（队列每分钟消费一次），这里轮询等它回来
    startBindSyncPolling();
  } catch (error) {
    if (isSlowResponseError(error)) {
      // 上游响应过慢：绑定请求已提交、后台仍在处理，用中性提示代替红色失败
      MessagePlugin.info(t('services.console.upstreamBindPending'));
    } else {
      MessagePlugin.error(errorMessage(error, t('services.console.updateUpstreamFailed')));
    }
  } finally {
    serviceUpstreamSubmitting.value = false;
  }
}

function openServicePricingDialog() {
  servicePricingForm.amount = toNumber(detail.value.amount);
  servicePricingForm.locked_pricing = createLockedPricingForm(detailRow.value);
  servicePricingForm.clear_locked_pricing = false;
  servicePricingVisible.value = true;
  servicePricingFormRef.value?.clearValidate?.();
}

async function submitServicePricing() {
  const result = await servicePricingFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  const payload: Row = { amount: toNumber(servicePricingForm.amount) };
  if (servicePricingForm.clear_locked_pricing) {
    payload.clear_locked_pricing = true;
  } else {
    payload.locked_pricing = Object.entries(servicePricingForm.locked_pricing).reduce(
      (resultMap, [cycle, item]) => {
        resultMap[cycle] = {
          enabled: Boolean(item.enabled),
          manual_amount:
            item.manual_amount === '' || item.manual_amount === null || item.manual_amount === undefined
              ? null
              : toNumber(item.manual_amount),
        };
        return resultMap;
      },
      {} as Record<string, { enabled: boolean; manual_amount: number | null }>,
    );
  }
  servicePricingSubmitting.value = true;
  try {
    await userApi.updateServiceMeta(props.userId, detail.value.id, payload);
    servicePricingVisible.value = false;
    MessagePlugin.success(t('services.cdnConsole.priceUpdated'));
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('services.console.updatePriceFailed')));
  } finally {
    servicePricingSubmitting.value = false;
  }
}

function openServiceRefundDialog() {
  serviceRefundForm.refund_method = 'balance';
  serviceRefundForm.remark = '';
  serviceRefundVisible.value = true;
  serviceRefundFormRef.value?.clearValidate?.();
}

async function handleServiceRefund() {
  const result = await serviceRefundFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  adminActionLoading.value = 'refund';
  try {
    const response = await userApi.refundService(props.userId, detail.value.id, {
      refund_method: serviceRefundForm.refund_method,
      amount: serviceRefundAmount.value,
      remark: serviceRefundForm.remark,
    });
    MessagePlugin.success(response.message || t('services.console.refundSuccess'));
    serviceRefundVisible.value = false;
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('services.console.refundFailed')));
  } finally {
    adminActionLoading.value = '';
  }
}

function openSuspendDialog() {
  suspendReason.value = '';
  suspendVisible.value = true;
}

async function handleSuspendService() {
  adminActionLoading.value = 'suspend';
  try {
    const response = await userApi.serviceSuspend(props.userId, detail.value.id, {
      reason: suspendReason.value,
    });
    MessagePlugin.success(response.message || t('services.console.instanceSuspended'));
    suspendVisible.value = false;
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('services.console.suspendFailed')));
  } finally {
    adminActionLoading.value = '';
  }
}

function handleUnsuspendService() {
  const dialog = DialogPlugin.confirm({
    header: t('services.console.resumeConfirmTitle'),
    body: t('services.console.resumeConfirmBody'),
    confirmBtn: t('services.cdnConsole.confirmUnbind'),
    theme: 'warning',
    async onConfirm() {
      adminActionLoading.value = 'suspend';
      try {
        const response = await userApi.serviceUnsuspend(props.userId, detail.value.id);
        MessagePlugin.success(response.message || t('services.console.instanceResumed'));
        dialog.hide();
        await loadDetailBase();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('services.console.resumeFailed')));
      } finally {
        adminActionLoading.value = '';
      }
    },
  });
}

function validateUpstreamPair() {
  const supplierId = Number(serviceUpstreamForm.supplier_id || 0);
  const hostId = Number(serviceUpstreamForm.upstream_host_id || 0);
  return (supplierId <= 0 && hostId <= 0) || (supplierId > 0 && hostId > 0);
}

function createLockedPricingForm(current: Row = {}) {
  const cycles = Array.isArray(current.renew_pricing_cycles) ? current.renew_pricing_cycles : [];
  return cycles.reduce(
    (result, item) => {
      const cycle = String(item?.billing_cycle || '').trim();
      if (!cycle) return result;
      result[cycle] = {
        enabled: Boolean(item?.enabled),
        base_amount: item?.base_amount || null,
        manual_amount: item?.manual_amount || '',
      };
      return result;
    },
    {} as Record<
      string,
      { enabled: boolean; base_amount?: number | string | null; manual_amount?: number | null | '' }
    >,
  );
}

function billingCycleLabel(value: unknown) {
  return billingCycleLabelOf(value) || fieldValue(value);
}

function isValidationPass(result: unknown) {
  return result === true || result === undefined;
}

function toNumber(value: unknown) {
  const num = Number(value);
  return Number.isFinite(num) ? num : 0;
}
</script>
