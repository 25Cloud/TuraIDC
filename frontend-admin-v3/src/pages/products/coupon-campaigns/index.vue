<template>
  <div class="coupon-campaigns-page">
    <t-card :bordered="false">
      <div class="campaigns-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          placeholder="搜索活动名称 / 描述 / 备注"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.status" clearable placeholder="状态" @change="handleSearch">
          <t-option value="1" label="运行中" />
          <t-option value="0" label="已停用" />
        </t-select>
        <t-button theme="primary" :disabled="!canManage" @click="openCampaignDialog()">
          <template #icon><add-icon /></template>
          新增活动
        </t-button>
      </div>

      <div v-if="!isMobile" class="table-scroll">
        <t-table row-key="id" :data="campaigns" :columns="columns" :loading="loading" hover table-layout="fixed">
          <template #campaign="{ row }">
            <div class="campaign-main">
              <div class="campaign-title-row">
                <strong>{{ row.name || '-' }}</strong>
                <t-tag size="small" variant="light">自动发放</t-tag>
                <t-tag v-if="Number(row.generated_coupon_count || 0) > 0" size="small" theme="default" variant="light"
                  >已生成</t-tag
                >
              </div>
              <span>{{ row.description || '暂无描述' }}</span>
            </div>
          </template>

          <template #schedule="{ row }">
            <div class="campaign-meta">
              <strong>{{ row.schedule_text || scheduleText(row) }}</strong>
              <span>下次执行：{{ row.next_run_at || '未配置' }}</span>
              <span>{{
                row.valid_duration_hours ? `生成后 ${row.valid_duration_hours} 小时失效` : '生成后长期有效'
              }}</span>
            </div>
          </template>

          <template #rule="{ row }">
            <div class="campaign-meta">
              <div class="campaign-title-row">
                <t-tag size="small" :theme="row.discount_type === 'fixed' ? 'danger' : 'warning'" variant="light">
                  {{ row.discount_type_label || discountTypeLabel(row.discount_type) }}
                </t-tag>
                <t-tag size="small" theme="primary" variant="light">
                  {{ row.discount_scope_label || discountScopeLabel(row.discount_scope) }}
                </t-tag>
              </div>
              <strong>{{ row.discount_label || formatDiscount(row) }}</strong>
              <span>发放数量：{{ row.issue_quantity || 0 }} 张</span>
              <span>最低消费：{{ moneyText(row.min_amount) }}</span>
              <span>{{ row.per_user_limit ? `每人可用 ${row.per_user_limit} 次` : '每人不限使用次数' }}</span>
            </div>
          </template>

          <template #latest="{ row }">
            <div class="campaign-meta">
              <span>{{ row.last_dispatched_at || '暂未发放' }}</span>
              <span v-if="row.last_coupon_name">最近批次：{{ row.last_coupon_name }}</span>
              <span v-if="row.last_coupon_code">券码：{{ row.last_coupon_code }}</span>
              <span>累计生成 {{ row.generated_coupon_count || 0 }} 批</span>
            </div>
          </template>

          <template #status="{ row }">
            <t-tag
              :theme="Number(row.status) === 1 || row.display_status === 'active' ? 'success' : 'default'"
              variant="light"
            >
              {{ row.display_status_label || (Number(row.status) === 1 ? '运行中' : '已停用') }}
            </t-tag>
          </template>

          <template #updatedAt="{ row }">{{ formatDateTime(row.updated_at) }}</template>

          <template #operation="{ row }">
            <t-space v-if="!isMobile" size="small">
              <t-button
                size="small"
                variant="text"
                theme="primary"
                :disabled="campaignEditDisabled(row)"
                @click="openCampaignDialog(row)"
              >
                编辑
              </t-button>
              <t-button
                size="small"
                variant="text"
                theme="success"
                :loading="isRowRunning(row.id, 'trigger')"
                :disabled="!canManage || Number(row.status) !== 1 || isRowBusy(row.id)"
                @click="handleTrigger(row)"
              >
                立即发放
              </t-button>
              <t-button
                size="small"
                variant="text"
                :loading="isRowRunning(row.id, 'toggle')"
                :disabled="!canManage || isRowBusy(row.id)"
                @click="handleToggleStatus(row)"
              >
                {{ Number(row.status) === 1 ? '停用' : '启用' }}
              </t-button>
              <t-button
                size="small"
                variant="text"
                theme="danger"
                :loading="isRowRunning(row.id, 'delete')"
                :disabled="campaignDeleteDisabled(row)"
                @click="handleDelete(row)"
              >
                删除
              </t-button>
            </t-space>
            <t-dropdown
              v-else
              :options="mobileActionOptions(row)"
              @click="(data: { value: unknown }) => handleMobileAction(data.value, row)"
            >
              <t-button size="small" variant="text">更多</t-button>
            </t-dropdown>
          </template>
        </t-table>
      </div>

      <div v-else class="campaign-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="campaigns.length" class="campaign-mobile-stack">
            <mobile-record-card
              v-for="row in campaigns"
              :key="row.id"
              :title="row.name || '-'"
              subtitle="自动发放"
              :description="row.description || ''"
              :status-label="row.display_status_label || (Number(row.status) === 1 ? '运行中' : '已停用')"
              :status-theme="Number(row.status) === 1 || row.display_status === 'active' ? 'success' : 'default'"
              :rows="campaignMobileRows(row)"
              :action-options="mobileActionOptions(row)"
              @action="handleCampaignCardAction(row, $event)"
            />
          </div>
          <t-empty v-else-if="!loading" description="暂无活动券" />
        </t-loading>
      </div>

      <div v-if="total > 0" class="pagination-row">
        <t-pagination
          :current="page"
          :page-size="pageSize"
          :total="total"
          :page-size-options="[20, 50, 100]"
          show-jumper
          @change="handlePageChange"
        />
      </div>
    </t-card>

    <t-drawer
      v-model:visible="dialogVisible"
      :header="form.id ? '编辑优惠券活动' : '新增优惠券活动'"
      size="820px"
      class="campaign-edit-drawer"
      :close-on-overlay-click="false"
      :footer="false"
      @close="handleDialogClosed"
    >
      <div class="campaign-drawer-shell">
        <t-form ref="formRef" class="campaign-drawer-form" :data="form" :rules="formRules" label-align="top">
          <section class="campaign-drawer-section" data-title="基础信息">
            <div class="campaign-form-grid">
              <t-form-item label="活动名称" name="name">
                <t-input v-model="form.name" maxlength="120" placeholder="例如：周五特惠" />
              </t-form-item>
              <t-form-item label="发放星期" name="weekdays">
                <t-select v-model="form.weekdays" multiple placeholder="至少选择一个星期">
                  <t-option v-for="item in weekdayOptions" :key="item.value" :label="item.label" :value="item.value" />
                </t-select>
              </t-form-item>
              <t-form-item label="发放时间" name="trigger_time">
                <t-time-picker v-model="form.trigger_time" clearable format="HH:mm:ss" placeholder="请选择发放时间" />
              </t-form-item>
              <t-form-item label="每批发放数量" name="issue_quantity">
                <t-input-number v-model="form.issue_quantity" :min="1" />
              </t-form-item>
              <t-form-item label="状态" name="status">
                <div class="campaign-switch-line">
                  <span>停用</span>
                  <t-switch v-model="form.status" :custom-value="[1, 0]" />
                  <span>启用</span>
                </div>
              </t-form-item>
              <t-form-item label="排序值" name="sort_order">
                <t-input-number v-model="form.sort_order" :min="0" />
              </t-form-item>
            </div>
          </section>

          <section class="campaign-drawer-section" data-title="优惠规则">
            <div class="campaign-form-grid">
              <t-form-item label="优惠类型" name="discount_type">
                <t-select v-model="form.discount_type">
                  <t-option value="fixed" label="满减券" />
                  <t-option value="percentage" label="折扣券" />
                </t-select>
              </t-form-item>
              <t-form-item label="优惠阶段" name="discount_scope">
                <t-select v-model="form.discount_scope">
                  <t-option value="first_month" label="首月优惠" />
                  <t-option value="recurring" label="持续优惠" />
                  <t-option value="renew" label="续费优惠" />
                </t-select>
              </t-form-item>
              <t-form-item
                :label="form.discount_type === 'percentage' ? '优惠值（百分比）' : '优惠金额'"
                name="discount_value"
              >
                <t-input-number
                  v-model="form.discount_value"
                  :min="0"
                  :max="form.discount_type === 'percentage' ? 100 : 999999999"
                />
              </t-form-item>
              <t-form-item label="最低消费金额" name="min_amount">
                <t-input-number v-model="form.min_amount" :min="0" :decimal-places="2" />
              </t-form-item>
              <t-form-item label="最高优惠金额" name="max_discount_amount">
                <t-input-number v-model="form.max_discount_amount" :min="0" :decimal-places="2" />
              </t-form-item>
              <t-form-item label="有效时长（小时）" name="valid_duration_hours">
                <t-input-number v-model="form.valid_duration_hours" :min="1" />
              </t-form-item>
              <t-form-item label="每人可用次数" name="per_user_limit">
                <t-input-number v-model="form.per_user_limit" :min="1" />
              </t-form-item>
              <t-form-item label="仅限首单可用" name="first_order_only">
                <t-switch v-model="form.first_order_only" />
              </t-form-item>
            </div>
          </section>

          <section class="campaign-drawer-section" data-title="时间与范围">
            <div class="campaign-form-grid">
              <t-form-item label="适用计费周期" name="billing_cycles" class="form-span-2">
                <t-select v-model="form.billing_cycles" multiple clearable placeholder="留空表示全部周期可用">
                  <t-option
                    v-for="item in billingCycleOptions"
                    :key="item.value"
                    :label="item.label"
                    :value="item.value"
                  />
                </t-select>
              </t-form-item>
              <t-form-item label="适用商品" name="product_ids" class="form-span-2">
                <product-binding-tree-select
                  v-model="form.product_ids"
                  mode="batch"
                  placeholder="按分类批量选择适用商品，留空表示全站商品可用"
                />
              </t-form-item>
            </div>
          </section>

          <section class="campaign-drawer-section" data-title="备注">
            <div class="campaign-form-grid">
              <t-form-item label="描述" name="description" class="form-span-2">
                <t-textarea v-model="form.description" :autosize="{ minRows: 3, maxRows: 5 }" maxlength="255" />
              </t-form-item>
              <t-form-item label="后台备注" name="remark" class="form-span-2">
                <t-textarea v-model="form.remark" :autosize="{ minRows: 3, maxRows: 5 }" maxlength="255" />
              </t-form-item>
            </div>
          </section>
        </t-form>

        <div class="campaign-drawer-footer">
          <t-button variant="outline" @click="closeCampaignDrawer">取消</t-button>
          <t-button theme="primary" :loading="saving" @click="submitForm">保存更改</t-button>
        </div>
      </div>
    </t-drawer>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { AddIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { CouponCampaignPayload, CouponCampaignRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { formatDateTime } from '@/utils/format';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

interface CampaignForm {
  id: number | null;
  name: string;
  weekdays: number[];
  trigger_time: string;
  issue_quantity: number;
  valid_duration_hours: number | null;
  discount_type: string;
  discount_scope: string;
  discount_value: number;
  min_amount: number;
  max_discount_amount: number | null;
  billing_cycles: string[];
  product_ids: number[];
  first_order_only: boolean;
  per_user_limit: number | null;
  status: number;
  sort_order: number;
  description: string;
  remark: string;
}

const billingCycleOptions = [
  { label: '月付', value: 'monthly' },
  { label: '季付', value: 'quarterly' },
  { label: '半年付', value: 'semiannually' },
  { label: '年付', value: 'annually' },
];

const weekdayOptions = [
  { label: '周一', value: 1 },
  { label: '周二', value: 2 },
  { label: '周三', value: 3 },
  { label: '周四', value: 4 },
  { label: '周五', value: 5 },
  { label: '周六', value: 6 },
  { label: '周日', value: 0 },
];

const loading = ref(false);
const saving = ref(false);
const dialogVisible = ref(false);
const formRef = ref<FormInstanceFunctions>();
const campaigns = ref<CouponCampaignRecord[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const rowActionState = reactive<Record<string, string>>({});
const isMobile = useMediaQuery('(max-width: 768px)');
const canManage = computed(() => hasAdminPermission(AdminPermissions.PRODUCT_MANAGE));

const filters = reactive({
  keyword: '',
  status: '',
});

const form = reactive<CampaignForm>(createDefaultForm());

const formRules: Record<string, FormRule[]> = {
  name: [{ required: true, message: '请输入活动名称', type: 'error' }],
  weekdays: [{ required: true, message: '至少选择一个发放星期', type: 'error' }],
  trigger_time: [{ required: true, message: '请输入发放时间', type: 'error' }],
  issue_quantity: [{ required: true, message: '请输入发放数量', type: 'error' }],
  discount_type: [{ required: true, message: '请选择优惠类型', type: 'error' }],
  discount_scope: [{ required: true, message: '请选择优惠阶段', type: 'error' }],
  discount_value: [{ required: true, message: '请输入优惠值', type: 'error' }],
};

const columns: PrimaryTableCol<CouponCampaignRecord>[] = [
  { colKey: 'id', title: 'ID', width: 72 },
  { colKey: 'campaign', title: '活动信息', minWidth: 260 },
  { colKey: 'schedule', title: '调度规则', minWidth: 220 },
  { colKey: 'rule', title: '优惠规则', minWidth: 220 },
  { colKey: 'latest', title: '最近发放', minWidth: 220 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'updatedAt', title: '更新时间', minWidth: 170 },
  { colKey: 'operation', title: '操作', width: 210, fixed: 'right' },
];

function createDefaultForm(): CampaignForm {
  return {
    id: null,
    name: '',
    weekdays: [5],
    trigger_time: '18:00:00',
    issue_quantity: 20,
    valid_duration_hours: 48,
    discount_type: 'percentage',
    discount_scope: 'first_month',
    discount_value: 80,
    min_amount: 0,
    max_discount_amount: null,
    billing_cycles: [],
    product_ids: [],
    first_order_only: false,
    per_user_limit: 1,
    status: 1,
    sort_order: 0,
    description: '',
    remark: '',
  };
}

function resetForm() {
  Object.assign(form, createDefaultForm());
}

function handleDialogClosed() {
  formRef.value?.clearValidate?.();
  resetForm();
}

function closeCampaignDrawer() {
  dialogVisible.value = false;
  handleDialogClosed();
}

function campaignEditDisabled(row: CouponCampaignRecord) {
  return !canManage.value || isRowBusy(row.id) || row.can_update === false;
}

function campaignDeleteDisabled(row: CouponCampaignRecord) {
  return !canManage.value || isRowBusy(row.id) || row.can_delete === false;
}

function campaignLockReason(row: CouponCampaignRecord) {
  return String(row.lock_reason || '活动已生成优惠券批次，不允许删除；编辑仅影响后续批次');
}

async function loadList() {
  loading.value = true;
  try {
    const response = await adminApi.couponCampaigns.list({
      ...filters,
      page: page.value,
      page_size: pageSize.value,
    });
    campaigns.value = response.list || [];
    total.value = Number(response.total || 0);
  } catch (error) {
    campaigns.value = [];
    total.value = 0;
    MessagePlugin.error(errorMessage(error, '加载活动列表失败'));
  } finally {
    loading.value = false;
  }
}

async function loadData() {
  await loadList();
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handlePageChange(data: { current: number; pageSize: number }) {
  page.value = data.current;
  pageSize.value = data.pageSize;
  loadList();
}

async function openCampaignDialog(row?: CouponCampaignRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券活动的权限');
    return;
  }
  resetForm();
  if (row) {
    form.id = Number(row.id);
    form.name = String(row.name || '');
    form.weekdays = Array.isArray(row.weekdays) ? row.weekdays.map(Number) : [5];
    form.trigger_time = String(row.trigger_time || '18:00:00');
    form.issue_quantity = Number(row.issue_quantity || 1);
    form.valid_duration_hours =
      row.valid_duration_hours === null || row.valid_duration_hours === undefined
        ? null
        : Number(row.valid_duration_hours || 0);
    form.discount_type = String(row.discount_type || 'fixed');
    form.discount_scope = String(row.discount_scope || 'first_month');
    form.discount_value = Number(row.discount_value_raw ?? row.discount_value ?? 0);
    form.min_amount = Number(row.min_amount_raw ?? row.min_amount ?? 0);
    form.max_discount_amount =
      row.max_discount_amount_raw === null || row.max_discount_amount_raw === undefined
        ? null
        : Number(row.max_discount_amount_raw || 0);
    form.billing_cycles = Array.isArray(row.billing_cycles) ? [...row.billing_cycles] : [];
    form.product_ids = Array.isArray(row.product_ids) ? row.product_ids.map(Number).filter(Boolean) : [];
    form.first_order_only = Boolean(row.first_order_only);
    form.per_user_limit =
      row.per_user_limit === null || row.per_user_limit === undefined ? null : Number(row.per_user_limit || 0);
    form.status = Number(row.status ?? 1);
    form.sort_order = Number(row.sort_order || 0);
    form.description = String(row.description || '');
    form.remark = String(row.remark || '');
  }
  dialogVisible.value = true;
}

function buildPayload(): CouponCampaignPayload {
  return {
    name: form.name.trim(),
    description: form.description.trim() || null,
    weekdays: form.weekdays.map(Number),
    trigger_time: form.trigger_time || null,
    issue_quantity: Number(form.issue_quantity || 0),
    valid_duration_hours: form.valid_duration_hours === null ? null : Number(form.valid_duration_hours || 0),
    discount_type: form.discount_type,
    discount_scope: form.discount_scope,
    discount_value: Number(form.discount_value || 0),
    min_amount: Number(form.min_amount || 0),
    max_discount_amount: form.max_discount_amount === null ? null : Number(form.max_discount_amount || 0),
    billing_cycles: form.billing_cycles,
    product_ids: form.product_ids.map(Number).filter((id) => id > 0),
    first_order_only: Boolean(form.first_order_only),
    per_user_limit: form.per_user_limit === null ? null : Number(form.per_user_limit || 0),
    status: Number(form.status ?? 1),
    sort_order: Number(form.sort_order || 0),
    remark: form.remark.trim() || null,
  };
}

async function submitForm() {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券活动的权限');
    return;
  }
  const validateResult = await formRef.value?.validate?.();
  if (validateResult !== true) return;
  if (!form.weekdays.length) {
    MessagePlugin.warning('至少选择一个发放星期');
    return;
  }
  if (Number(form.discount_value || 0) <= 0) {
    MessagePlugin.warning('优惠值必须大于 0');
    return;
  }
  if (form.discount_type === 'percentage' && Number(form.discount_value || 0) > 100) {
    MessagePlugin.warning('折扣值不能大于 100');
    return;
  }
  saving.value = true;
  try {
    const payload = buildPayload();
    if (form.id) {
      await adminApi.couponCampaigns.update(form.id, payload);
      MessagePlugin.success('活动已更新');
    } else {
      await adminApi.couponCampaigns.create(payload);
      MessagePlugin.success('活动已创建');
    }
    dialogVisible.value = false;
    await loadData();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存活动失败'));
  } finally {
    saving.value = false;
  }
}

function rowKey(rowId: number | string) {
  return String(rowId || '');
}

function isRowRunning(rowId: number | string, action: string) {
  return rowActionState[rowKey(rowId)] === action;
}

function isRowBusy(rowId: number | string) {
  return Boolean(rowActionState[rowKey(rowId)]);
}

async function runRowAction(
  row: CouponCampaignRecord,
  action: string,
  fallbackMessage: string,
  task: () => Promise<void>,
) {
  const key = rowKey(row.id);
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券活动的权限');
    return;
  }
  if (!key || isRowBusy(row.id)) return;
  rowActionState[key] = action;
  try {
    await task();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, fallbackMessage));
  } finally {
    if (rowActionState[key] === action) delete rowActionState[key];
  }
}

function handleTrigger(row: CouponCampaignRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券活动的权限');
    return;
  }
  const dialog = DialogPlugin.confirm({
    header: '立即发放',
    body: `确认立即发放活动「${row.name || row.id}」的新一批优惠券吗？`,
    theme: 'warning',
    confirmBtn: '确认发放',
    cancelBtn: '取消',
    async onConfirm() {
      await runRowAction(row, 'trigger', '发放活动批次失败', async () => {
        await adminApi.couponCampaigns.trigger(row.id);
        MessagePlugin.success('活动批次已发放');
        dialog.hide();
        await loadData();
      });
    },
  });
}

async function handleToggleStatus(row: CouponCampaignRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券活动的权限');
    return;
  }
  await runRowAction(row, 'toggle', '更新活动状态失败', async () => {
    await adminApi.couponCampaigns.toggleStatus(row.id, Number(row.status) !== 1);
    MessagePlugin.success('活动状态已更新');
    await loadData();
  });
}

function handleDelete(row: CouponCampaignRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券活动的权限');
    return;
  }
  if (row.can_delete === false) {
    MessagePlugin.warning(campaignLockReason(row));
    return;
  }
  const dialog = DialogPlugin.confirm({
    header: '删除活动',
    body: `确认删除活动「${row.name || row.id}」吗？仅未生成批次的活动可删除。`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    async onConfirm() {
      await runRowAction(row, 'delete', '删除活动失败', async () => {
        await adminApi.couponCampaigns.delete(row.id);
        MessagePlugin.success('活动已删除');
        dialog.hide();
        await loadData();
      });
    },
  });
}

function mobileActionOptions(row: CouponCampaignRecord) {
  return [
    { content: '编辑', value: 'edit', disabled: campaignEditDisabled(row) },
    {
      content: '立即发放',
      value: 'trigger',
      disabled: !canManage.value || Number(row.status) !== 1 || isRowBusy(row.id),
    },
    {
      content: Number(row.status) === 1 ? '停用' : '启用',
      value: 'toggle',
      disabled: !canManage.value || isRowBusy(row.id),
    },
    { content: '删除', value: 'delete', disabled: campaignDeleteDisabled(row) },
  ];
}

function campaignMobileRows(row: CouponCampaignRecord) {
  return [
    { label: '调度', value: row.schedule_text || scheduleText(row), strong: true },
    { label: '优惠', value: row.discount_label || formatDiscount(row), strong: true },
    {
      label: '类型',
      value: `${row.discount_type_label || discountTypeLabel(row.discount_type)} / ${
        row.discount_scope_label || discountScopeLabel(row.discount_scope)
      }`,
    },
    { label: '数量', value: `${row.issue_quantity || 0} 张` },
    { label: '下次', value: row.next_run_at || '未配置' },
    { label: '生成', value: `${row.generated_coupon_count || 0} 批` },
    { label: '锁定', value: campaignLockReason(row), show: row.can_update === false || row.can_delete === false },
  ];
}

function handleCampaignCardAction(row: CouponCampaignRecord, action: unknown) {
  handleMobileAction(action, row);
}

function handleMobileAction(action: unknown, row: CouponCampaignRecord) {
  if (action === 'edit') openCampaignDialog(row);
  if (action === 'trigger') handleTrigger(row);
  if (action === 'toggle') handleToggleStatus(row);
  if (action === 'delete') handleDelete(row);
}

function discountTypeLabel(value: unknown) {
  return value === 'percentage' ? '折扣券' : '满减券';
}

function discountScopeLabel(value: unknown) {
  const map: Record<string, string> = { first_month: '首月优惠', recurring: '持续优惠', renew: '续费优惠' };
  return map[String(value || '')] || '-';
}

function formatDiscount(row: CouponCampaignRecord) {
  const value = Number(row.discount_value_raw ?? row.discount_value ?? 0);
  return row.discount_type === 'percentage' ? `${value}%` : moneyText(value);
}

function moneyText(value: unknown) {
  return `¥${Number(value || 0).toFixed(2)}`;
}

function scheduleText(row: CouponCampaignRecord) {
  const weekdays = Array.isArray(row.weekdays) ? row.weekdays : [];
  const map = new Map(weekdayOptions.map((item) => [item.value, item.label]));
  const dayText = weekdays.length ? weekdays.map((item) => map.get(Number(item)) || item).join('、') : '未配置星期';
  return `${dayText} ${row.trigger_time || '未配置时间'}`;
}

onMounted(async () => {
  await loadData();
});
</script>
