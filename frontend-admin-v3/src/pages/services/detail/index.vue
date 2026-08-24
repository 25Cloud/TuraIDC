<template>
  <div class="service-detail-page">
    <div class="detail-action-bar">
      <t-button variant="text" theme="default" @click="goBack">
        <template #icon><chevron-left-icon /></template>
        返回
      </t-button>
      <div class="detail-title">
        <h3>{{ displayName }}</h3>
        <span>服务 #{{ serviceId }}</span>
      </div>
      <t-button v-if="hasUser" variant="outline" @click="goUserDetail">查看用户</t-button>
    </div>

    <t-loading :loading="loading" size="small">
      <template v-if="!loading">
        <t-card :bordered="false" header="实例信息">
          <t-descriptions :column="2" bordered>
            <t-descriptions-item label="服务名称">{{ fieldValue(displayName) }}</t-descriptions-item>
            <t-descriptions-item label="状态">{{ serviceStatusLabel(detail.status) }}</t-descriptions-item>
            <t-descriptions-item label="产品">{{
              fieldValue(detail.product_display_name || detail.product_full_path || detail.product?.display_name)
            }}</t-descriptions-item>
            <t-descriptions-item label="产品类型">{{ fieldValue(detail.product?.type_label) }}</t-descriptions-item>
            <t-descriptions-item label="计费周期">{{ fieldValue(detail.billing_cycle_label) }}</t-descriptions-item>
            <t-descriptions-item label="金额">{{ formatMoney(detail.amount) }}</t-descriptions-item>
            <t-descriptions-item label="账单号">{{
              fieldValue(detail.invoice?.invoice_no || detail.order?.invoice_no)
            }}</t-descriptions-item>
            <t-descriptions-item label="上游"
              >{{ fieldValue(detail.upstream?.provider_key)
              }}<template v-if="detail.upstream?.host_id">
                / host #{{ detail.upstream.host_id }}</template
              ></t-descriptions-item
            >
            <t-descriptions-item label="公网 IP">{{
              fieldValue(detail.connection?.dedicated_ip || detail.upstream?.dedicated_ip)
            }}</t-descriptions-item>
            <t-descriptions-item label="登录账号">{{ fieldValue(detail.connection?.username) }}</t-descriptions-item>
            <t-descriptions-item label="登录端口">{{ fieldValue(detail.connection?.port) }}</t-descriptions-item>
            <t-descriptions-item label="运行状态">
              <template v-if="detail.runtime?.power_label || detail.runtime?.description">
                {{ fieldValue(detail.runtime?.power_label || detail.runtime?.description) }}
              </template>
              <t-tooltip v-else content="上游暂未返回实例的电源状态">
                <t-tag theme="warning" variant="light">未获取到运行状态</t-tag>
              </t-tooltip>
            </t-descriptions-item>
            <t-descriptions-item label="到期时间">{{ formatDateTime(detail.expires_at) }}</t-descriptions-item>
            <t-descriptions-item label="创建时间">{{ formatDateTime(detail.created_at) }}</t-descriptions-item>
          </t-descriptions>
          <div v-if="detail.upstream?.remote_error" class="drawer-alert">
            <t-alert theme="warning" :message="detail.upstream.remote_error" />
          </div>
          <div v-if="specs.length" class="spec-grid">
            <div v-for="item in specs" :key="item.label" class="spec-chip">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </div>
          </div>
          <div class="drawer-actions">
            <t-button
              v-if="canPowerOn"
              theme="success"
              size="small"
              :loading="actionLoading === 'power:on'"
              @click="handleServicePower('on')"
              >开机</t-button
            >
            <t-button
              v-if="canPowerOff"
              theme="danger"
              variant="outline"
              size="small"
              :loading="actionLoading === 'power:off'"
              @click="handleServicePower('off')"
              >关机</t-button
            >
            <t-button
              v-if="canReboot"
              theme="warning"
              variant="outline"
              size="small"
              :loading="actionLoading === 'power:reboot'"
              @click="handleServicePower('reboot')"
              >重启</t-button
            >
            <t-button
              theme="default"
              size="small"
              :loading="actionLoading === 'remote-status'"
              @click="handleRefreshRemoteStatus"
              >刷新远程</t-button
            >
            <t-button
              theme="default"
              size="small"
              :disabled="!actions.password_reset"
              :loading="actionLoading === 'reset-password'"
              @click="openResetPasswordDialog"
              >重置密码</t-button
            >
            <t-button theme="default" size="small" @click="openServiceUpstreamDialog">上游绑定</t-button>
            <t-button theme="default" size="small" @click="openServicePricingDialog">调价</t-button>
            <t-button theme="default" size="small" @click="openServiceNameDialog">改名称</t-button>
            <t-button
              theme="default"
              size="small"
              :disabled="!actions.manual_provision"
              :loading="actionLoading === 'manual-provision'"
              @click="openManualProvisionDialog"
              >手动开通</t-button
            >
            <t-button
              v-if="canRefundService"
              theme="danger"
              size="small"
              :loading="actionLoading === 'refund'"
              @click="openServiceRefundDialog"
              >退款</t-button
            >
            <t-tag v-else-if="isServiceRefunded" theme="danger" variant="light">已退款</t-tag>
          </div>
        </t-card>
      </template>
      <t-empty v-else-if="loadFailed" description="服务不存在或无权限查看">
        <t-button theme="primary" @click="goBack">返回服务列表</t-button>
      </t-empty>
    </t-loading>

    <t-dialog
      v-model:visible="resetPasswordVisible"
      header="重置登录密码"
      width="420px"
      :confirm-btn="{ content: '确认重置', loading: actionLoading === 'reset-password' }"
      @cancel="resetPasswordVisible = false"
      @confirm="handleResetServicePassword"
    >
      <t-form ref="resetPasswordFormRef" :data="resetPasswordForm" :rules="resetPasswordRules" label-align="top">
        <t-form-item label="新密码" name="password">
          <t-input v-model="resetPasswordForm.password" type="password" placeholder="至少 8 位" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="manualProvisionVisible"
      header="手动开通 / 关联上游"
      width="420px"
      :confirm-btn="{ content: '确认关联', loading: actionLoading === 'manual-provision' }"
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
      v-model:visible="serviceNameVisible"
      header="修改实例名称"
      width="420px"
      :confirm-btn="{ content: '保存', loading: serviceNameSubmitting }"
      @cancel="serviceNameVisible = false"
      @confirm="submitServiceName"
    >
      <t-form :data="serviceNameForm" label-align="top">
        <t-form-item label="实例名称">
          <t-input v-model="serviceNameForm.service_name" :maxlength="120" placeholder="填写便于识别的实例名称" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="serviceRefundVisible"
      header="服务退款"
      width="500px"
      :confirm-btn="{ content: '确认退款', theme: 'danger', loading: actionLoading === 'refund' }"
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
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { billingCycleLabel as billingCycleLabelOf } from '@shared/billingCycle';
import { SERVICE_STATUS_MAP, toLabelMap } from '@shared/statusConfig';
import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { supplierApi } from '@/api/supplier';
import { userApi } from '@/api/user';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminServiceDetail' });

type Row = Record<string, any>;

const route = useRoute();
const router = useRouter();

const serviceId = computed(() => Number(route.params.id || 0));
const userId = computed(() => String(route.query.user || ''));
const hasUser = computed(() => Number(userId.value) > 0);

const loading = ref(false);
const loadFailed = ref(false);
const detail = ref<Row>(normalizeServiceDetail());
const actionLoading = ref('');

const serviceUpstreamVisible = ref(false);
const serviceUpstreamLoading = ref(false);
const serviceUpstreamSubmitting = ref(false);
const servicePricingVisible = ref(false);
const servicePricingSubmitting = ref(false);
const serviceNameVisible = ref(false);
const serviceNameSubmitting = ref(false);
const resetPasswordVisible = ref(false);
const manualProvisionVisible = ref(false);
const serviceRefundVisible = ref(false);

const serviceUpstreamFormRef = ref<FormInstanceFunctions>();
const servicePricingFormRef = ref<FormInstanceFunctions>();
const resetPasswordFormRef = ref<FormInstanceFunctions>();
const manualProvisionFormRef = ref<FormInstanceFunctions>();
const serviceRefundFormRef = ref<FormInstanceFunctions>();

const serviceUpstreamForm = reactive({
  supplier_id: undefined as number | undefined,
  upstream_host_id: undefined as number | undefined,
});
const servicePricingForm = reactive({
  amount: 0,
  locked_pricing: {} as Record<
    string,
    { enabled: boolean; base_amount?: number | string | null; manual_amount?: number | null | '' }
  >,
  clear_locked_pricing: false,
});
const serviceNameForm = reactive({ service_name: '' });
const resetPasswordForm = reactive({ password: '' });
const manualProvisionForm = reactive({ upstream_host_id: undefined as number | undefined });
const serviceRefundForm = reactive({ refund_method: 'balance' as 'balance' | 'original', remark: '' });

const serviceStatusLabelMap = toLabelMap(SERVICE_STATUS_MAP);
const serviceUpstreamOptions = ref<Array<{ id: number; label: string }>>([]);

const serviceUpstreamRules: Record<string, FormRule[]> = {
  supplier_id: [{ validator: validateUpstreamPair, message: '选择上游接口时必须填写上游实例 ID', type: 'error' }],
  upstream_host_id: [{ validator: validateUpstreamPair, message: '填写上游实例 ID 时必须选择上游接口', type: 'error' }],
};
const servicePricingRules: Record<string, FormRule[]> = {
  amount: [required('请输入购买价格')],
};
const resetPasswordRules: Record<string, FormRule[]> = {
  password: [{ validator: (val) => String(val || '').length >= 8, message: '密码长度至少 8 位', type: 'error' }],
};
const manualProvisionRules: Record<string, FormRule[]> = {
  upstream_host_id: [required('请输入上游实例 ID')],
};
const refundRules: Record<string, FormRule[]> = {
  refund_method: [required('请选择退款方式')],
  remark: [required('请填写退款原因')],
};

const displayName = computed(() => {
  const d = detail.value;
  return (
    d.custom_service_name ||
    d.name ||
    d.product_display_name ||
    d.product_full_path ||
    d.product?.display_name ||
    d.domain ||
    `服务 #${serviceId.value}`
  );
});
const actions = computed(() => detail.value.actions || {});
const specs = computed(() =>
  (Array.isArray(detail.value.specs) ? detail.value.specs : []).map((item: Row) => ({
    label: item.label || item.name || '-',
    value: item.value || '-',
  })),
);
const canPowerOn = computed(() => {
  const available = actions.value.available || [];
  return Array.isArray(available) && available.includes('power:on') && detail.value.runtime?.power_state !== 'running';
});
const canPowerOff = computed(() => {
  const available = actions.value.available || [];
  return Array.isArray(available) && available.includes('power:off') && detail.value.runtime?.power_state === 'running';
});
const canReboot = computed(() => {
  const available = actions.value.available || [];
  return (
    Array.isArray(available) && available.includes('power:reboot') && detail.value.runtime?.power_state === 'running'
  );
});
const canRefundService = computed(() => {
  const status = Number(detail.value.status);
  if ([0, 5, 6].includes(status)) return false;
  const available = actions.value.available;
  return !Array.isArray(available) || available.includes('refund');
});
const isServiceRefunded = computed(() => [5, 6].includes(Number(detail.value.status)));
const canOriginalServiceRefund = computed(() => detail.value.refund?.can_original !== false);
const serviceRefundAmount = computed(
  () => detail.value.refund?.amount ?? detail.value.amount ?? detail.value.order?.amount ?? 0,
);
const servicePricingEntries = computed(() =>
  Object.entries(servicePricingForm.locked_pricing || {}).map(([cycle, item]) => ({
    cycle,
    label: billingCycleLabel(cycle),
    base_amount: item?.base_amount || null,
  })),
);

onMounted(loadDetail);

async function loadDetail() {
  if (!serviceId.value) {
    loadFailed.value = true;
    return;
  }
  loading.value = true;
  loadFailed.value = false;
  try {
    const response = await userApi.serviceDetail(userId.value, serviceId.value);
    detail.value = normalizeServiceDetail(response);
  } catch (error) {
    loadFailed.value = true;
    MessagePlugin.error(errorMessage(error, '加载实例详情失败'));
  } finally {
    loading.value = false;
  }
}

async function reloadDetail() {
  await loadDetail();
}

function goBack() {
  if (window.history.length > 1) {
    router.back();
  } else {
    router.push({ name: 'AdminServices' });
  }
}

function goUserDetail() {
  if (hasUser.value) {
    router.push({ name: 'AdminUserDetail', params: { id: userId.value } });
  }
}

function handleRefreshRemoteStatus() {
  if (!serviceId.value) return;
  actionLoading.value = 'remote-status';
  try {
    userApi
      .serviceRemoteStatus(userId.value, serviceId.value)
      .then((response) => {
        detail.value = mergeServiceDetail(detail.value, response);
        MessagePlugin.success('远程状态已刷新');
      })
      .catch((error) => {
        MessagePlugin.error(errorMessage(error, '刷新远程状态失败'));
      })
      .finally(() => {
        actionLoading.value = '';
      });
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '刷新远程状态失败'));
    actionLoading.value = '';
  }
}

function handleServicePower(action: string) {
  if (!serviceId.value || !action) return;
  const label = ({ on: '开机', off: '关机', reboot: '重启' } as Record<string, string>)[action] || action;
  const dialog = DialogPlugin.confirm({
    header: `${label}确认`,
    body: `确认对实例执行“${label}”操作？`,
    confirmBtn: `确认${label}`,
    cancelBtn: '取消',
    theme: 'warning',
    async onConfirm() {
      actionLoading.value = `power:${action}`;
      try {
        const response = await userApi.servicePower(userId.value, serviceId.value, { action });
        if (response.detail) detail.value = mergeServiceDetail(detail.value, response.detail);
        MessagePlugin.success(response.message || `${label}指令已下发`);
        dialog.hide();
        await handleRefreshRemoteStatus();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, `${label}失败`));
      } finally {
        actionLoading.value = '';
      }
    },
  });
}

function openResetPasswordDialog() {
  resetPasswordForm.password = '';
  resetPasswordVisible.value = true;
  resetPasswordFormRef.value?.clearValidate?.();
}

async function handleResetServicePassword() {
  const result = await resetPasswordFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceId.value) return;
  actionLoading.value = 'reset-password';
  try {
    await userApi.serviceResetPassword(userId.value, serviceId.value, { password: resetPasswordForm.password });
    MessagePlugin.success('密码重置指令已下发');
    resetPasswordVisible.value = false;
    await reloadDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '密码重置失败'));
  } finally {
    actionLoading.value = '';
  }
}

function openManualProvisionDialog() {
  manualProvisionForm.upstream_host_id = undefined;
  manualProvisionVisible.value = true;
  manualProvisionFormRef.value?.clearValidate?.();
}

async function handleManualProvision() {
  const result = await manualProvisionFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceId.value) return;
  actionLoading.value = 'manual-provision';
  try {
    await userApi.manualProvisionService(userId.value, serviceId.value, {
      upstream_host_id: Number(manualProvisionForm.upstream_host_id || 0),
    });
    MessagePlugin.success('手动开通指令已下发');
    manualProvisionVisible.value = false;
    await reloadDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '手动开通失败'));
  } finally {
    actionLoading.value = '';
  }
}

async function openServiceUpstreamDialog() {
  await loadServiceUpstreamOptions();
  serviceUpstreamForm.supplier_id = Number(detail.value.upstream?.supplier_id || 0) || undefined;
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
  if (!isValidationPass(result) || !serviceId.value) return;
  serviceUpstreamSubmitting.value = true;
  actionLoading.value = 'meta-update';
  try {
    const response = await userApi.updateServiceMeta(userId.value, serviceId.value, {
      supplier_id: serviceUpstreamForm.supplier_id ? Number(serviceUpstreamForm.supplier_id) : null,
      upstream_host_id: serviceUpstreamForm.upstream_host_id ? Number(serviceUpstreamForm.upstream_host_id) : null,
    });
    detail.value = mergeServiceDetail(detail.value, response);
    serviceUpstreamVisible.value = false;
    MessagePlugin.success('上游绑定已更新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新上游绑定失败'));
  } finally {
    serviceUpstreamSubmitting.value = false;
    actionLoading.value = '';
  }
}

function openServicePricingDialog() {
  servicePricingForm.amount = toNumber(detail.value.amount);
  servicePricingForm.locked_pricing = createLockedPricingForm(detail.value);
  servicePricingForm.clear_locked_pricing = false;
  servicePricingVisible.value = true;
  servicePricingFormRef.value?.clearValidate?.();
}

async function submitServicePricing() {
  const result = await servicePricingFormRef.value?.validate?.();
  if (!isValidationPass(result) || !serviceId.value) return;
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
  actionLoading.value = 'meta-update';
  try {
    const response = await userApi.updateServiceMeta(userId.value, serviceId.value, payload);
    detail.value = mergeServiceDetail(detail.value, response);
    servicePricingVisible.value = false;
    MessagePlugin.success('价格信息已更新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新价格信息失败'));
  } finally {
    servicePricingSubmitting.value = false;
    actionLoading.value = '';
  }
}

function openServiceNameDialog() {
  serviceNameForm.service_name = String(detail.value.custom_service_name || detail.value.name || '');
  serviceNameVisible.value = true;
}

async function submitServiceName() {
  if (!serviceId.value) return;
  serviceNameSubmitting.value = true;
  actionLoading.value = 'meta-update';
  try {
    const response = await userApi.updateServiceMeta(userId.value, serviceId.value, {
      service_name: serviceNameForm.service_name,
    });
    detail.value = mergeServiceDetail(detail.value, response);
    serviceNameVisible.value = false;
    MessagePlugin.success('实例名称已更新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新实例名称失败'));
  } finally {
    serviceNameSubmitting.value = false;
    actionLoading.value = '';
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
  if (!isValidationPass(result) || !serviceId.value) return;
  actionLoading.value = 'refund';
  try {
    const response = await userApi.refundService(userId.value, serviceId.value, {
      refund_method: serviceRefundForm.refund_method,
      amount: serviceRefundAmount.value,
      remark: serviceRefundForm.remark,
    });
    MessagePlugin.success(response.message || '服务已完成退款');
    serviceRefundVisible.value = false;
    await reloadDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '退款失败'));
  } finally {
    actionLoading.value = '';
  }
}

function normalizeServiceDetail(payload: Row = {}) {
  const empty = {
    id: 0,
    name: '',
    domain: '',
    status: 0,
    status_tone: 'default',
    billing_cycle: '',
    billing_cycle_label: '',
    amount: 0,
    expires_at: '',
    created_at: '',
    product: {},
    order: {},
    invoice: {},
    upstream: {},
    runtime: {},
    connection: {},
    actions: { refresh: true, password_reset: false, manual_provision: false, available: [] as string[] },
    specs: [] as Row[],
    custom_service_name: '',
  };
  return {
    ...empty,
    ...payload,
    product: { ...empty.product, ...(payload.product || {}) },
    order: { ...empty.order, ...(payload.order || {}) },
    invoice: { ...empty.invoice, ...(payload.invoice || {}) },
    upstream: { ...empty.upstream, ...(payload.upstream || {}) },
    runtime: { ...empty.runtime, ...(payload.runtime || {}) },
    connection: { ...empty.connection, ...(payload.connection || {}) },
    actions: { ...empty.actions, ...(payload.actions || {}) },
    specs: Array.isArray(payload.specs) ? payload.specs : [],
  };
}

function mergeServiceDetail(current: Row = {}, patch: Row = {}) {
  return normalizeServiceDetail({
    ...current,
    ...patch,
    product: { ...(current.product || {}), ...(patch.product || {}) },
    order: { ...(current.order || {}), ...(patch.order || {}) },
    invoice: { ...(current.invoice || {}), ...(patch.invoice || {}) },
    upstream: { ...(current.upstream || {}), ...(patch.upstream || {}) },
    runtime: { ...(current.runtime || {}), ...(patch.runtime || {}) },
    connection: { ...(current.connection || {}), ...(patch.connection || {}) },
    actions: { ...(current.actions || {}), ...(patch.actions || {}) },
  });
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

function serviceStatusLabel(status: unknown) {
  return serviceStatusLabelMap[String(status ?? '')] || '-';
}

function validateUpstreamPair() {
  const supplierId = Number(serviceUpstreamForm.supplier_id || 0);
  const hostId = Number(serviceUpstreamForm.upstream_host_id || 0);
  return (supplierId <= 0 && hostId <= 0) || (supplierId > 0 && hostId > 0);
}

function isValidationPass(result: unknown) {
  return result === true || result === undefined;
}

function toNumber(value: unknown) {
  const num = Number(value);
  return Number.isFinite(num) ? num : 0;
}
</script>
