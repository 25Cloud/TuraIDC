<template>
  <div class="ticket-delivery-page">
    <t-card class="ticket-delivery-card" :bordered="false">
      <div class="ticket-delivery-toolbar">
        <div class="ticket-delivery-summary">
          <strong>工单传递规则</strong>
          <span>按部门、供应商和产品范围决定工单是否同步到上游。</span>
        </div>
        <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
          <template #icon><add-icon /></template>
          新增规则
        </t-button>
      </div>

      <t-table
        v-if="!isMobile && (loading || rules.length > 0)"
        row-key="id"
        :data="rules"
        :columns="columns"
        :loading="loading"
        hover
        table-layout="fixed"
      >
        <template #name="{ row }">
          <div class="rule-name-cell">
            <strong>{{ row.name || '-' }}</strong>
            <span>{{ row.department || '-' }} · ZJMF 财务</span>
          </div>
        </template>
        <template #supplier="{ row }">{{ supplierName(row.supplier_id) }}</template>
        <template #scope="{ row }">
          <t-tag variant="light">{{ scopeLabel(row) }}</t-tag>
        </template>
        <template #upstreamDepartment="{ row }">{{ row.upstream_department_id || '-' }}</template>
        <template #status="{ row }">
          <t-tag :theme="isEnabled(row) ? 'success' : 'default'" variant="light">
            {{ isEnabled(row) ? '启用' : '停用' }}
          </t-tag>
        </template>
        <template #sync="{ row }">{{ isEnabledValue(row.sync_admin_replies) ? '同步' : '不同步' }}</template>
        <template #actions="{ row }">
          <t-space size="small">
            <t-button v-if="canManage" theme="primary" variant="text" @click="openEditDialog(row)">编辑</t-button>
            <t-button v-if="canManage" variant="text" @click="toggleRule(row)">
              {{ isEnabled(row) ? '停用' : '启用' }}
            </t-button>
            <t-button v-if="canManage" theme="danger" variant="text" @click="confirmDelete(row)">删除</t-button>
            <t-button v-if="!canManage" variant="text" @click="openEditDialog(row)">查看</t-button>
          </t-space>
        </template>
      </t-table>

      <div v-else class="ticket-delivery-mobile-list">
        <t-loading :loading="loading" size="small">
          <div v-if="rules.length" class="ticket-delivery-mobile-stack">
            <article v-for="row in rules" :key="row.id" class="ticket-delivery-mobile-card">
              <div class="ticket-delivery-mobile-card__head">
                <div class="rule-name-cell">
                  <strong>{{ row.name || '-' }}</strong>
                  <span>{{ row.department || '-' }}</span>
                </div>
                <t-tag :theme="isEnabled(row) ? 'success' : 'default'" variant="light">
                  {{ isEnabled(row) ? '启用' : '停用' }}
                </t-tag>
              </div>
              <dl>
                <div>
                  <dt>供应商</dt>
                  <dd>{{ supplierName(row.supplier_id) }}</dd>
                </div>
                <div>
                  <dt>产品范围</dt>
                  <dd>{{ scopeLabel(row) }}</dd>
                </div>
                <div>
                  <dt>上游部门</dt>
                  <dd>{{ row.upstream_department_id || '-' }}</dd>
                </div>
                <div>
                  <dt>管理员回复</dt>
                  <dd>{{ isEnabledValue(row.sync_admin_replies) ? '同步' : '不同步' }}</dd>
                </div>
              </dl>
              <div class="ticket-delivery-mobile-card__actions">
                <t-button size="small" variant="text" @click="openEditDialog(row)">{{
                  canManage ? '编辑' : '查看'
                }}</t-button>
                <t-button v-if="canManage" size="small" variant="text" @click="toggleRule(row)">
                  {{ isEnabled(row) ? '停用' : '启用' }}
                </t-button>
                <t-button v-if="canManage" size="small" theme="danger" variant="text" @click="confirmDelete(row)"
                  >删除</t-button
                >
              </div>
            </article>
          </div>
          <t-empty v-else description="暂无工单传递规则" />
        </t-loading>
      </div>

      <t-empty v-if="!isMobile && !loading && rules.length === 0" description="暂无工单传递规则" />
    </t-card>

    <t-dialog
      v-model:visible="dialogVisible"
      :header="editingId ? '编辑工单传递规则' : '新增工单传递规则'"
      :confirm-btn="canManage ? { content: '保存', loading: saving } : null"
      :cancel-btn="canManage ? '取消' : '关闭'"
      placement="center"
      :width="isMobile ? '94vw' : '720px'"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="formRules" label-align="top">
        <div class="ticket-delivery-form-grid">
          <t-form-item label="规则名称" name="name">
            <t-input v-model="form.name" :disabled="!canManage" maxlength="120" placeholder="例如：技术支持工单同步" />
          </t-form-item>
          <t-form-item label="工单部门" name="department">
            <t-select v-model="form.department" :disabled="!canManage" placeholder="选择工单部门">
              <t-option
                v-for="option in departmentOptions"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </t-select>
          </t-form-item>
          <t-form-item label="供应商" name="supplier_id">
            <t-select
              v-model="form.supplier_id"
              :disabled="!canManage || Boolean(editingId)"
              filterable
              placeholder="选择启用的 ZJMF 供应商"
              @change="handleSupplierChange"
            >
              <t-option
                v-for="supplier in suppliers"
                :key="supplier.id"
                :label="supplierLabel(supplier)"
                :value="supplier.id"
              />
            </t-select>
          </t-form-item>
          <t-form-item label="上游部门" name="upstream_department_id">
            <t-select
              v-model="form.upstream_department_id"
              :disabled="!canManage || !form.supplier_id || departmentsLoading"
              :loading="departmentsLoading"
              filterable
              placeholder="选择上游部门"
            >
              <t-option
                v-for="department in upstreamDepartments"
                :key="department.id"
                :label="department.description ? `${department.name} · ${department.description}` : department.name"
                :value="department.id"
              />
            </t-select>
          </t-form-item>
          <t-form-item label="产品范围" name="product_scope_mode">
            <t-radio-group v-model="form.product_scope_mode" :disabled="!canManage">
              <t-radio value="all">全部产品</t-radio>
              <t-radio value="selected">指定产品</t-radio>
            </t-radio-group>
          </t-form-item>
          <t-form-item v-if="form.product_scope_mode === 'selected'" label="指定产品" name="product_ids">
            <t-select
              v-model="form.product_ids"
              :disabled="!canManage || !form.supplier_id"
              filterable
              multiple
              placeholder="选择已绑定上游产品"
            >
              <t-option
                v-for="product in filteredProducts"
                :key="product.id"
                :label="productLabel(product)"
                :value="product.id"
              />
            </t-select>
          </t-form-item>
        </div>
        <t-form-item label="规则选项">
          <t-space direction="vertical" size="small">
            <t-checkbox v-model="form.enabled" :disabled="!canManage">启用规则</t-checkbox>
            <t-checkbox v-model="form.sync_admin_replies" :disabled="!canManage">同步管理员回复</t-checkbox>
          </t-space>
        </t-form-item>
        <t-form-item label="屏蔽关键词" name="mask_keywords">
          <t-textarea
            v-model="form.mask_keywords"
            :disabled="!canManage"
            :autosize="{ minRows: 3, maxRows: 6 }"
            maxlength="10000"
            placeholder="每行一个关键词，命中标题或首条回复时不传递"
          />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-card class="ticket-delivery-card ticket-delivery-guard-card" :bordered="false">
      <div class="ticket-delivery-toolbar">
        <div class="ticket-delivery-summary">
          <strong>上游附件上传防护</strong>
          <span
            >默认关闭
            /upload_image；开启后白名单外上传默认拒绝。上传后超过保留期仍未用于回复工单的文件会自动删除。</span
          >
        </div>
        <t-button v-if="canManage" theme="primary" variant="outline" :loading="guardSaving" @click="saveUploadGuard">
          保存配置
        </t-button>
      </div>

      <t-form
        class="ticket-delivery-guard-form"
        :label-align="isMobile ? 'top' : 'right'"
        :label-width="isMobile ? undefined : '170px'"
      >
        <t-form-item label="启用 /upload_image 接口">
          <t-switch v-model="uploadGuard.upload_image_enabled" :disabled="!canManage" />
          <span class="ticket-delivery-guard-hint">配置工单传递规则前必须先开启</span>
        </t-form-item>
        <t-form-item label="白名单 IP / CIDR">
          <t-textarea
            v-model="uploadGuard.allowed_ips"
            :disabled="!canManage"
            :autosize="{ minRows: 2, maxRows: 5 }"
            maxlength="2000"
            placeholder="每行或逗号分隔一个 IP 或 CIDR，例如 203.0.113.10、10.0.0.0/8"
          />
        </t-form-item>
        <t-form-item label="非白名单速率（次/分钟）">
          <t-input-number
            v-model="uploadGuard.rate_limit"
            :disabled="!canManage || uploadGuard.block_non_whitelisted"
            :min="0"
            :max="10000"
          />
          <span class="ticket-delivery-guard-hint">0 表示不限速（不推荐）</span>
        </t-form-item>
        <t-form-item label="拒绝白名单外上传">
          <t-switch v-model="uploadGuard.block_non_whitelisted" :disabled="!canManage" />
          <span class="ticket-delivery-guard-hint">开启后仅白名单 IP/CIDR 可上传，其余来源直接拒绝</span>
        </t-form-item>
        <t-form-item label="未使用文件保留期">
          <span>{{ uploadGuard.unused_retention_minutes }} 分钟（每分钟自动清理一次）</span>
        </t-form-item>
      </t-form>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { useWindowSize } from '@vueuse/core';
import { AddIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PrimaryTableCol, SelectValue } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

import type {
  TicketDeliveryDepartment,
  TicketDeliveryRulePayload,
  TicketDeliveryRuleRecord,
  TicketDeliveryScopeMode,
} from '@/api/admin';
import { adminApi } from '@/api/admin';
import type { ProductRecord } from '@/api/product';
import { productApi } from '@/api/product';
import type { SupplierRecord } from '@/api/supplier';
import { supplierApi } from '@/api/supplier';
import { AdminPermissions } from '@/constants/permissions';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

const PROVIDER_KEY = 'zjmf_finance_api';
const { width } = useWindowSize();
const isMobile = computed(() => width.value < 768);
const canManage = computed(() => hasAdminPermission(AdminPermissions.TICKET_MANAGE));
const loading = ref(false);
const saving = ref(false);
const guardSaving = ref(false);
const dialogVisible = ref(false);
const editingId = ref<number | string | null>(null);
const formRef = ref<FormInstanceFunctions>();
const rules = ref<TicketDeliveryRuleRecord[]>([]);
const suppliers = ref<SupplierRecord[]>([]);
const products = ref<ProductRecord[]>([]);
const supplierProducts = ref<ProductRecord[]>([]);
const upstreamDepartments = ref<TicketDeliveryDepartment[]>([]);
const departmentsLoading = ref(false);
let departmentsRequestId = 0;
let productsRequestId = 0;

const uploadGuard = reactive({
  upload_image_enabled: false,
  allowed_ips: '',
  rate_limit: 30,
  block_non_whitelisted: true,
  unused_retention_minutes: 5,
});

// 已保存生效的接口开关状态：开关表单改动未点「保存配置」前不生效，
// 新建/启停规则以该状态为准，避免未保存的本地状态绕过后端校验。
const savedUploadImageEnabled = ref(false);

const departmentOptions = [
  { label: '销售', value: 'sales' },
  { label: '技术支持', value: 'support' },
  { label: '财务', value: 'billing' },
  { label: '投诉', value: 'abuse' },
];

const form = reactive({
  name: '',
  department: '',
  supplier_id: '' as number | string,
  product_scope_mode: 'selected' as TicketDeliveryScopeMode,
  product_ids: [] as Array<number | string>,
  upstream_department_id: '',
  enabled: true,
  sync_admin_replies: false,
  mask_keywords: '',
});

const columns: PrimaryTableCol[] = [
  { title: '规则', colKey: 'name', minWidth: 220 },
  { title: '供应商', colKey: 'supplier', minWidth: 150 },
  { title: '产品范围', colKey: 'scope', minWidth: 140 },
  { title: '上游部门', colKey: 'upstreamDepartment', width: 130 },
  { title: '状态', colKey: 'status', width: 90 },
  { title: '管理员回复', colKey: 'sync', width: 110 },
  { title: '操作', colKey: 'actions', fixed: 'right', width: 220 },
];

const formRules: Record<string, FormRule[]> = {
  name: [
    { required: true, message: '请输入规则名称', type: 'error' },
    { max: 120, message: '规则名称不能超过 120 个字符', type: 'error' },
  ],
  department: [{ required: true, message: '请选择工单部门', type: 'error' }],
  supplier_id: [{ required: true, message: '请选择供应商', type: 'error' }],
  upstream_department_id: [{ required: true, message: '请选择上游部门', type: 'error' }],
  product_scope_mode: [{ required: true, message: '请选择产品范围', type: 'error' }],
  product_ids: [
    {
      validator: (value) => form.product_scope_mode !== 'selected' || (Array.isArray(value) && value.length > 0),
      message: '指定产品模式至少选择一个产品',
      type: 'error',
    },
  ],
  mask_keywords: [{ max: 10000, message: '屏蔽关键词不能超过 10000 个字符', type: 'error' }],
};

const filteredProducts = computed(() => {
  // 已按 supplier_id + provider_key 从后端过滤，无需再依赖本地 upstream_binding。
  if (!form.supplier_id) return [];
  return supplierProducts.value;
});

watch(
  () => form.product_scope_mode,
  (mode) => {
    if (mode === 'all') form.product_ids = [];
  },
);

function createDefaultForm() {
  Object.assign(form, {
    name: '',
    department: '',
    supplier_id: '',
    product_scope_mode: 'selected' as TicketDeliveryScopeMode,
    product_ids: [],
    upstream_department_id: '',
    enabled: true,
    sync_admin_replies: false,
    mask_keywords: '',
  });
  upstreamDepartments.value = [];
  productsRequestId += 1;
  supplierProducts.value = [];
}

async function loadUpstreamDepartments(supplierId: number | string, configuredId = '') {
  const requestId = ++departmentsRequestId;
  departmentsLoading.value = true;
  try {
    const response = await adminApi.tickets.deliveryDepartments(supplierId);
    if (requestId !== departmentsRequestId) return;
    const departments = response.list || [];
    if (configuredId && !departments.some((department) => String(department.id) === String(configuredId))) {
      departments.unshift({
        id: String(configuredId),
        name: `当前已配置：${configuredId}`,
        description: '上游已不再返回此部门',
      });
    }
    upstreamDepartments.value = departments;
  } catch (error) {
    if (requestId !== departmentsRequestId) return;
    upstreamDepartments.value = configuredId
      ? [{ id: String(configuredId), name: `当前已配置：${configuredId}`, description: '部门列表加载失败' }]
      : [];
    MessagePlugin.error(errorMessage(error, '加载上游部门失败'));
  } finally {
    if (requestId === departmentsRequestId) departmentsLoading.value = false;
  }
}

async function loadSupplierProducts(supplierId: number | string) {
  const requestId = ++productsRequestId;
  supplierProducts.value = [];
  try {
    // 供应商绑定产品可能超过单页上限，分页拉全量后再合并；
    // 每页返回前都校验请求序号，避免旧响应覆盖新供应商的结果。
    const products: ProductRecord[] = [];
    const pageSize = 100;
    let page = 1;
    let total = 0;

    do {
      const response = await productApi.v2List({
        page,
        page_size: pageSize,
        lifecycle_status: 'active',
        supplier_id: supplierId,
        provider_key: PROVIDER_KEY,
      });
      if (requestId !== productsRequestId) return;

      const list = response.list || [];
      products.push(...list);
      total = Number(response.total ?? products.length);
      page += 1;
      if (list.length === 0) break;
    } while (products.length < total);

    supplierProducts.value = products;
  } catch (error) {
    if (requestId !== productsRequestId) return;
    supplierProducts.value = [];
    MessagePlugin.error(errorMessage(error, '加载已绑定产品失败'));
  }
}

async function handleSupplierChange(value: SelectValue) {
  form.upstream_department_id = '';
  // 仅在管理员主动切换供应商时清空已选产品，避免首屏分页加载被误判为供应商变更而丢失已保存绑定
  form.product_ids = [];
  upstreamDepartments.value = [];
  if (value !== '' && value !== undefined && value !== null) {
    const supplierId = String(value);
    await Promise.all([loadSupplierProducts(supplierId), loadUpstreamDepartments(supplierId)]);
  }
}

function supplierLabel(supplier: SupplierRecord) {
  return `${supplier.name || `供应商 #${supplier.id}`} · ${supplier.provider_label || supplier.provider_key || '未知类型'}`;
}

function supplierName(id: unknown) {
  return suppliers.value.find((supplier) => String(supplier.id) === String(id))?.name || `供应商 #${id || '-'}`;
}

function productLabel(product: ProductRecord) {
  return product.display_name || product.name || product.product_display_name || `产品 #${product.id}`;
}

function productNames(ids: Array<number | string> = []) {
  const names = ids
    .map((id) => products.value.find((product) => String(product.id) === String(id)))
    .filter(Boolean)
    .map((product) => productLabel(product as ProductRecord));
  return names.length ? names.join('、') : '指定产品';
}

function scopeLabel(row: TicketDeliveryRuleRecord) {
  return row.product_scope_mode === 'all' ? '全部产品' : productNames(row.product_ids || []);
}

function isEnabledValue(value: unknown) {
  return value === true || Number(value) === 1;
}

function isEnabled(row: TicketDeliveryRuleRecord) {
  return isEnabledValue(row.enabled);
}

async function loadOptions() {
  const [supplierResponse, productResponse] = await Promise.all([
    supplierApi.list({ page: 1, page_size: 100, status: 1 }),
    productApi.v2List({ page: 1, page_size: 100, lifecycle_status: 'active' }),
  ]);
  suppliers.value = (supplierResponse.list || []).filter((supplier) => supplier.provider_key === PROVIDER_KEY);
  products.value = productResponse.list || [];
}

async function loadRules() {
  loading.value = true;
  try {
    const response = await adminApi.tickets.deliveryRules.list();
    rules.value = response.list || [];
  } catch (error) {
    rules.value = [];
    MessagePlugin.error(errorMessage(error, '加载工单传递规则失败'));
  } finally {
    loading.value = false;
  }
}

async function loadPage() {
  try {
    await Promise.all([loadOptions(), loadRules(), loadUploadGuard()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载工单传递设置失败'));
  }
}

async function loadUploadGuard() {
  try {
    const config = await adminApi.tickets.uploadGuard.config();
    uploadGuard.upload_image_enabled = config.upload_image_enabled === true;
    savedUploadImageEnabled.value = uploadGuard.upload_image_enabled;
    uploadGuard.allowed_ips = config.allowed_ips ?? '';
    uploadGuard.rate_limit = Number(config.rate_limit ?? 30);
    uploadGuard.block_non_whitelisted = config.block_non_whitelisted !== false;
    uploadGuard.unused_retention_minutes = Number(config.unused_retention_minutes ?? 5);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载上传防护配置失败'));
  }
}

async function saveUploadGuard() {
  if (!canManage.value) return;
  guardSaving.value = true;
  try {
    const saved = await adminApi.tickets.uploadGuard.save({
      upload_image_enabled: Boolean(uploadGuard.upload_image_enabled),
      allowed_ips: uploadGuard.allowed_ips,
      rate_limit: Number(uploadGuard.rate_limit),
      block_non_whitelisted: Boolean(uploadGuard.block_non_whitelisted),
    });
    uploadGuard.upload_image_enabled = saved.upload_image_enabled === true;
    savedUploadImageEnabled.value = uploadGuard.upload_image_enabled;
    uploadGuard.allowed_ips = saved.allowed_ips ?? '';
    uploadGuard.rate_limit = Number(saved.rate_limit ?? 0);
    uploadGuard.block_non_whitelisted = saved.block_non_whitelisted !== false;
    MessagePlugin.success('上传防护配置已保存');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存上传防护配置失败'));
  } finally {
    guardSaving.value = false;
  }
}

function openCreateDialog() {
  if (!canManage.value || !savedUploadImageEnabled.value) {
    if (canManage.value) MessagePlugin.warning('请先启用 /upload_image 接口');
    return;
  }
  editingId.value = null;
  createDefaultForm();
  dialogVisible.value = true;
}

function openEditDialog(row: TicketDeliveryRuleRecord) {
  editingId.value = row.id;
  Object.assign(form, {
    name: row.name || '',
    department: row.department || '',
    supplier_id: row.supplier_id || '',
    product_scope_mode: row.product_scope_mode || 'selected',
    product_ids: [...(row.product_ids || [])],
    upstream_department_id: row.upstream_department_id || '',
    enabled: isEnabledValue(row.enabled),
    sync_admin_replies: isEnabledValue(row.sync_admin_replies),
    mask_keywords: row.mask_keywords || '',
  });
  dialogVisible.value = true;
  const supplierId = String(form.supplier_id);
  void Promise.all([
    loadSupplierProducts(supplierId),
    loadUpstreamDepartments(supplierId, String(form.upstream_department_id)),
  ]);
}

function buildPayload(): TicketDeliveryRulePayload {
  return {
    name: form.name.trim(),
    department: form.department,
    supplier_id: form.supplier_id,
    provider_key: PROVIDER_KEY,
    product_scope_mode: form.product_scope_mode,
    product_ids: form.product_scope_mode === 'selected' ? [...form.product_ids] : [],
    upstream_department_id: form.upstream_department_id.trim(),
    enabled: form.enabled,
    sync_admin_replies: form.sync_admin_replies,
    mask_keywords: form.mask_keywords.trim(),
  };
}

async function submitForm() {
  if (!canManage.value) return;
  if (!savedUploadImageEnabled.value) {
    MessagePlugin.warning('请先启用 /upload_image 接口');
    return;
  }
  const result = await formRef.value?.validate();
  if (result !== true) return;
  saving.value = true;
  try {
    const payload = buildPayload();
    if (editingId.value) {
      await adminApi.tickets.deliveryRules.update(editingId.value, payload);
      MessagePlugin.success('工单传递规则已更新');
    } else {
      await adminApi.tickets.deliveryRules.create(payload);
      MessagePlugin.success('工单传递规则已创建');
    }
    dialogVisible.value = false;
    await loadRules();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存工单传递规则失败'));
  } finally {
    saving.value = false;
  }
}

async function toggleRule(row: TicketDeliveryRuleRecord) {
  if (!canManage.value) return;
  if (!savedUploadImageEnabled.value) {
    MessagePlugin.warning('请先启用 /upload_image 接口');
    return;
  }
  try {
    await adminApi.tickets.deliveryRules.update(row.id, {
      name: row.name || '',
      department: row.department || '',
      supplier_id: row.supplier_id || '',
      provider_key: PROVIDER_KEY,
      product_scope_mode: row.product_scope_mode || 'selected',
      product_ids: row.product_ids || [],
      upstream_department_id: row.upstream_department_id || '',
      enabled: !isEnabled(row),
      sync_admin_replies: isEnabledValue(row.sync_admin_replies),
      mask_keywords: row.mask_keywords || '',
    });
    MessagePlugin.success(isEnabled(row) ? '规则已停用' : '规则已启用');
    await loadRules();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新规则状态失败'));
  }
}

function confirmDelete(row: TicketDeliveryRuleRecord) {
  if (!canManage.value) return;
  const dialog = DialogPlugin.confirm({
    header: '删除工单传递规则',
    body: `确认删除规则「${row.name || row.id}」？删除后无法恢复。`,
    confirmBtn: { content: '删除', theme: 'danger' },
    cancelBtn: '取消',
    onConfirm: async () => {
      try {
        await adminApi.tickets.deliveryRules.delete(row.id);
        MessagePlugin.success('工单传递规则已删除');
        await loadRules();
        dialog.destroy();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除工单传递规则失败'));
      }
    },
  });
}

onMounted(loadPage);
</script>
