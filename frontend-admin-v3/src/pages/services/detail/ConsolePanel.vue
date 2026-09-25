<template>
  <div class="service-console admin-console">
    <t-loading :loading="detailLoading" size="small">
      <t-card class="console-header-card" :bordered="false">
        <div class="console-header-body">
          <div class="console-header-main">
            <div class="console-title-line">
              <h3 class="console-title">{{ displayName }}</h3>
              <t-tag :theme="instanceStatusTheme" variant="light">{{ instanceStatusText }}</t-tag>
              <t-tag v-if="isServiceRefunded" theme="danger" variant="light">已退款</t-tag>
            </div>
            <div class="console-meta-line">
              <span>产品：{{ fieldValue(productText) }}</span>
              <span>区域：{{ serviceRegion }}</span>
              <span>系统：{{ serviceOs }}</span>
              <span>计费：{{ fieldValue(detail.billing_cycle_label) }}</span>
              <span>续费价：{{ renewPriceText }}</span>
              <span>到期：{{ detail.expires_at || '长期有效' }}</span>
              <span>公网 IP：{{ primaryConnectionText }}</span>
              <span>自动续费：{{ autoRenewLabel }}</span>
            </div>
            <t-alert
              v-if="detail.upstream?.remote_error"
              theme="warning"
              class="console-header-alert"
              :message="String(detail.upstream.remote_error)"
            />
            <div v-if="specChips.length" class="spec-grid">
              <div v-for="item in specChips" :key="item.label" class="spec-chip">
                <span>{{ item.label }}</span>
                <strong>{{ item.value }}</strong>
              </div>
            </div>
            <div class="drawer-actions">
              <t-button
                v-if="canPowerOn"
                theme="success"
                size="small"
                :loading="actionLoading"
                @click="handlePowerAction('on')"
                >开机</t-button
              >
              <t-button
                v-if="canPowerOff"
                theme="danger"
                variant="outline"
                size="small"
                :loading="actionLoading"
                @click="handlePowerAction('off')"
                >关机</t-button
              >
              <t-button
                v-if="canReboot"
                theme="warning"
                variant="outline"
                size="small"
                :loading="actionLoading"
                @click="handlePowerAction('reboot')"
                >重启</t-button
              >
              <t-button
                theme="default"
                size="small"
                :disabled="!canSyncStatus"
                :loading="statusSyncing"
                @click="handleSyncStatus"
                >同步信息</t-button
              >
              <t-button
                v-if="detail.actions?.reinstall"
                theme="default"
                size="small"
                :loading="actionLoading"
                @click="openReinstallDialog"
                >重装系统</t-button
              >
              <t-button
                v-if="canSuspendService"
                theme="warning"
                size="small"
                :loading="adminActionLoading === 'suspend'"
                @click="openSuspendDialog"
                >暂停</t-button
              >
              <t-button
                v-if="canUnsuspendService"
                theme="success"
                variant="outline"
                size="small"
                :loading="adminActionLoading === 'unsuspend'"
                @click="handleUnsuspendService"
                >解除暂停</t-button
              >
              <t-button v-if="canRenewService" theme="primary" variant="outline" size="small" @click="openRenewDialog"
                >续费</t-button
              >
              <t-button
                theme="default"
                size="small"
                :disabled="!detail.actions?.password_reset"
                @click="openPasswordDialog"
                >重置密码</t-button
              >
              <t-button theme="default" size="small" @click="openServiceUpstreamDialog">上游绑定</t-button>
              <t-button theme="default" size="small" @click="openServicePricingDialog">调价</t-button>
              <t-button theme="default" size="small" @click="openNameDialog">改名称</t-button>
              <t-button
                theme="default"
                size="small"
                :disabled="!detail.actions?.manual_provision"
                :loading="adminActionLoading === 'manual-provision'"
                @click="openManualProvisionDialog"
                >手动开通</t-button
              >
              <t-button
                v-if="canRefundService"
                theme="danger"
                size="small"
                :loading="adminActionLoading === 'refund'"
                @click="openServiceRefundDialog"
                >退款</t-button
              >
            </div>
          </div>
        </div>
      </t-card>
    </t-loading>

    <console-alerts />

    <t-tabs v-model="activeTabModel" theme="normal" class="console-tabs">
      <t-tab-panel v-for="nav in consoleNavItems" :key="nav.key" :value="nav.key" :tab="nav.label">
        <component :is="resolveConsoleTabComponent(nav.key)" />
      </t-tab-panel>
    </t-tabs>

    <console-dialogs />

    <t-dialog
      v-model:visible="manualProvisionVisible"
      header="手动开通 / 关联上游"
      width="420px"
      :confirm-btn="{ content: '确认关联', loading: adminActionLoading === 'manual-provision' }"
      @cancel="manualProvisionVisible = false"
      @confirm="handleManualProvision"
    >
      <t-form ref="manualProvisionFormRef" :data="manualProvisionForm" :rules="manualProvisionRules" label-align="top">
        <t-form-item label="上游实例 ID" name="upstream_host_id">
          <t-input-number v-model="manualProvisionForm.upstream_host_id" :min="1" style="width: 100%" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceUpstreamVisible"
      header="上游绑定"
      width="520px"
      :confirm-btn="{ content: '保存', loading: serviceUpstreamSubmitting }"
      @cancel="serviceUpstreamVisible = false"
      @confirm="submitServiceUpstream"
    >
      <t-form ref="serviceUpstreamFormRef" :data="serviceUpstreamForm" :rules="serviceUpstreamRules" label-align="top">
        <t-form-item label="上游接口" name="supplier_id">
          <t-select
            v-model="serviceUpstreamForm.supplier_id"
            clearable
            filterable
            :loading="serviceUpstreamLoading"
            placeholder="请选择上游接口"
          >
            <t-option v-for="item in serviceUpstreamOptions" :key="item.id" :label="item.label" :value="item.id" />
          </t-select>
        </t-form-item>
        <t-form-item label="上游实例 ID" name="upstream_host_id">
          <t-input-number v-model="serviceUpstreamForm.upstream_host_id" :min="1" style="width: 100%" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="servicePricingVisible"
      header="调整价格"
      width="620px"
      :confirm-btn="{ content: '保存', loading: servicePricingSubmitting }"
      @cancel="servicePricingVisible = false"
      @confirm="submitServicePricing"
    >
      <t-form ref="servicePricingFormRef" :data="servicePricingForm" :rules="servicePricingRules" label-align="top">
        <t-form-item label="购买价格" name="amount">
          <t-input-number v-model="servicePricingForm.amount" :min="0" :decimal-places="2" style="width: 100%" />
        </t-form-item>
        <div v-if="servicePricingEntries.length" class="pricing-list">
          <div v-for="item in servicePricingEntries" :key="item.cycle" class="pricing-row">
            <div>
              <strong>{{ item.label }}</strong>
              <span>基础价 {{ item.base_amount ? formatMoney(item.base_amount) : '未配置' }}</span>
            </div>
            <t-switch v-model="servicePricingForm.locked_pricing[item.cycle].enabled" />
            <t-input-number
              v-model="servicePricingForm.locked_pricing[item.cycle].manual_amount"
              :min="0"
              :decimal-places="2"
              placeholder="手动价"
            />
          </div>
          <t-checkbox v-model="servicePricingForm.clear_locked_pricing">恢复默认续费价格</t-checkbox>
        </div>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceRefundVisible"
      header="服务退款"
      width="500px"
      :confirm-btn="{ content: '确认退款', theme: 'danger', loading: adminActionLoading === 'refund' }"
      @cancel="serviceRefundVisible = false"
      @confirm="handleServiceRefund"
    >
      <t-alert theme="warning" message="退款将把对应账单标记为已退款，并关闭该实例的计费流程，当前仅支持全额退款。" />
      <t-form
        ref="serviceRefundFormRef"
        :data="serviceRefundForm"
        :rules="refundRules"
        label-align="top"
        class="dialog-form"
      >
        <t-form-item label="退款金额">
          <t-input :value="formatMoney(serviceRefundAmount)" disabled />
        </t-form-item>
        <t-form-item label="退款方式" name="refund_method">
          <t-radio-group v-model="serviceRefundForm.refund_method">
            <t-radio value="balance">退回余额</t-radio>
            <t-radio value="original" :disabled="!canOriginalServiceRefund">原路退款</t-radio>
          </t-radio-group>
        </t-form-item>
        <t-form-item label="退款原因" name="remark">
          <t-textarea v-model="serviceRefundForm.remark" :maxlength="200" placeholder="请输入退款原因" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="suspendVisible"
      header="暂停实例"
      width="420px"
      :confirm-btn="{ content: '确认暂停', theme: 'warning', loading: adminActionLoading === 'suspend' }"
      @cancel="suspendVisible = false"
      @confirm="handleSuspendService"
    >
      <t-alert
        theme="warning"
        message="暂停后实例将被上游停机，用户将无法继续使用，可在管理端随时解除暂停。"
        class="dialog-alert"
      />
      <t-form label-align="top">
        <t-form-item label="暂停原因">
          <t-textarea v-model="suspendReason" :maxlength="255" placeholder="选填，记录暂停原因" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './console/styles.less';

import { billingCycleLabel as billingCycleLabelOf } from '@shared/billingCycle';
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import { supplierApi } from '@/api/supplier';
import { userApi } from '@/api/user';
import { fieldValue, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { errorMessage } from '@/utils/userMessage';

import ConsoleAlerts from './console/ConsoleAlerts.vue';
import ConsoleDialogs from './console/ConsoleDialogs.vue';
import { provideServiceConsoleContext } from './console/context';
import { resolveConsoleNavItems, resolveConsoleTabComponent } from './console/registry';
import { useServiceConsole } from './console/useServiceConsole';

defineOptions({ name: 'AdminServiceConsolePanel' });

const props = defineProps<{ userId: string }>();

type Row = Record<string, any>;

const serviceConsole = useServiceConsole(props.userId);
provideServiceConsoleContext(serviceConsole);

const {
  detail,
  detailLoading,
  statusSyncing,
  actionLoading,
  activeTab,
  availableTabs,
  consoleAreaLabels,
  canSyncStatus,
  serviceRegion,
  serviceOs,
  primaryConnectionText,
  instanceStatusText,
  instanceStatusTheme,
  renewPriceText,
  autoRenewLabel,
  handleSyncStatus,
  handlePowerAction,
  openRenewDialog,
  openReinstallDialog,
  openPasswordDialog,
  openNameDialog,
  loadDetailBase,
} = serviceConsole;

const activeTabModel = computed({
  get: () => activeTab.value,
  set: (value: string) => {
    activeTab.value = value;
  },
});

const consoleNavItems = computed(() => resolveConsoleNavItems(availableTabs.value, consoleAreaLabels.value));

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
    `服务 #${detail.value.id}`
  );
});
const productText = computed(
  () => detailRow.value.product_display_name || detailRow.value.product_full_path || detail.value.product?.display_name,
);
const specChips = computed(() =>
  (Array.isArray(detail.value.specs) ? detail.value.specs : []).map((item) => ({
    label: String(item.label || item.name || '-'),
    value: String(item.value ?? '-'),
  })),
);
const canPowerOn = computed(() => {
  const available = detail.value.actions?.available || [];
  return Array.isArray(available) && available.includes('power:on') && detail.value.runtime?.power_state !== 'running';
});
const canPowerOff = computed(() => {
  const available = detail.value.actions?.available || [];
  return Array.isArray(available) && available.includes('power:off') && detail.value.runtime?.power_state === 'running';
});
const canReboot = computed(() => {
  const available = detail.value.actions?.available || [];
  return (
    Array.isArray(available) && available.includes('power:reboot') && detail.value.runtime?.power_state === 'running'
  );
});
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
const isServiceRefunded = computed(() => [5, 6].includes(Number(detail.value.status)));

// ==================== 管理端专属操作 ====================
const adminActionLoading = ref('');

const manualProvisionVisible = ref(false);
const manualProvisionFormRef = ref<FormInstanceFunctions>();
const manualProvisionForm = reactive({ upstream_host_id: undefined as number | undefined });
const manualProvisionRules: Record<string, FormRule[]> = {
  upstream_host_id: [required('请输入上游实例 ID')],
};

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
  supplier_id: [{ validator: validateUpstreamPair, message: '选择上游接口时必须填写上游实例 ID', type: 'error' }],
  upstream_host_id: [{ validator: validateUpstreamPair, message: '填写上游实例 ID 时必须选择上游接口', type: 'error' }],
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
  amount: [required('请输入购买价格')],
};
const servicePricingEntries = computed(() =>
  Object.entries(servicePricingForm.locked_pricing || {}).map(([cycle, item]) => ({
    cycle,
    label: billingCycleLabel(cycle),
    base_amount: item?.base_amount || null,
  })),
);

const serviceRefundVisible = ref(false);
const serviceRefundFormRef = ref<FormInstanceFunctions>();
const serviceRefundForm = reactive({ refund_method: 'balance' as 'balance' | 'original', remark: '' });
const refundRules: Record<string, FormRule[]> = {
  refund_method: [required('请选择退款方式')],
  remark: [required('请填写退款原因')],
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

function openManualProvisionDialog() {
  manualProvisionForm.upstream_host_id = Number(detail.value.upstream?.host_id || 0) || undefined;
  manualProvisionVisible.value = true;
  manualProvisionFormRef.value?.clearValidate?.();
}

async function handleManualProvision() {
  const result = await manualProvisionFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;
  adminActionLoading.value = 'manual-provision';
  try {
    await userApi.manualProvisionService(props.userId, detail.value.id, {
      upstream_host_id: Number(manualProvisionForm.upstream_host_id || 0),
    });
    MessagePlugin.success('手动开通指令已下发');
    manualProvisionVisible.value = false;
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '手动开通失败'));
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
        const type = item.provider_label || upstreamBinding.provider_key || '上游';
        return { id, label: `${item.name || `接口 #${item.id}`} · ${type}` };
      })
      .filter((item) => item.id > 0);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载上游接口失败'));
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
    MessagePlugin.success('上游绑定已更新');
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新上游绑定失败'));
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
    MessagePlugin.success('价格信息已更新');
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新价格信息失败'));
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
    MessagePlugin.success(response.message || '服务已完成退款');
    serviceRefundVisible.value = false;
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '退款失败'));
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
    MessagePlugin.success(response.message || '实例已暂停');
    suspendVisible.value = false;
    await loadDetailBase();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '暂停实例失败'));
  } finally {
    adminActionLoading.value = '';
  }
}

function handleUnsuspendService() {
  const dialog = DialogPlugin.confirm({
    header: '解除暂停确认',
    body: '确认对该实例解除暂停？解除后实例将恢复可用。',
    confirmBtn: '确认解除',
    theme: 'warning',
    async onConfirm() {
      adminActionLoading.value = 'unsuspend';
      try {
        const response = await userApi.serviceUnsuspend(props.userId, detail.value.id);
        MessagePlugin.success(response.message || '实例已解除暂停');
        dialog.hide();
        await loadDetailBase();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '解除暂停失败'));
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
