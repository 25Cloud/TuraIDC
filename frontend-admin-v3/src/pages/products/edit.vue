<template>
  <div class="product-edit-page">
    <div class="product-edit-toolbar">
      <t-button variant="text" @click="goBack">
        <template #icon><chevron-left-icon /></template>
        返回商品目录
      </t-button>
      <h2>{{ isEdit ? '编辑商品' : '新增商品' }}</h2>
    </div>

    <div class="product-edit-card">
      <div class="product-edit-layout">
        <aside class="product-edit-nav">
          <button
            v-for="(section, index) in sections"
            :key="section.key"
            type="button"
            class="product-edit-nav-item"
            :class="[{ 'is-active': activeSection === section.key }]"
            :aria-current="activeSection === section.key ? 'step' : undefined"
            @click="activeSection = section.key"
          >
            <span class="product-edit-nav-index">{{ index + 1 }}</span>
            <span class="product-edit-nav-copy">
              <strong>{{ section.label }}</strong>
              <span>{{ section.description }}</span>
            </span>
          </button>
        </aside>

        <div class="product-edit-main">
          <t-form ref="formRef" class="product-edit-form" :data="form" :rules="rules" label-width="96px">
            <!-- 详情 -->
            <section v-show="activeSection === 'basic'" class="product-edit-section">
              <h3 class="product-edit-section-title">详情</h3>
              <div class="product-edit-grid">
                <t-form-item label="商品名称" name="display_name">
                  <t-input v-model="form.display_name" />
                </t-form-item>
                <t-form-item label="所属分类" name="selected_product_group_key">
                  <t-select v-model="form.selected_product_group_key" filterable clearable>
                    <t-option
                      v-for="item in selectableGroups"
                      :key="productGroupOptionKey(item)"
                      :label="productGroupOptionLabel(item)"
                      :value="productGroupOptionKey(item)"
                    />
                  </t-select>
                </t-form-item>
                <t-form-item label="状态" name="status">
                  <t-switch v-model="form.status" :custom-value="[1, 0]" />
                </t-form-item>
              </div>
            </section>

            <!-- 定价 -->
            <section v-show="activeSection === 'pricing'" class="product-edit-section">
              <div class="product-edit-section-head">
                <h3 class="product-edit-section-title">定价</h3>
                <div class="product-edit-section-actions">
                  <t-select v-model="pricingPlan" size="small" style="width: 150px" @change="syncPricingCycles">
                    <t-option
                      v-for="item in pricingPlanOptions"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                    />
                  </t-select>
                </div>
              </div>
              <div class="product-edit-grid">
                <t-form-item label="月付价格" name="monthly_price">
                  <t-input-number v-model="form.monthly_price" :min="0" style="width: 100%" />
                </t-form-item>
                <t-form-item label="季付价格" name="quarterly_price">
                  <t-input-number v-model="form.quarterly_price" :min="0" style="width: 100%" />
                </t-form-item>
                <t-form-item label="半年付价格" name="semiannually_price">
                  <t-input-number v-model="form.semiannually_price" :min="0" style="width: 100%" />
                </t-form-item>
                <t-form-item label="年付价格" name="annually_price">
                  <t-input-number v-model="form.annually_price" :min="0" style="width: 100%" />
                </t-form-item>
              </div>
            </section>

            <!-- 接口设置 -->
            <section v-show="activeSection === 'interface'" class="product-edit-section">
              <h3 class="product-edit-section-title">自动开通</h3>
              <div class="product-edit-tip">配置开通模块、支付方式和上架状态。</div>
              <div class="product-edit-interface-panel">
                <t-form-item label="提供商" name="supplier_id">
                  <t-select
                    v-model="form.supplier_id"
                    filterable
                    clearable
                    placeholder="请选择提供商接口"
                    :loading="supplierLoading"
                    @change="handleSupplierChange"
                  >
                    <t-option
                      v-for="item in supplierOptions"
                      :key="item.id"
                      :label="supplierOptionLabel(item)"
                      :value="item.id"
                    >
                      <div class="product-edit-supplier-option">
                        <span>{{ item.name || item.id }}</span>
                        <span>{{ supplierInterfaceTypeLabel(item) }}</span>
                      </div>
                    </t-option>
                  </t-select>
                </t-form-item>
                <t-form-item label="提供商商品" name="upstream_product_id">
                  <div class="product-edit-supplier-product-row">
                    <t-cascader
                      v-model="form.upstream_product_id"
                      :options="supplierProductCascaderOptions"
                      filterable
                      clearable
                      placeholder="请选择提供商商品"
                      :loading="supplierProductLoading"
                      :disabled="!form.supplier_id"
                      value-mode="onlyLeaf"
                      :show-all-levels="false"
                      :popup-props="{ overlayClassName: 'product-edit-supplier-product-popup' }"
                    />
                    <t-button
                      theme="primary"
                      :loading="supplierProductLoading"
                      :disabled="!form.supplier_id"
                      @click="loadSupplierProducts(form.supplier_id, true)"
                    >
                      同步数据
                    </t-button>
                  </div>
                </t-form-item>
                <div class="product-edit-interface-grid">
                  <t-form-item label="自动开通" name="auto_setup">
                    <div class="product-edit-switch-line">
                      <span>手动</span>
                      <t-switch v-model="form.auto_setup" :custom-value="[1, 0]" />
                      <span>自动</span>
                    </div>
                  </t-form-item>
                  <t-form-item label="上架状态" name="interface_status">
                    <div class="product-edit-switch-line">
                      <span>下架</span>
                      <t-switch v-model="form.status" :custom-value="[1, 0]" />
                      <span>上架</span>
                    </div>
                  </t-form-item>
                </div>
              </div>
            </section>

            <!-- 产品配置 -->
            <section v-show="activeSection === 'config'" class="product-edit-section">
              <div class="product-edit-section-head">
                <h3 class="product-edit-section-title">产品配置</h3>
                <div class="product-edit-section-actions">
                  <t-button size="small" variant="outline" :loading="configTemplateLoading" @click="pullConfigTemplate">
                    拉取模板
                  </t-button>
                  <t-button size="small" theme="primary" variant="outline" @click="openConfigOptionDialog()"
                    >新增配置</t-button
                  >
                </div>
              </div>
              <div class="product-edit-config-panel">
                <div class="product-edit-config-count">{{ form.config_options.length }} 项配置，保存商品后生效。</div>
                <div v-if="form.config_options.length" class="product-edit-config-list">
                  <article v-for="(item, index) in form.config_options" :key="item.uid || item.field || index">
                    <div>
                      <strong>{{ item.name || item.option_name || item.field }}</strong>
                      <span>{{ item.field || '-' }} · {{ item.option_mode || 'select' }}</span>
                    </div>
                    <t-space size="small">
                      <t-button size="small" variant="text" theme="primary" @click="openConfigOptionDialog(item, index)"
                        >编辑</t-button
                      >
                      <t-button size="small" variant="text" theme="danger" @click="removeConfigOption(index)"
                        >删除</t-button
                      >
                    </t-space>
                  </article>
                </div>
                <t-empty v-else description="暂无配置项" />
              </div>
            </section>
          </t-form>
        </div>
      </div>

      <div class="product-edit-footer">
        <t-button variant="outline" @click="goBack">取消</t-button>
        <t-button theme="primary" :loading="submitting" @click="submit">保存更改</t-button>
      </div>
    </div>

    <!-- 配置项编辑弹窗 -->
    <t-dialog
      v-model:visible="configOptionDialogVisible"
      class="config-option-edit-dialog"
      :header="configOptionEditingIndex >= 0 ? '编辑配置项' : '新增配置项'"
      width="760px"
      :confirm-btn="{ content: '保存配置', loading: configOptionSubmitting }"
      @confirm="submitConfigOption"
    >
      <t-form class="config-option-edit-form" :data="configOptionForm" label-align="top">
        <div class="config-option-basic-grid">
          <t-form-item label="配置项名称" name="name" required-mark>
            <t-input v-model="configOptionForm.name" placeholder="例如 CPU、内存" />
          </t-form-item>
          <t-form-item label="配置项类型" name="option_mode" required-mark>
            <t-select v-model="configOptionForm.option_mode" @change="handleConfigOptionModeChange">
              <t-option label="单选（固定选项）" value="select" />
              <t-option label="数量范围" value="range" />
            </t-select>
          </t-form-item>
          <t-form-item label="高级设置">
            <t-checkbox v-model="configOptionForm.advanced" />
          </t-form-item>
          <t-form-item label="配置标识" name="field" required-mark>
            <t-input v-model="configOptionForm.field" placeholder="例如 cpu、memory、os" />
          </t-form-item>
          <t-form-item label="选项说明">
            <t-input v-model="configOptionForm.description" placeholder="例如 镜像管理中的操作系统 ID。" />
          </t-form-item>
          <t-form-item label="选项尾部文字">
            <t-input v-model="configOptionForm.suffix_text" placeholder="请输入选项尾部文字" />
          </t-form-item>
        </div>
        <t-form-item v-if="configOptionForm.option_mode !== 'select'" label="参数值" name="parameter">
          <t-input v-model="configOptionForm.parameter" placeholder="例如 1-16 / 1|1核,2|2核" />
        </t-form-item>
        <div v-else class="config-subitem-table">
          <div class="config-subitem-head">
            <strong>子项列表</strong>
            <t-button size="small" variant="outline" @click="addConfigSubItemRow">新增子项</t-button>
          </div>
          <div class="config-subitem-grid config-subitem-grid--header">
            <span>子项名称</span>
            <span>传参值</span>
            <span>月付价格</span>
            <span>排序</span>
            <span>操作</span>
          </div>
          <div v-for="(row, index) in configOptionSubItemRows" :key="row.uid" class="config-subitem-grid">
            <t-input v-model="row.name" placeholder="例如 CentOS^CentOS-7.6" />
            <t-input v-model="row.value" placeholder="例如 27" />
            <t-input v-model="row.monthly_price" placeholder="0.00" />
            <t-input-number v-model="row.sort_order" :min="0" theme="column" />
            <t-button
              shape="square"
              variant="outline"
              :disabled="configOptionSubItemRows.length <= 1"
              @click="removeConfigSubItemRow(index)"
            >
              <delete-icon />
            </t-button>
          </div>
        </div>
        <div class="config-option-footer-row">
          <t-form-item label="排序" name="sort_order">
            <t-input-number v-model="configOptionForm.sort_order" :min="0" />
          </t-form-item>
          <t-form-item label="开关">
            <t-space>
              <t-checkbox v-model="configOptionForm.required">必选</t-checkbox>
              <t-checkbox v-model="configOptionForm.hidden">隐藏</t-checkbox>
            </t-space>
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import { ChevronLeftIcon, DeleteIcon } from 'tdesign-icons-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { ProductCategoryRecord } from '@/api/product';
import { productApi } from '@/api/product';
import type { SupplierRecord } from '@/api/supplier';
import { supplierApi } from '@/api/supplier';

import {
  errorMessage,
  findProductGroupByKey,
  flattenCategories,
  isSelectableProductGroup,
  productGroupOptionKey,
  productGroupOptionLabel,
  productGroupPayload,
  providerTypeFallbackLabels,
  toPlainRecord,
} from './composables/useProductShared';

// --- Router ---
const route = useRoute();
const router = useRouter();

const productId = computed(() => {
  const id = route.params.id;
  return id ? Number(id) : 0;
});
const isEdit = computed(() => productId.value > 0);

// --- Section nav ---
const activeSection = ref('basic');
const sections = [
  { key: 'basic', label: '详情', description: '名称、分类、状态' },
  { key: 'pricing', label: '定价', description: '多周期价格' },
  { key: 'interface', label: '接口设置', description: '上游商品绑定' },
  { key: 'config', label: '产品配置', description: '规格与可选项' },
];

// --- Form ---
const formRef = ref();
const submitting = ref(false);
const form = reactive({
  display_name: '',
  product_spec_display: '',
  selected_product_group_key: '' as string,
  monthly_price: 0,
  quarterly_price: 0,
  semiannually_price: 0,
  annually_price: 0,
  auto_setup: 1,
  status: 1,
  supplier_id: '' as number | string,
  upstream_product_id: '' as number | string,
  config_options: [] as ConfigOptionRecord[],
});

const rules = {
  display_name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  selected_product_group_key: [{ required: true, message: '请选择所属分类', trigger: 'change' }],
};

// --- Categories ---
const categoryOptions = ref<ProductCategoryRecord[]>([]);
const selectableGroups = computed(() => categoryOptions.value.filter((item) => isSelectableProductGroup(item)));

// --- Pricing ---
const pricingPlan = ref('standard');
const pricingPlanOptions = [
  { value: 'standard', label: '无优惠', ratios: { quarterly: 3, semiannually: 6, annually: 12 } },
  { value: 'annual', label: '年付优惠', ratios: { quarterly: 3, semiannually: 6, annually: 10 } },
  { value: 'bulk', label: '大额优惠', ratios: { quarterly: 2.7, semiannually: 5.1, annually: 8.4 } },
  { value: 'rule1', label: '规则一', ratios: { quarterly: 3, semiannually: 4.8, annually: 9 } },
  { value: 'rule2', label: '规则二', ratios: { quarterly: 2.7, semiannually: 5.1, annually: 9.6 } },
];

// --- Suppliers ---
const supplierOptions = ref<SupplierRecord[]>([]);
const supplierLoading = ref(false);
const supplierProductOptions = ref<SupplierBatchProduct[]>([]);
const supplierProductLoading = ref(false);

interface SupplierBatchProduct {
  id: number;
  name: string;
  type_label: string;
  remote_group_name: string;
  [key: string]: unknown;
}

const supplierProductCascaderOptions = computed(() => {
  const groups = new Map<string, SupplierBatchProduct[]>();
  supplierProductOptions.value.forEach((item) => {
    const groupName = String(item.remote_group_name || item.type_label || '默认分组');
    const list = groups.get(groupName) || [];
    list.push(item);
    groups.set(groupName, list);
  });
  return Array.from(groups.entries()).map(([groupName, items], index) => ({
    label: groupName,
    value: `supplier-product-group-${index}`,
    children: items.map((item) => ({
      label: supplierProductOptionLabel(item),
      value: item.id,
    })),
  }));
});

// --- Config options ---
interface ConfigOptionRecord {
  uid?: string;
  field: string;
  name: string;
  option_name?: string;
  option_mode: string;
  parameter?: string;
  sub?: Array<Record<string, unknown>>;
  sub_items?: Array<Record<string, unknown>>;
  required?: boolean;
  hidden?: boolean;
  sort_order?: number;
  [key: string]: unknown;
}

interface ConfigOptionSubItemFormRow {
  uid: string;
  name: string;
  value: string;
  monthly_price: string;
  sort_order: number;
}

const configOptionDialogVisible = ref(false);
const configOptionSubmitting = ref(false);
const configOptionEditingIndex = ref(-1);
const configTemplateLoading = ref(false);
const configOptionForm = reactive({
  name: '',
  field: '',
  option_mode: 'select',
  parameter: '',
  sub_items_text: '',
  description: '',
  suffix_text: '',
  advanced: true,
  required: true,
  hidden: false,
  sort_order: 0,
});
const configOptionSubItemRows = ref<ConfigOptionSubItemFormRow[]>([]);

// --- Lifecycle ---
onMounted(async () => {
  await Promise.all([loadCategories(), loadSuppliers()]);
  if (isEdit.value) {
    await loadProductDetail();
  }
});

// --- Data loading ---
async function loadCategories() {
  try {
    const response = await productApi.categories();
    categoryOptions.value = flattenCategories(response.tree || response.list || [], 0, null, '');
  } catch {
    // ignore
  }
}

async function loadSuppliers() {
  supplierLoading.value = true;
  try {
    const response = await supplierApi.list({ status: 1, page: 1, page_size: 100 });
    supplierOptions.value = Array.isArray(response.list) ? response.list : [];
  } catch {
    supplierOptions.value = [];
  } finally {
    supplierLoading.value = false;
  }
}

async function loadProductDetail() {
  try {
    const detail = await productApi.detail(productId.value);
    const upstreamBinding = toPlainRecord(detail.upstream_binding);
    Object.assign(form, {
      display_name: resolveDisplayName(detail),
      product_spec_display: detail.product_spec_display || detail.cpu_memory_display || '',
      selected_product_group_key: productGroupOptionKey(detail as unknown as ProductCategoryRecord),
      monthly_price: pricingValue(detail, 'monthly', detail.monthly_price),
      quarterly_price: pricingValue(detail, 'quarterly'),
      semiannually_price: pricingValue(detail, 'semiannually'),
      annually_price: pricingValue(detail, 'annually'),
      auto_setup: Number(detail.auto_setup ?? 1),
      status: Number(detail.status ?? 1),
      supplier_id: upstreamBinding.supplier_id || '',
      upstream_product_id: upstreamBinding.upstream_product_id || '',
      config_options: normalizeConfigOptions(detail.config_options),
    });
    if (form.supplier_id) {
      loadSupplierProducts(form.supplier_id);
    }
  } catch {
    MessagePlugin.error('加载商品详情失败');
  }
}

// --- Supplier helpers ---
function supplierOptionLabel(row: SupplierRecord) {
  const typeLabel = supplierInterfaceTypeLabel(row);
  return typeLabel ? `${row.name || row.id} / ${typeLabel}` : String(row.name || row.id || '-');
}

function supplierInterfaceTypeLabel(row: SupplierRecord) {
  const upstreamBinding = toPlainRecord(row.upstream_binding);
  const rawType = String(upstreamBinding.provider_key || row.provider_key || '').trim();
  if (rawType && providerTypeFallbackLabels[rawType]) return providerTypeFallbackLabels[rawType];
  return row.provider_label || '';
}

function supplierProductOptionLabel(row: SupplierBatchProduct) {
  const typeLabel = row.type_label || row.remote_group_name || '';
  const productLabel = `#${row.id} · ${row.name || '-'}`;
  return typeLabel ? `${productLabel} · ${typeLabel}` : productLabel;
}

function handleSupplierChange(value: string | number) {
  form.supplier_id = value || '';
  form.upstream_product_id = '';
  supplierProductOptions.value = [];
  if (value) {
    loadSupplierProducts(value, true);
  }
}

async function loadSupplierProducts(supplierId: string | number, notify = false) {
  if (!supplierId) return;
  supplierProductLoading.value = true;
  try {
    const response = await supplierApi.products(supplierId, { silent: true });
    supplierProductOptions.value = buildSupplierBatchProducts(response);
  } catch (error) {
    if (notify) MessagePlugin.error(errorMessage(error, '同步上游商品失败'));
  } finally {
    supplierProductLoading.value = false;
  }
}

function buildSupplierBatchProducts(response: unknown): SupplierBatchProduct[] {
  const raw = toPlainRecord(response);
  const list = Array.isArray(raw.products)
    ? raw.products
    : Array.isArray(raw.list)
      ? raw.list
      : Array.isArray(response)
        ? response
        : [];
  return list
    .map((item: Record<string, unknown>) => ({
      id: Number(item.id || item.product_id || 0),
      name: String(item.name || item.product_name || item.display_name || '-'),
      type_label: String(item.type_label || item.type_name || item.type || item.billingcycle || ''),
      remote_group_name: String(item.remote_group_name || item.group_name || item.second_group_name || ''),
    }))
    .filter((item) => item.id > 0);
}

// --- Pricing helpers ---
function pricingValue(source: Record<string, unknown> | null, cycle: string, fallback?: unknown) {
  if (!source) return fallback ?? 0;
  const pricing = toPlainRecord(source.pricing);
  const value = pricing[cycle] ?? fallback;
  if (value === null || value === undefined || value === '') return 0;
  return Number(value) || 0;
}

function syncPricingCycles() {
  const plan = pricingPlanOptions.find((item) => item.value === pricingPlan.value) || pricingPlanOptions[0];
  const monthly = Number(form.monthly_price || 0);
  if (monthly <= 0) {
    MessagePlugin.warning('请先填写月付价格');
    return;
  }
  form.quarterly_price = roundPrice(monthly * plan.ratios.quarterly);
  form.semiannually_price = roundPrice(monthly * plan.ratios.semiannually);
  form.annually_price = roundPrice(monthly * plan.ratios.annually);
  MessagePlugin.success('已同步其他计费周期价格');
}

function roundPrice(value: number) {
  return Number(value.toFixed(2));
}

function hasPositivePrice() {
  return [form.monthly_price, form.quarterly_price, form.semiannually_price, form.annually_price].some(
    (value) => Number(value || 0) > 0,
  );
}

// --- Config option helpers ---
function normalizeConfigOptions(value: unknown): ConfigOptionRecord[] {
  const items = Array.isArray(value) ? value : [];
  return items.map((itemValue, index) => {
    const item = toPlainRecord(itemValue);
    const field = String(item.field || item.spec_key || `option_${index + 1}`).trim();
    const name = String(item.name || item.option_name || field).trim();
    return {
      ...item,
      uid: String(item.uid || `${field}-${index}`),
      field,
      name,
      option_name: name,
      option_mode: String(item.option_mode || (item.option_type === 'quantity' ? 'range' : 'select')),
      parameter: String(item.parameter || ''),
      sub: Array.isArray(item.sub) ? (item.sub as Array<Record<string, unknown>>) : [],
      sub_items: Array.isArray(item.sub_items)
        ? (item.sub_items as Array<Record<string, unknown>>)
        : Array.isArray(item.sub)
          ? (item.sub as Array<Record<string, unknown>>)
          : [],
      required: Boolean(item.required ?? true),
      hidden: Boolean(item.hidden ?? false),
      sort_order: Number(item.sort_order || index + 1),
    };
  });
}

function serializeConfigOptions(options: ConfigOptionRecord[]) {
  return options.map((item, index) => ({
    ...item,
    name: String(item.name || item.option_name || item.field || '').trim(),
    option_name: String(item.option_name || item.name || item.field || '').trim(),
    field: String(item.field || '').trim(),
    option_mode: String(item.option_mode || 'select'),
    parameter: String(item.parameter || '').trim(),
    required: Boolean(item.required),
    hidden: Boolean(item.hidden),
    sort_order: Number(item.sort_order || index + 1),
  }));
}

function resetConfigOptionForm() {
  Object.assign(configOptionForm, {
    name: '',
    field: '',
    option_mode: 'select',
    parameter: '',
    sub_items_text: '',
    description: '',
    suffix_text: '',
    advanced: true,
    required: true,
    hidden: false,
    sort_order: form.config_options.length + 1,
  });
  configOptionSubItemRows.value = [createConfigSubItemRow({}, 0)];
}

function createConfigSubItemRow(item: Record<string, unknown> = {}, index = 0): ConfigOptionSubItemFormRow {
  const pricing = toPlainRecord(item.pricing);
  const monthlyPrice = item.monthly_price ?? item.monthly ?? pricing.monthly ?? pricing.month ?? '';
  return {
    uid: String(item.uid || `config-subitem-${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`),
    name: String(item.label || item.option_name || item.name || '').trim(),
    value: String(item.value || item.option_name_first || '').trim(),
    monthly_price:
      monthlyPrice === '' || monthlyPrice === undefined || monthlyPrice === null ? '0.00' : String(monthlyPrice),
    sort_order: Number(item.sort_order || index + 1),
  };
}

function addConfigSubItemRow() {
  configOptionSubItemRows.value.push(createConfigSubItemRow({}, configOptionSubItemRows.value.length));
}

function removeConfigSubItemRow(index: number) {
  if (configOptionSubItemRows.value.length <= 1) return;
  configOptionSubItemRows.value.splice(index, 1);
}

function handleConfigOptionModeChange(value: string | number) {
  configOptionForm.option_mode = String(value || 'select');
  if (configOptionForm.option_mode === 'select' && configOptionSubItemRows.value.length === 0) {
    configOptionSubItemRows.value = [createConfigSubItemRow({}, 0)];
  }
}

function openConfigOptionDialog(row?: ConfigOptionRecord, index = -1) {
  configOptionEditingIndex.value = index;
  if (row) {
    Object.assign(configOptionForm, {
      name: row.name || row.option_name || '',
      field: row.field || '',
      option_mode: row.option_mode || 'select',
      parameter: row.parameter || '',
      sub_items_text: '',
      description: String(row.description || '').trim(),
      suffix_text: String(row.suffix_text || '').trim(),
      advanced: Boolean(row.advanced ?? true),
      required: Boolean(row.required ?? true),
      hidden: Boolean(row.hidden ?? false),
      sort_order: Number(row.sort_order || index + 1),
    });
    const sourceSubItems =
      Array.isArray(row.sub_items) && row.sub_items.length ? row.sub_items : Array.isArray(row.sub) ? row.sub : [];
    const rowSubItems = sourceSubItems.length
      ? sourceSubItems.map((item) => toPlainRecord(item))
      : parseConfigSubItems(String(row.parameter || ''));
    configOptionSubItemRows.value = rowSubItems.length
      ? rowSubItems.map((item, itemIndex) => createConfigSubItemRow(item, itemIndex))
      : [createConfigSubItemRow({}, 0)];
  } else {
    resetConfigOptionForm();
  }
  configOptionDialogVisible.value = true;
}

function parseConfigSubItems(rawValue: string) {
  return rawValue
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
    .map((item, index) => {
      const idx = item.indexOf('|');
      const value = idx >= 0 ? item.slice(0, idx).trim() : item.trim();
      const label = idx >= 0 ? item.slice(idx + 1).trim() : '';
      return { value: value || label, label: label || value, sort_order: index + 1 };
    });
}

function buildConfigSubItemsFromRows() {
  const rows = configOptionSubItemRows.value
    .map((row, index) => ({
      ...row,
      name: row.name.trim(),
      value: row.value.trim(),
      sort_order: Number(row.sort_order || index + 1),
      monthly_price: String(row.monthly_price ?? '').trim(),
    }))
    .filter((row) => row.name || row.value);

  const invalidRow = rows.find((row) => !row.name || !row.value);
  if (invalidRow) {
    throw new Error('请完整填写子项名称和传参值');
  }

  return rows.map((row, index) => {
    const monthlyAmount = Number(row.monthly_price || 0);
    return {
      id: row.value || index,
      value: row.value,
      label: row.name,
      option_name: row.name,
      option_name_first: row.value,
      monthly: Number.isFinite(monthlyAmount) ? monthlyAmount.toFixed(2) : '0.00',
      monthly_price: Number.isFinite(monthlyAmount) ? monthlyAmount.toFixed(2) : '0.00',
      pricing: { monthly: Number.isFinite(monthlyAmount) ? monthlyAmount.toFixed(2) : '0.00' },
      sort_order: row.sort_order || index + 1,
      hidden: 0,
    };
  });
}

function submitConfigOption() {
  const name = configOptionForm.name.trim();
  const field = configOptionForm.field.trim();
  if (!name || !field) {
    MessagePlugin.warning('请填写配置名称和字段名');
    return;
  }
  let subItems: ReturnType<typeof buildConfigSubItemsFromRows> = [];
  try {
    subItems = configOptionForm.option_mode === 'select' ? buildConfigSubItemsFromRows() : [];
  } catch (error) {
    MessagePlugin.warning(errorMessage(error, '请检查子项配置'));
    return;
  }
  const parameter = subItems.length
    ? subItems.map((item) => `${item.value}|${item.label}`).join(',')
    : configOptionForm.parameter.trim();
  if (configOptionForm.option_mode === 'select' && !parameter) {
    MessagePlugin.warning('请填写参数值或子项');
    return;
  }

  configOptionSubmitting.value = true;
  const payload: ConfigOptionRecord = {
    uid: `${field}-${Date.now()}`,
    source: 'manual',
    field,
    name,
    option_name: name,
    option_mode: configOptionForm.option_mode,
    option_type: configOptionForm.option_mode === 'range' ? 'quantity' : 'select',
    parameter,
    sub: subItems,
    sub_items: subItems,
    range_pricing: [],
    description: configOptionForm.description.trim(),
    suffix_text: configOptionForm.suffix_text.trim(),
    advanced: Boolean(configOptionForm.advanced),
    required: Boolean(configOptionForm.required),
    hidden: Boolean(configOptionForm.hidden),
    sort_order: Number(configOptionForm.sort_order || form.config_options.length + 1),
  };
  if (configOptionEditingIndex.value >= 0) {
    form.config_options.splice(configOptionEditingIndex.value, 1, payload);
  } else {
    form.config_options.push(payload);
  }
  configOptionDialogVisible.value = false;
  configOptionSubmitting.value = false;
}

function removeConfigOption(index: number) {
  form.config_options.splice(index, 1);
}

async function pullConfigTemplate() {
  const supplierId = form.supplier_id;
  const upstreamProductId = form.upstream_product_id;
  if (!supplierId || !upstreamProductId) {
    MessagePlugin.warning('请先绑定提供商和提供商商品 ID');
    return;
  }
  configTemplateLoading.value = true;
  try {
    const response = await supplierApi.productConfigTemplate(supplierId, upstreamProductId);
    const options = Array.isArray(response.config_options) ? response.config_options : [];
    form.config_options = normalizeConfigOptions(options);
    MessagePlugin.success('配置项模板已拉取');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '拉取配置项模板失败'));
  } finally {
    configTemplateLoading.value = false;
  }
}

// --- Submit ---
async function submit() {
  const validateResult = await formRef.value?.validate?.();
  if (validateResult !== true) return;
  if (!hasPositivePrice()) {
    activeSection.value = 'pricing';
    MessagePlugin.warning('请至少填写一个大于 0 的计费周期价格');
    return;
  }
  submitting.value = true;
  try {
    const group = findProductGroupByKey(categoryOptions.value, form.selected_product_group_key);
    const payload = {
      custom_display_name: resolveCustomDisplayNamePayload(),
      ...productGroupPayload(group),
      pricing: {
        monthly: form.monthly_price,
        quarterly: form.quarterly_price,
        semiannually: form.semiannually_price,
        annually: form.annually_price,
      },
      auto_setup: form.auto_setup,
      status: form.status,
      upstream_binding: {
        supplier_id: form.supplier_id || undefined,
        upstream_product_id: form.upstream_product_id || undefined,
      },
      config_options: serializeConfigOptions(form.config_options),
    };
    if (isEdit.value) {
      await productApi.update(productId.value, payload);
      MessagePlugin.success('商品已更新');
    } else {
      await productApi.create(payload);
      MessagePlugin.success('商品已创建');
    }
    goBack();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存商品失败'));
  } finally {
    submitting.value = false;
  }
}

function resolveDisplayName(source?: Record<string, unknown> | null) {
  return String(
    source?.custom_display_name ||
      source?.product_spec_display ||
      source?.cpu_memory_display ||
      source?.product_display_name ||
      source?.display_name ||
      source?.name ||
      '',
  ).trim();
}

function resolveCustomDisplayNamePayload() {
  const value = String(form.display_name || '').trim();
  if (!value) return null;
  const defaultDisplayName = String(form.product_spec_display || '').trim();
  return defaultDisplayName && value === defaultDisplayName ? null : value;
}

function goBack() {
  router.push({ name: 'AdminProductCatalog' });
}
</script>
<style lang="less" scoped>
.product-edit-page {
  display: flex;
  flex-direction: column;
  width: 100%;
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  gap: var(--td-comp-margin-m);
}

.product-edit-toolbar {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s);
  min-height: 32px;

  h2 {
    margin: 0;
    color: var(--td-text-color-primary);
    font-size: var(--td-font-size-size-5, 18px);
    font-weight: 600;
    line-height: 26px;
  }
}

.product-edit-card {
  overflow: hidden;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-container);
}

.product-edit-layout {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  min-height: min(640px, calc(100vh - 220px));
}

.product-edit-nav {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs);
  border-right: 1px solid var(--td-component-border);
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-page);
}

.product-edit-nav-item {
  display: grid;
  grid-template-columns: 28px minmax(0, 1fr);
  align-items: center;
  gap: var(--td-comp-margin-s);
  width: 100%;
  min-height: 58px;
  border: 1px solid transparent;
  border-radius: var(--td-radius-default, 4px);
  padding: 8px 10px;
  background: transparent;
  color: var(--td-text-color-primary);
  cursor: pointer;
  text-align: left;
  transition:
    border-color 0.16s cubic-bezier(0.2, 0, 0, 1),
    background 0.16s cubic-bezier(0.2, 0, 0, 1),
    color 0.16s cubic-bezier(0.2, 0, 0, 1);

  &:hover {
    border-color: var(--td-component-border);
    background: var(--td-bg-color-container);
  }

  &.is-active {
    border-color: var(--td-brand-color-light);
    background: var(--td-brand-color-light);
    color: var(--td-brand-color);
    box-shadow: inset 3px 0 0 var(--td-brand-color);
  }
}

.product-edit-nav-index {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: var(--td-radius-small);
  background: var(--td-bg-color-container);
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
  font-weight: 600;
}

.product-edit-nav-item.is-active .product-edit-nav-index {
  background: var(--td-brand-color);
  color: var(--td-text-color-anti);
}

.product-edit-nav-copy {
  display: flex;
  flex-direction: column;
  min-width: 0;
  gap: 2px;

  strong,
  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    font-size: var(--td-font-size-size-3, 14px);
    font-weight: 600;
    line-height: 22px;
  }

  span {
    color: var(--td-text-color-secondary);
    font-size: var(--td-font-size-size-1, 12px);
    line-height: 18px;
  }
}

.product-edit-main {
  min-width: 0;
  padding: 28px 32px;
}

.product-edit-form {
  width: 100%;
  max-width: 1120px;
}

.product-edit-section {
  min-width: 0;
}

.product-edit-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--td-comp-margin-m);
  margin-bottom: var(--td-comp-margin-l);
}

.product-edit-section-title {
  margin: 0 0 var(--td-comp-margin-l);
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-4, 16px);
  font-weight: 600;
  line-height: 24px;
}

.product-edit-section-head .product-edit-section-title {
  margin-bottom: 0;
}

.product-edit-section-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  justify-content: flex-end;
}

.product-edit-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(260px, 1fr));
  gap: 2px 32px;
}

.product-edit-tip {
  margin: -8px 0 var(--td-comp-margin-l);
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-2, 13px);
  line-height: 20px;
}

.product-edit-interface-panel {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.product-edit-supplier-product-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--td-comp-margin-s);
  width: 100%;

  > .t-cascader {
    min-width: 0;
  }
}

:global(.product-edit-supplier-product-popup .t-popup__content) {
  width: min(760px, calc(100vw - 32px));
  max-width: calc(100vw - 32px);
}

:global(.product-edit-supplier-product-popup .t-cascader__panel) {
  width: 100%;
}

:global(.product-edit-supplier-product-popup .t-cascader__menu) {
  box-sizing: border-box;
  flex: 0 0 36%;
  min-width: 0;
  width: 36%;
}

:global(.product-edit-supplier-product-popup .t-cascader__menu:last-child) {
  flex-basis: 64%;
  width: 64%;
}

:global(.product-edit-supplier-product-popup .t-cascader__menu:only-child) {
  flex-basis: 100%;
  width: 100%;
}

.product-edit-interface-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(220px, 1fr));
  gap: 2px 32px;
}

.product-edit-switch-line {
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  gap: var(--td-comp-margin-s);
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-2, 13px);
}

.product-edit-config-panel {
  min-width: 0;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-default, 4px);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-container);
}

.product-edit-config-count {
  margin-bottom: var(--td-comp-margin-m);
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-2, 13px);
  line-height: 20px;
}

.product-edit-config-list {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
}

.product-edit-config-list article {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--td-comp-margin-m);
  align-items: center;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-default, 4px);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-container);

  &:hover {
    border-color: var(--td-brand-color-light);
    background: var(--td-bg-color-container-hover);
  }

  > div:first-child {
    display: flex;
    flex-direction: column;
    min-width: 0;
    gap: 3px;
  }

  strong,
  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    color: var(--td-text-color-primary);
    font-weight: 600;
  }

  span {
    color: var(--td-text-color-secondary);
    font-size: var(--td-font-size-size-1, 12px);
  }
}

.product-edit-supplier-option {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--td-comp-margin-m);
  align-items: center;
  width: 100%;

  span:first-child {
    overflow: hidden;
    color: var(--td-text-color-primary);
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  span:last-child {
    color: var(--td-text-color-secondary);
    font-size: var(--td-font-size-size-1, 12px);
  }
}

.product-edit-footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--td-comp-margin-s);
  border-top: 1px solid var(--td-component-border);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-xl);
  background: var(--td-bg-color-container);
}

.product-edit-form :deep(.t-form__item) {
  margin-bottom: var(--td-comp-margin-l);
}

.product-edit-form :deep(.t-form__label) {
  color: var(--td-text-color-primary);
}

// Config option dialog styles
.config-option-basic-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 24px;
}

.config-subitem-table {
  margin-top: 16px;
}

.config-subitem-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.config-subitem-grid {
  display: grid;
  grid-template-columns: 1.2fr 0.8fr 0.8fr 80px 40px;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;

  &--header {
    font-size: 12px;
    color: var(--td-text-color-placeholder);
    padding-bottom: 4px;
    border-bottom: 1px solid var(--td-component-border);
  }
}

.config-option-footer-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 24px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--td-component-border);
}

@media (max-width: 1024px) {
  .product-edit-layout {
    grid-template-columns: 1fr;
    min-height: 0;
  }

  .product-edit-nav {
    flex-direction: row;
    overflow-x: auto;
    border-right: 0;
    border-bottom: 1px solid var(--td-component-border);
    padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  }

  .product-edit-nav-item {
    min-width: 160px;
  }

  .product-edit-main {
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  }

  .product-edit-form {
    max-width: none;
  }
}

@media (max-width: 768px) {
  :global(.product-edit-supplier-product-popup .t-popup__content) {
    width: calc(100vw - 24px);
    max-width: calc(100vw - 24px);
  }

  .product-edit-page {
    padding: 12px;
  }

  .product-edit-toolbar {
    flex-wrap: wrap;
  }

  .product-edit-main {
    padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  }

  .product-edit-grid,
  .product-edit-interface-grid {
    grid-template-columns: 1fr;
  }

  .product-edit-section-head {
    align-items: stretch;
    flex-direction: column;
  }

  .product-edit-section-actions {
    justify-content: flex-start;
  }

  .product-edit-supplier-product-row {
    grid-template-columns: 1fr;
  }

  .product-edit-config-list article {
    grid-template-columns: 1fr;
  }

  .product-edit-footer {
    padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  }

  .product-edit-footer .t-button {
    flex: 1;
  }

  .config-option-basic-grid,
  .config-option-footer-row {
    grid-template-columns: 1fr;
  }

  .config-subitem-table {
    overflow-x: auto;
  }

  .config-subitem-grid {
    min-width: 640px;
  }
}
</style>
