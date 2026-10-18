<template>
  <div class="ticket-delivery-page">
    <t-card class="ticket-delivery-card" :bordered="false">
      <div class="ticket-delivery-toolbar">
        <div class="ticket-delivery-summary">
          <strong>{{ $t('tickets.deliverySettings.title') }}</strong>
          <span>{{ $t('tickets.deliverySettings.titleTip') }}</span>
        </div>
        <t-button v-if="canManage" theme="primary" @click="openCreateDialog">
          <template #icon><add-icon /></template>
          {{ $t('tickets.deliverySettings.addRule') }}
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
            <span>{{ row.department || '-' }} · {{ $t('tickets.deliverySettings.zjmfFinance') }}</span>
          </div>
        </template>
        <template #supplier="{ row }">{{ supplierName(row.supplier_id) }}</template>
        <template #scope="{ row }">
          <t-tag variant="light">{{ scopeLabel(row) }}</t-tag>
        </template>
        <template #upstreamDepartment="{ row }">{{ row.upstream_department_id || '-' }}</template>
        <template #status="{ row }">
          <t-tag :theme="isEnabled(row) ? 'success' : 'default'" variant="light">
            {{ isEnabled(row) ? $t('tickets.deliverySettings.enabled') : $t('tickets.deliverySettings.disabled') }}
          </t-tag>
        </template>
        <template #sync="{ row }">{{ isEnabledValue(row.sync_admin_replies) ? $t('tickets.deliverySettings.sync') : $t('tickets.deliverySettings.notSync') }}</template>
        <template #actions="{ row }">
          <t-space size="small">
            <t-button v-if="canManage" theme="primary" variant="text" @click="openEditDialog(row)">{{ $t('tickets.deliverySettings.edit') }}</t-button>
            <t-button v-if="canManage" variant="text" @click="toggleRule(row)">
              {{ isEnabled(row) ? $t('tickets.deliverySettings.disabled') : $t('tickets.deliverySettings.enabled') }}
            </t-button>
            <t-button v-if="canManage" theme="danger" variant="text" @click="confirmDelete(row)">{{ $t('tickets.deliverySettings.delete') }}</t-button>
            <t-button v-if="!canManage" variant="text" @click="openEditDialog(row)">{{ $t('tickets.deliverySettings.view') }}</t-button>
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
                  {{ isEnabled(row) ? $t('tickets.deliverySettings.enabled') : $t('tickets.deliverySettings.disabled') }}
                </t-tag>
              </div>
              <dl>
                <div>
                  <dt>{{ $t('tickets.deliverySettings.supplier') }}</dt>
                  <dd>{{ supplierName(row.supplier_id) }}</dd>
                </div>
                <div>
                  <dt>{{ $t('tickets.deliverySettings.productScope') }}</dt>
                  <dd>{{ scopeLabel(row) }}</dd>
                </div>
                <div>
                  <dt>{{ $t('tickets.deliverySettings.upstreamDepartment') }}</dt>
                  <dd>{{ row.upstream_department_id || '-' }}</dd>
                </div>
                <div>
                  <dt>{{ $t('tickets.deliverySettings.adminReply') }}</dt>
                  <dd>{{ isEnabledValue(row.sync_admin_replies) ? $t('tickets.deliverySettings.sync') : $t('tickets.deliverySettings.notSync') }}</dd>
                </div>
              </dl>
              <div class="ticket-delivery-mobile-card__actions">
                <t-button size="small" variant="text" @click="openEditDialog(row)">{{
                  canManage ? $t('tickets.deliverySettings.edit') : $t('tickets.deliverySettings.view')
                }}</t-button>
                <t-button v-if="canManage" size="small" variant="text" @click="toggleRule(row)">
                  {{ isEnabled(row) ? $t('tickets.deliverySettings.disabled') : $t('tickets.deliverySettings.enabled') }}
                </t-button>
                <t-button v-if="canManage" size="small" theme="danger" variant="text" @click="confirmDelete(row)"
                  >{{ $t('tickets.deliverySettings.delete') }}</t-button
                >
              </div>
            </article>
          </div>
          <t-empty v-else :description="$t('tickets.deliverySettings.empty')" />
        </t-loading>
      </div>

      <t-empty
        v-if="!isMobile && !loading && rules.length === 0"
        :description="$t('tickets.deliverySettings.empty')"
      />
    </t-card>

    <t-dialog
      v-model:visible="dialogVisible"
      :header="editingId ? $t('tickets.deliverySettings.editRuleTitle') : $t('tickets.deliverySettings.addRuleTitle')"
      :confirm-btn="canManage ? { content: $t('tickets.deliverySettings.save'), loading: saving } : null"
      :cancel-btn="canManage ? $t('tickets.deliverySettings.cancel') : $t('tickets.deliverySettings.close')"
      placement="center"
      :width="isMobile ? '94vw' : '720px'"
      @confirm="submitForm"
    >
      <t-form ref="formRef" :data="form" :rules="formRules" label-align="top">
        <div class="ticket-delivery-form-grid">
          <t-form-item :label="$t('tickets.deliverySettings.ruleName')" name="name">
            <t-input
              v-model="form.name"
              :disabled="!canManage"
              maxlength="120"
              :placeholder="$t('tickets.deliverySettings.ruleNamePlaceholder')"
            />
          </t-form-item>
          <t-form-item :label="$t('tickets.deliverySettings.ticketDepartment')" name="department">
            <t-select
              v-model="form.department"
              :disabled="!canManage"
              :placeholder="$t('tickets.deliverySettings.ticketDepartmentPlaceholder')"
            >
              <t-option
                v-for="option in departmentOptions"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </t-select>
          </t-form-item>
          <t-form-item :label="$t('tickets.deliverySettings.supplier')" name="supplier_id">
            <t-select
              v-model="form.supplier_id"
              :disabled="!canManage || Boolean(editingId)"
              filterable
              :placeholder="$t('tickets.deliverySettings.supplierPlaceholder')"
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
          <t-form-item :label="$t('tickets.deliverySettings.upstreamDepartment')" name="upstream_department_id">
            <t-select
              v-model="form.upstream_department_id"
              :disabled="!canManage || !form.supplier_id || departmentsLoading"
              :loading="departmentsLoading"
              filterable
              :placeholder="$t('tickets.deliverySettings.upstreamDepartmentPlaceholder')"
            >
              <t-option
                v-for="department in upstreamDepartments"
                :key="department.id"
                :label="department.description ? `${department.name} · ${department.description}` : department.name"
                :value="department.id"
              />
            </t-select>
          </t-form-item>
          <t-form-item :label="$t('tickets.deliverySettings.productScope')" name="product_scope_mode">
            <t-radio-group v-model="form.product_scope_mode" :disabled="!canManage">
              <t-radio value="all">{{ $t('tickets.deliverySettings.allProducts') }}</t-radio>
              <t-radio value="selected">{{ $t('tickets.deliverySettings.selectedProducts') }}</t-radio>
            </t-radio-group>
          </t-form-item>
          <t-form-item
            v-if="form.product_scope_mode === 'selected'"
            :label="$t('tickets.deliverySettings.selectedProducts')"
            name="product_ids"
          >
            <t-select
              v-model="form.product_ids"
              :disabled="!canManage || !form.supplier_id"
              filterable
              multiple
              :placeholder="$t('tickets.deliverySettings.boundProductsPlaceholder')"
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
        <t-form-item :label="$t('tickets.deliverySettings.ruleOptions')">
          <t-space direction="vertical" size="small">
            <t-checkbox v-model="form.enabled" :disabled="!canManage">{{ $t('tickets.deliverySettings.enableRule') }}</t-checkbox>
            <t-checkbox v-model="form.sync_admin_replies" :disabled="!canManage">{{ $t('tickets.deliverySettings.syncAdminReplies') }}</t-checkbox>
          </t-space>
        </t-form-item>
        <t-form-item :label="$t('tickets.deliverySettings.maskKeywords')" name="mask_keywords">
          <t-textarea
            v-model="form.mask_keywords"
            :disabled="!canManage"
            :autosize="{ minRows: 3, maxRows: 6 }"
            maxlength="10000"
            :placeholder="$t('tickets.deliverySettings.maskKeywordsPlaceholder')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-card v-if="canManage" class="ticket-delivery-card ticket-delivery-guard-card" :bordered="false">
      <div class="ticket-delivery-toolbar">
        <div class="ticket-delivery-summary">
          <strong>{{ $t('tickets.deliverySettings.uploadGuardTitle') }}</strong>
          <span>{{ $t('tickets.deliverySettings.uploadGuardTip') }}</span>
        </div>
        <t-button v-if="canManage" theme="primary" variant="outline" :loading="guardSaving" @click="saveUploadGuard">
          {{ $t('tickets.deliverySettings.saveGuardConfig') }}
        </t-button>
      </div>

      <t-form
        class="ticket-delivery-guard-form"
        :label-align="isMobile ? 'top' : 'right'"
        :label-width="isMobile ? undefined : '170px'"
      >
        <t-form-item :label="$t('tickets.deliverySettings.enableUploadImage')">
          <t-switch v-model="uploadGuard.upload_image_enabled" :disabled="!canManage" />
          <span class="ticket-delivery-guard-hint">{{ $t('tickets.deliverySettings.enableUploadImageTip') }}</span>
        </t-form-item>
        <t-form-item :label="$t('tickets.deliverySettings.allowedIps')">
          <t-textarea
            v-model="uploadGuard.allowed_ips"
            :disabled="!canManage"
            :autosize="{ minRows: 2, maxRows: 5 }"
            maxlength="2000"
            :placeholder="$t('tickets.deliverySettings.allowedIpsPlaceholder')"
          />
        </t-form-item>
        <t-form-item :label="$t('tickets.deliverySettings.rateLimit')">
          <t-input-number
            v-model="uploadGuard.rate_limit"
            :disabled="!canManage || uploadGuard.block_non_whitelisted"
            :min="0"
            :max="10000"
          />
          <span class="ticket-delivery-guard-hint">{{ $t('tickets.deliverySettings.rateLimitTip') }}</span>
        </t-form-item>
        <t-form-item :label="$t('tickets.deliverySettings.blockNonWhitelisted')">
          <t-switch v-model="uploadGuard.block_non_whitelisted" :disabled="!canManage" />
          <span class="ticket-delivery-guard-hint">{{ $t('tickets.deliverySettings.blockNonWhitelistedTip') }}</span>
        </t-form-item>
        <t-form-item :label="$t('tickets.deliverySettings.unusedRetention')">
          <span>{{ uploadGuard.unused_retention_minutes }} {{ $t('tickets.deliverySettings.unusedRetentionUnit') }}</span>
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
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';

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
import { t } from '@/locales';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminTicketDeliveryRules' });

const PROVIDER_KEY = 'zjmf_finance_api';
const { width } = useWindowSize();
const isMobile = computed(() => width.value < 768);
const canManage = computed(() => hasAdminPermission(AdminPermissions.TICKET_DELIVERY_MANAGE));
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
  { label: t('tickets.deliverySettings.departmentSales'), value: 'sales' },
  { label: t('tickets.deliverySettings.departmentSupport'), value: 'support' },
  { label: t('tickets.deliverySettings.departmentBilling'), value: 'billing' },
  { label: t('tickets.deliverySettings.departmentAbuse'), value: 'abuse' },
];

const form = reactive({
  name: '',
  department: '',
  supplier_id: '' as number | string,
  // 新规则默认「全部产品」：多数传递规则按供应商整体转发，
  // 默认指定产品会让弹窗一打开就挂一个「至少选择一个产品」的必填错误。
  product_scope_mode: 'all' as TicketDeliveryScopeMode,
  product_ids: [] as Array<number | string>,
  upstream_department_id: '',
  enabled: true,
  sync_admin_replies: false,
  mask_keywords: '',
});

const columns: PrimaryTableCol[] = [
  { title: t('tickets.deliverySettings.columnRule'), colKey: 'name', minWidth: 220 },
  { title: t('tickets.deliverySettings.supplier'), colKey: 'supplier', minWidth: 150 },
  { title: t('tickets.deliverySettings.productScope'), colKey: 'scope', minWidth: 140 },
  { title: t('tickets.deliverySettings.upstreamDepartment'), colKey: 'upstreamDepartment', width: 130 },
  { title: t('common.status'), colKey: 'status', width: 90 },
  { title: t('tickets.deliverySettings.adminReply'), colKey: 'sync', width: 110 },
  { title: t('common.action'), colKey: 'actions', fixed: 'right', width: 220 },
];

const formRules: Record<string, FormRule[]> = {
  name: [
    { required: true, message: t('tickets.deliverySettings.ruleNameRequired'), type: 'error' },
    { max: 120, message: t('tickets.deliverySettings.ruleNameTooLong'), type: 'error' },
  ],
  department: [{ required: true, message: t('tickets.deliverySettings.selectDepartment'), type: 'error' }],
  supplier_id: [{ required: true, message: t('tickets.deliverySettings.selectSupplier'), type: 'error' }],
  upstream_department_id: [{ required: true, message: t('tickets.deliverySettings.selectUpstreamDepartment'), type: 'error' }],
  product_scope_mode: [{ required: true, message: t('tickets.deliverySettings.selectProductScope'), type: 'error' }],
  product_ids: [
    {
      validator: (value) => form.product_scope_mode !== 'selected' || (Array.isArray(value) && value.length > 0),
      message: t('tickets.deliverySettings.selectProductRequired'),
      type: 'error',
    },
  ],
  mask_keywords: [{ max: 10000, message: t('tickets.deliverySettings.maskKeywordsTooLong'), type: 'error' }],
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
    product_scope_mode: 'all' as TicketDeliveryScopeMode,
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
        description: t('tickets.deliverySettings.upstreamDepartmentGone'),
      });
    }
    upstreamDepartments.value = departments;
  } catch (error) {
    if (requestId !== departmentsRequestId) return;
    upstreamDepartments.value = configuredId
      ? [{ id: String(configuredId), name: `当前已配置：${configuredId}`, description: t('tickets.deliverySettings.departmentListFailed') }]
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
    const loadedProducts: ProductRecord[] = [];
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
      loadedProducts.push(...list);
      total = Number(response.total ?? loadedProducts.length);
      page += 1;
      if (list.length === 0) break;
    } while (loadedProducts.length < total);

    // 按 ID 合并进全局产品缓存，保证列表页 productNames() 能解析出超首屏产品的名称
    const productIndex = new Map(products.value.map((product) => [String(product.id), product]));
    for (const product of loadedProducts) {
      productIndex.set(String(product.id), product);
    }
    products.value = [...productIndex.values()];
    supplierProducts.value = loadedProducts;
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
  // 按 ID 合并而非覆盖：loadSupplierProducts 可能已把分页产品写入缓存，
  // 直接替换会清掉这些产品导致列表页名称解析回退为"指定产品"。
  const productIndex = new Map(products.value.map((product) => [String(product.id), product]));
  for (const product of productResponse.list || []) {
    productIndex.set(String(product.id), product);
  }
  products.value = [...productIndex.values()];
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
  // 配置接口对无 TICKET_DELIVERY_MANAGE 权限的用户返回 403，只读用户无需加载与报错
  if (!canManage.value) return;
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
  if (!uploadGuard.upload_image_enabled && rules.value.length > 0) {
    MessagePlugin.warning(t('tickets.deliverySettings.uploadImageLockedByRules'));
    return;
  }
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
    MessagePlugin.success(t('tickets.deliverySettings.uploadGuardSaved'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存上传防护配置失败'));
  } finally {
    guardSaving.value = false;
  }
}

function openCreateDialog() {
  if (!canManage.value || !savedUploadImageEnabled.value) {
    if (canManage.value) MessagePlugin.warning(t('tickets.deliverySettings.enableUploadImageFirst'));
    return;
  }
  editingId.value = null;
  createDefaultForm();
  dialogVisible.value = true;
  // 弹窗复用同一个表单实例：打开时清掉上一次留下的校验红框
  nextTick(() => formRef.value?.clearValidate?.());
}

function openEditDialog(row: TicketDeliveryRuleRecord) {
  // 与新建流程一致：管理员在 /upload_image 未启用时禁止编辑（非管理员仅查看不受限）
  if (canManage.value && !savedUploadImageEnabled.value) {
    MessagePlugin.warning(t('tickets.deliverySettings.enableUploadImageFirst'));
    return;
  }
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
  // 复用表单实例：先清掉上一次或本页别的规则留下的校验红框，再异步补拉选项
  nextTick(() => formRef.value?.clearValidate?.());
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
    MessagePlugin.warning(t('tickets.deliverySettings.enableUploadImageFirst'));
    return;
  }
  const result = await formRef.value?.validate();
  if (result !== true) return;
  saving.value = true;
  try {
    const payload = buildPayload();
    if (editingId.value) {
      await adminApi.tickets.deliveryRules.update(editingId.value, payload);
      MessagePlugin.success(t('tickets.deliverySettings.ruleUpdated'));
    } else {
      await adminApi.tickets.deliveryRules.create(payload);
      MessagePlugin.success(t('tickets.deliverySettings.ruleCreated'));
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
    MessagePlugin.warning(t('tickets.deliverySettings.enableUploadImageFirst'));
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
    cancelBtn: t('common.cancel'),
    onConfirm: async () => {
      try {
        await adminApi.tickets.deliveryRules.delete(row.id);
        MessagePlugin.success(t('tickets.deliverySettings.ruleDeleted'));
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
