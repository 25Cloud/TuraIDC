<template>
  <t-card :bordered="false">
    <div class="filter-row supplier-filter-row">
      <t-input
        v-model="supplierFilters.keyword"
        class="supplier-filter-keyword"
        clearable
        placeholder="搜索接口名称 / 用户名"
        @enter="handleSupplierSearch"
        @clear="handleSupplierSearch"
      >
        <template #suffix-icon><search-icon /></template>
      </t-input>
      <t-select
        v-model="supplierFilters.status"
        class="supplier-filter-status"
        clearable
        placeholder="接口状态"
        @change="handleSupplierSearch"
      >
        <t-option :value="1" label="启用中" />
        <t-option :value="0" label="已停用" />
      </t-select>
      <div class="supplier-filter-actions">
        <t-button v-if="canManageSuppliers" theme="primary" @click="openSupplierDialog()">
          <template #icon><add-icon /></template>
          新增提供商
        </t-button>
      </div>
    </div>

    <div class="supplier-grid">
      <article v-for="row in suppliers" :key="row.id" class="supplier-card">
        <div class="supplier-card__head">
          <div>
            <strong>{{ supplierCardTitle(row) }}</strong>
            <span v-if="supplierCardSubtitle(row)">{{ supplierCardSubtitle(row) }}</span>
          </div>
          <t-tag
            v-if="supplierCardStatus(row).label"
            :theme="supplierCardStatus(row).theme"
            :variant="supplierCardStatus(row).variant"
          >
            {{ supplierCardStatus(row).label }}
          </t-tag>
        </div>
        <div v-if="supplierCardEmptyText(row)" class="supplier-card__empty">
          {{ supplierCardEmptyText(row) }}
        </div>
        <dl v-else>
          <div v-for="field in supplierCardFields(row)" :key="supplierCardFieldKey(field)">
            <dt>{{ field.label }}</dt>
            <dd :class="supplierCardFieldClass(field)">{{ supplierCardFieldValue(field) }}</dd>
          </div>
        </dl>
        <div class="supplier-card__actions">
          <t-button size="small" variant="text" theme="primary" @click="openSupplierDialog(row)">{{
            canManageSuppliers ? '编辑' : '查看'
          }}</t-button>
          <t-button
            v-for="action in supplierCardActions(row)"
            :key="action.key"
            size="small"
            :variant="action.variant || 'text'"
            :theme="action.theme || 'default'"
            :loading="supplierCardActionLoading === supplierCardActionLoadingKey(row, action)"
            :disabled="supplierCardActionDisabled(action)"
            @click="handleSupplierCardAction(row, action)"
          >
            {{ action.label }}
          </t-button>
          <t-button
            v-if="canManageSuppliers"
            size="small"
            variant="text"
            :loading="supplierActionLoading === row.id"
            @click="handleToggleSupplier(row)"
          >
            {{ Number(row.status) === 1 ? '停用' : '启用' }}
          </t-button>
          <t-button
            v-if="canManageSuppliers"
            size="small"
            variant="text"
            theme="danger"
            @click="handleDeleteSupplier(row)"
            >删除</t-button
          >
        </div>
      </article>
      <t-empty v-if="!supplierLoading && suppliers.length === 0" description="暂无提供商" />
    </div>

    <div v-if="supplierTotal > 0" class="pagination-row">
      <t-pagination
        :current="supplierPage"
        :page-size="supplierPageSize"
        :total="supplierTotal"
        :page-size-options="[20, 50, 100]"
        show-jumper
        @change="handleSupplierPageChange"
      />
    </div>
  </t-card>

  <t-dialog
    v-model:visible="supplierBatchDialogVisible"
    :header="supplierBatchSupplier?.name ? `批量对接 · ${supplierBatchSupplier.name}` : '批量对接'"
    width="1080px"
    :confirm-btn="canSyncSuppliers ? { content: '执行对接', loading: supplierBatchSubmitting } : null"
    @confirm="submitSupplierBatchConnect"
  >
    <div class="split-dialog-intro">
      <strong>左右穿梭对接商品</strong>
      <p>
        左侧选择 ZJMF 财务未对接商品，右侧选择当前系统分类作为导入位置，执行后会创建或更新本地商品并绑定当前提供商商品
        ID。
      </p>
    </div>

    <t-form class="supplier-batch-form" :data="supplierBatchForm" label-width="90px">
      <div class="supplier-batch-settings">
        <t-form-item label="默认上架">
          <t-switch v-model="supplierBatchForm.default_status" :custom-value="[1, 0]" />
        </t-form-item>
        <t-form-item label="自动开通">
          <t-switch v-model="supplierBatchForm.default_auto_setup" :custom-value="[1, 0]" />
        </t-form-item>
        <t-form-item label="同步配置项">
          <t-switch v-model="supplierBatchForm.sync_config_options" :custom-value="[1, 0]" />
        </t-form-item>
      </div>
    </t-form>

    <div class="supplier-batch-toolbar">
      <span>
        已选 {{ supplierBatchSelectedKeys.length }} / {{ supplierBatchPendingProducts.length }} 个未对接商品
        <template v-if="supplierBatchTargetGroupLabel">，对接到：{{ supplierBatchTargetGroupLabel }}</template>
      </span>
      <t-space size="small">
        <t-button size="small" variant="outline" :loading="supplierBatchLoading" @click="reloadSupplierBatchProducts"
          >刷新商品</t-button
        >
        <t-button
          size="small"
          variant="text"
          :disabled="!supplierBatchPendingProducts.length"
          @click="selectPendingSupplierBatchProducts"
        >
          选择未对接
        </t-button>
        <t-button
          size="small"
          variant="text"
          :disabled="!supplierBatchSelectedKeys.length"
          @click="supplierBatchSelectedKeys = []"
        >
          清空
        </t-button>
      </t-space>
    </div>

    <div class="supplier-batch-transfer">
      <section class="supplier-batch-panel">
        <div class="supplier-batch-panel__head">
          <div class="supplier-batch-panel__title">
            <strong>未对接</strong>
            <span>ZJMF 财务商品结构（含已对接）</span>
          </div>
          <div class="supplier-batch-panel__actions">
            <t-tag variant="light" theme="warning"
              >未对接 {{ supplierBatchPendingProducts.length }}/{{ supplierBatchProducts.length }}</t-tag
            >
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchRemoteRows.length"
              @click="setAllSupplierBatchRemoteExpanded(true)"
            >
              展开
            </t-button>
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchRemoteRows.length"
              @click="setAllSupplierBatchRemoteExpanded(false)"
            >
              收起
            </t-button>
          </div>
        </div>
        <div class="supplier-tree" :class="{ 'is-loading': supplierBatchLoading }">
          <template v-if="supplierBatchRemoteRows.length">
            <div
              v-for="row in supplierBatchVisibleRemoteRows"
              :key="row.key"
              class="supplier-tree-node"
              :class="[
                `supplier-tree-node--${row.node_type}`,
                `supplier-tree-node--level-${Math.min(row.level, 3)}`,
                { 'is-disabled': row.node_type === 'product' && row.product?.is_connected },
              ]"
            >
              <span class="supplier-tree-node__indent" :style="{ width: `${row.level * 18}px` }" />
              <button
                v-if="row.node_type === 'group' && row.hasChildren"
                type="button"
                class="supplier-tree-toggle"
                :class="{ 'is-expanded': row.isExpanded }"
                :aria-label="row.isExpanded ? `收起${row.label}` : `展开${row.label}`"
                @click="toggleSupplierBatchRemoteGroup(row.key)"
              >
                <chevron-right-icon />
              </button>
              <span v-else class="supplier-tree-toggle supplier-tree-toggle--placeholder" />
              <template v-if="row.node_type === 'group'">
                <div class="supplier-tree-node__content">
                  <span class="supplier-tree-level-badge">L{{ row.level + 1 }}</span>
                  <span class="supplier-tree-node__label">{{ row.label }}</span>
                  <span v-if="row.count !== undefined" class="supplier-tree-node__count">{{ row.count }}</span>
                </div>
              </template>
              <template v-else>
                <div
                  class="supplier-tree-node__content supplier-tree-node__content--product"
                  :class="{
                    'is-selectable': !row.product?.is_connected,
                    'is-selected': supplierBatchSelectedKeySet.has(row.productId || 0),
                  }"
                  @click="
                    !row.product?.is_connected &&
                    handleSupplierBatchProductCheck(
                      row.productId || 0,
                      !supplierBatchSelectedKeySet.has(row.productId || 0),
                    )
                  "
                >
                  <t-checkbox
                    :checked="supplierBatchSelectedKeySet.has(row.productId || 0)"
                    :disabled="row.product?.is_connected"
                    :aria-label="`${row.product?.name || '商品'}，点击切换选择`"
                    @click.stop
                    @change="
                      (checked: boolean) =>
                        !row.product?.is_connected && handleSupplierBatchProductCheck(row.productId || 0, checked)
                    "
                  />
                  <div class="supplier-tree-product">
                    <strong>{{ row.product?.name || '-' }}</strong>
                    <span>
                      {{ row.product?.type_label || row.product?.remote_group_name || '-' }}
                      <template v-if="row.product?.is_connected"> · 已对接</template>
                    </span>
                  </div>
                </div>
              </template>
            </div>
          </template>
          <t-empty v-else :description="supplierBatchLoading ? '正在加载上游商品' : '暂无上游商品'" />
        </div>
      </section>

      <div class="supplier-batch-transfer__action">
        <t-button
          theme="primary"
          :disabled="!supplierBatchSelectedKeys.length || !supplierBatchTargetGroupKey"
          :loading="supplierBatchSubmitting"
          @click="submitSupplierBatchConnect"
        >
          对接
        </t-button>
      </div>

      <section class="supplier-batch-panel">
        <div class="supplier-batch-panel__head">
          <div class="supplier-batch-panel__title">
            <strong>已对接</strong>
            <span>当前系统全部商品结构</span>
          </div>
          <div class="supplier-batch-panel__actions">
            <t-tag variant="light" theme="success">{{ supplierBatchLocalProducts.length }}</t-tag>
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchConnectedRows.length"
              @click="setAllSupplierBatchLocalExpanded(true)"
            >
              展开
            </t-button>
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchConnectedRows.length"
              @click="setAllSupplierBatchLocalExpanded(false)"
            >
              收起
            </t-button>
          </div>
        </div>
        <div class="supplier-tree supplier-tree--target">
          <template v-if="supplierBatchConnectedRows.length">
            <div
              v-for="row in supplierBatchVisibleConnectedRows"
              :key="row.key"
              class="supplier-tree-node"
              :class="[`supplier-tree-node--${row.node_type}`, `supplier-tree-node--level-${Math.min(row.level, 3)}`]"
            >
              <span class="supplier-tree-node__indent" :style="{ width: `${row.level * 18}px` }" />
              <button
                v-if="row.node_type === 'group' && row.hasChildren"
                type="button"
                class="supplier-tree-toggle"
                :class="{ 'is-expanded': row.isExpanded }"
                :aria-label="row.isExpanded ? `收起${row.label}` : `展开${row.label}`"
                @click="toggleSupplierBatchLocalGroup(row.key)"
              >
                <chevron-right-icon />
              </button>
              <span v-else class="supplier-tree-toggle supplier-tree-toggle--placeholder" />
              <template v-if="row.node_type === 'group'">
                <button
                  type="button"
                  class="supplier-tree-node__content"
                  :class="{
                    'is-selected': row.groupKey && row.groupKey === supplierBatchTargetGroupKey,
                    'is-selectable': row.selectable,
                  }"
                  :disabled="!row.selectable"
                  @click="row.selectable && selectSupplierBatchTargetGroup(row.groupKey || '')"
                >
                  <span class="supplier-tree-level-badge">L{{ row.level + 1 }}</span>
                  <span class="supplier-tree-node__label">{{ row.label }}</span>
                  <span v-if="row.count !== undefined" class="supplier-tree-node__count">{{ row.count }}</span>
                </button>
              </template>
              <template v-else>
                <div class="supplier-tree-node__content supplier-tree-node__content--product">
                  <span class="supplier-tree-node__leaf-dot" />
                  <div class="supplier-tree-product">
                    <strong>{{ localProductDisplayName(row.localProduct) }}</strong>
                    <span>{{ localProductSubtitle(row.localProduct) }}</span>
                  </div>
                </div>
              </template>
            </div>
          </template>
          <t-empty v-else description="暂无系统商品分类" />
        </div>
      </section>
    </div>

    <div v-if="supplierBatchResult" class="supplier-batch-result">
      新增 {{ supplierBatchResult.created_count || 0 }}，更新 {{ supplierBatchResult.updated_count || 0 }}，跳过
      {{ supplierBatchResult.skipped_count || 0 }}
    </div>
  </t-dialog>

  <t-dialog
    v-model:visible="supplierDialogVisible"
    :header="editingSupplier ? '编辑提供商' : '新增提供商'"
    width="620px"
    :confirm-btn="canManageSuppliers ? { content: '保存', loading: supplierSubmitting } : null"
    @confirm="submitSupplier"
  >
    <t-form
      ref="supplierFormRef"
      :data="supplierForm"
      :rules="supplierRules"
      label-width="110px"
      :disabled="!canManageSuppliers"
    >
      <t-form-item label="插件提供商" name="provider_key">
        <t-select v-model="supplierForm.provider_key" filterable @change="handleSupplierProviderChange">
          <t-option v-for="item in providerTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </t-form-item>
      <t-form-item label="接口名称" name="name"><t-input v-model="supplierForm.name" /></t-form-item>
      <div v-if="selectedSupplierFormHelp" class="supplier-form-help">{{ selectedSupplierFormHelp }}</div>
      <t-form-item v-for="field in supplierCredentialFields" :key="field.key" :label="field.label" :name="field.key">
        <t-select
          v-if="field.type === 'select'"
          v-model="supplierCredentialValues[field.key]"
          :placeholder="field.placeholder || `请选择${field.label}`"
          clearable
          filterable
        >
          <t-option
            v-for="option in field.options || []"
            :key="String(option.value)"
            :label="option.label"
            :value="option.value"
          />
        </t-select>
        <t-switch
          v-else-if="field.type === 'switch' || field.type === 'boolean'"
          v-model="supplierCredentialValues[field.key]"
        />
        <t-input-number
          v-else-if="field.type === 'number'"
          v-model="supplierCredentialValues[field.key]"
          :placeholder="field.placeholder || `请输入${field.label}`"
          style="width: 100%"
        />
        <t-textarea
          v-else-if="field.type === 'textarea'"
          v-model="supplierCredentialValues[field.key]"
          :autosize="{ minRows: 3, maxRows: 6 }"
          :placeholder="field.placeholder || `请输入${field.label}`"
        />
        <secret-input
          v-else-if="field.secret"
          :model-value="secretSupplierFieldValue(field)"
          :has-value="hasExistingSupplierSecret(field)"
          :placeholder="supplierFieldPlaceholder(field)"
          :reset-key="supplierSecretResetKey(field)"
          :can-reveal="canRevealSupplierSecrets"
          :reveal="() => revealSupplierSecret(field)"
          @update:model-value="(value: string) => (supplierCredentialValues[field.key] = value)"
          @edited-change="(value: boolean) => (supplierSecretEdited[field.key] = value)"
          @reveal-error="(error: unknown) => MessagePlugin.error(errorMessage(error, '读取敏感配置失败'))"
        />
        <t-input
          v-else
          v-model="supplierCredentialValues[field.key]"
          :type="field.type === 'password' ? 'password' : 'text'"
          clearable
          :placeholder="supplierFieldPlaceholder(field)"
        />
        <p v-if="field.description" class="supplier-field-tip">{{ field.description }}</p>
      </t-form-item>
      <t-form-item label="状态" name="status"
        ><t-switch v-model="supplierForm.status" :custom-value="[1, 0]"
      /></t-form-item>
    </t-form>
  </t-dialog>
</template>
<script setup lang="ts">
import { AddIcon, ChevronRightIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { PageInfo } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { ProductCategoryRecord, ProductRecord } from '@/api/product';
import { productApi } from '@/api/product';
import type {
  ProviderTypeRecord,
  SupplierCardAction,
  SupplierCardField,
  SupplierCardStatus,
  SupplierFormField,
  SupplierFormSchema,
  SupplierRecord,
  SupplierUpsertPayload,
} from '@/api/supplier';
import { supplierApi } from '@/api/supplier';
import SecretInput from '@/components/secret-input/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { hasAdminPermission } from '@/utils/permission';

import {
  errorMessage,
  findProductGroupByKey,
  flattenCategories,
  isSelectableProductGroup,
  mergeProviderTypeOptions,
  productGroupOptionKey,
  productGroupOptionLabel,
  productGroupPayload,
  providerTypeFallbackLabels,
  toPlainRecord,
} from '../composables/useProductShared';

interface SupplierBatchProduct {
  id: number;
  name: string;
  type_label: string;
  remote_group_name: string;
  is_connected: boolean;
  connected_display_name: string;
  local_product_id?: number | null;
  local_product_name?: string | null;
  local_group_path?: string[];
  [key: string]: unknown;
}

interface SupplierBatchTreeRow {
  key: string;
  node_type: 'group' | 'product';
  level: number;
  label: string;
  parentKey?: string;
  count?: number;
  hasChildren?: boolean;
  isExpanded?: boolean;
  selectable?: boolean;
  groupKey?: string;
  group?: ProductCategoryRecord;
  productId?: number;
  product?: SupplierBatchProduct;
  localProduct?: ProductRecord;
}

interface SupplierBatchRemoteGroupNode {
  key: string;
  label: string;
  level: number;
  parentKey?: string;
  children: SupplierBatchRemoteGroupNode[];
  childMap: Map<string, SupplierBatchRemoteGroupNode>;
  products: SupplierBatchProduct[];
}

// --- State ---
const supplierLoading = ref(false);
const supplierSubmitting = ref(false);
const supplierActionLoading = ref<number | string | null>(null);
const supplierCardActionLoading = ref<string | null>(null);
let supplierLoadBatchId = 0;

const suppliers = ref<SupplierRecord[]>([]);
const supplierTotal = ref(0);
const supplierPage = ref(1);
const supplierPageSize = ref(20);
const providerTypes = ref<ProviderTypeRecord[]>([]);

const supplierFilters = reactive({
  keyword: '',
  status: '' as number | string,
});

const supplierDialogVisible = ref(false);
const editingSupplier = ref<SupplierRecord | null>(null);
const supplierFormRef = ref();
const supplierBatchDialogVisible = ref(false);
const supplierBatchLoading = ref(false);
const supplierBatchSubmitting = ref(false);
const supplierBatchSupplier = ref<SupplierRecord | null>(null);
const supplierBatchAction = ref<SupplierCardAction | null>(null);
const supplierBatchProducts = ref<SupplierBatchProduct[]>([]);
const supplierBatchLocalProducts = ref<ProductRecord[]>([]);
const supplierBatchCategories = ref<ProductCategoryRecord[]>([]);
const supplierBatchCategoryTree = ref<ProductCategoryRecord[]>([]);
const supplierBatchSelectedKeys = ref<Array<string | number>>([]);
const supplierBatchTargetGroupKey = ref('');
const supplierBatchResult = ref<Record<string, unknown> | null>(null);
const supplierBatchRemoteExpandedKeys = ref<string[]>([]);
const supplierBatchLocalExpandedKeys = ref<string[]>([]);
const supplierBatchRemoteExpansionInitialized = ref(false);
const supplierBatchLocalExpansionInitialized = ref(false);
const supplierBatchForm = reactive({
  default_status: 1,
  default_auto_setup: 1,
  sync_config_options: 1,
});
const supplierForm = reactive({
  provider_key: '',
  name: '',
  status: 1,
});
const supplierCredentialValues = reactive<Record<string, unknown>>({});
const supplierSecretEdited = reactive<Record<string, boolean>>({});
const canManageSuppliers = computed(() => hasAdminPermission(AdminPermissions.SUPPLIER_MANAGE));
const canSyncSuppliers = computed(() => hasAdminPermission(AdminPermissions.SUPPLIER_SYNC));
const canRevealSupplierSecrets = computed(() => hasAdminPermission(AdminPermissions.SUPPLIER_SECRET_REVEAL));

const supplierRules = {
  provider_key: [{ required: true, message: '请选择插件提供商', trigger: 'change' }],
  name: [{ required: true, message: '请输入接口名称', trigger: 'blur' }],
};

const fallbackSupplierCredentialFields: SupplierFormField[] = [
  {
    key: 'api_url',
    label: '接口地址',
    type: 'url',
    required: true,
    placeholder: 'https://panel.example.com',
  },
  {
    key: 'api_username',
    label: '接口账号',
    type: 'text',
    required: true,
  },
  {
    key: 'api_key',
    label: '接口密钥',
    type: 'password',
    required: true,
    secret: true,
    placeholder: '编辑时留空则保持原密钥',
  },
];

// --- Computeds ---
const providerTypeOptions = computed(() => {
  return mergeProviderTypeOptions(providerTypes.value);
});

const selectedProviderType = computed(() => {
  return providerTypeOptions.value.find((item) => item.value === supplierForm.provider_key) || null;
});

const selectedSupplierForm = computed<SupplierFormSchema>(() => {
  const schema = toPlainRecord(selectedProviderType.value?.supplier_form);
  const fields = normalizeSupplierFormFields(schema.fields);

  return {
    fields: fields.length > 0 ? fields : fallbackSupplierCredentialFields,
    help: String(schema.help || '').trim(),
  };
});

const selectedSupplierFormHelp = computed(() => selectedSupplierForm.value.help || '');

const supplierCredentialFields = computed(() => selectedSupplierForm.value.fields || []);

const supplierBatchPendingProducts = computed(() => supplierBatchProducts.value.filter((item) => !item.is_connected));
const supplierBatchSelectedKeySet = computed(() => new Set(supplierBatchSelectedKeys.value.map((id) => Number(id))));
const supplierBatchRemoteRows = computed(() => buildRemoteProductTreeRows(supplierBatchProducts.value));
const supplierBatchConnectedRows = computed(() =>
  buildLocalProductTreeRows(supplierBatchCategoryTree.value, supplierBatchLocalProducts.value),
);
const supplierBatchRemoteExpandedKeySet = computed(() => new Set(supplierBatchRemoteExpandedKeys.value));
const supplierBatchLocalExpandedKeySet = computed(() => new Set(supplierBatchLocalExpandedKeys.value));
const supplierBatchVisibleRemoteRows = computed(() =>
  visibleSupplierBatchTreeRows(supplierBatchRemoteRows.value, supplierBatchRemoteExpandedKeySet.value),
);
const supplierBatchVisibleConnectedRows = computed(() =>
  visibleSupplierBatchTreeRows(supplierBatchConnectedRows.value, supplierBatchLocalExpandedKeySet.value),
);
const supplierBatchTargetGroup = computed(() =>
  findProductGroupByKey(supplierBatchCategories.value, supplierBatchTargetGroupKey.value),
);
const supplierBatchTargetGroupLabel = computed(() => {
  return supplierBatchTargetGroup.value ? productGroupOptionLabel(supplierBatchTargetGroup.value) : '';
});

// --- Methods ---
function supplierCard(row: SupplierRecord) {
  return toPlainRecord(row.card);
}

function supplierCardTitle(row: SupplierRecord) {
  return String(supplierCard(row).title || row.name || '-').trim() || '-';
}

function supplierCardSubtitle(row: SupplierRecord) {
  return String(supplierCard(row).subtitle || '').trim();
}

function supplierCardStatus(row: SupplierRecord): Required<SupplierCardStatus> {
  const status = toPlainRecord(supplierCard(row).status);
  return {
    label: String(status.label || '').trim(),
    theme: String(status.theme || 'default').trim(),
    variant: String(status.variant || 'light').trim(),
  };
}

function supplierCardFields(row: SupplierRecord): SupplierCardField[] {
  const fields = supplierCard(row).fields;
  return Array.isArray(fields)
    ? fields
        .map((field) => toPlainRecord(field) as SupplierCardField)
        .filter((field) => String(field.label || '').trim())
    : [];
}

function supplierCardActions(row: SupplierRecord): SupplierCardAction[] {
  const actions = supplierCard(row).actions;
  if (!canSyncSuppliers.value || !Array.isArray(actions)) return [];
  return actions
    .map((action) => {
      const record = toPlainRecord(action);
      return {
        key: String(record.key || '').trim(),
        label: String(record.label || '').trim(),
        action: String(record.action || '').trim(),
        request_action: String(record.request_action || '').trim(),
        theme: String(record.theme || '').trim(),
        variant: String(record.variant || '').trim(),
        disabled: Boolean(record.disabled),
        disabled_reason: String(record.disabled_reason || '').trim(),
      };
    })
    .filter(
      (action) =>
        String(action.key || '').trim() && String(action.action || '').trim() && String(action.label || '').trim(),
    );
}

function supplierCardEmptyText(row: SupplierRecord) {
  const card = supplierCard(row);
  const emptyText = String(card.empty_text || '').trim();
  return supplierCardFields(row).length === 0 && emptyText ? emptyText : '';
}

function supplierCardFieldKey(field: SupplierCardField) {
  return String(field.key || field.label || '').trim();
}

function supplierCardFieldValue(field: SupplierCardField) {
  const value = field.value;
  if (value === null || value === undefined || value === '') return '-';
  if (typeof value === 'boolean') return value ? '是' : '否';
  return String(value);
}

function supplierCardFieldClass(field: SupplierCardField) {
  const theme = String(field.theme || '').trim();
  return theme ? `supplier-card__field-value--${theme}` : '';
}

function supplierCardActionDisabled(action: SupplierCardAction) {
  return Boolean(action.disabled);
}

function supplierCardActionLoadingKey(row: SupplierRecord, action: SupplierCardAction) {
  return `${row.id}:${action.key}`;
}

function handleSupplierSearch() {
  supplierPage.value = 1;
  void loadSuppliers();
}

function handleSupplierPageChange(pageInfo: PageInfo) {
  supplierPage.value = pageInfo.current;
  supplierPageSize.value = pageInfo.pageSize;
  void loadSuppliers();
}

async function loadProviderTypes() {
  try {
    const response = await supplierApi.providerTypes();
    providerTypes.value = normalizeProviderTypeOptions(response);
  } catch {
    providerTypes.value = [];
  }
}

async function loadSuppliers() {
  supplierLoadBatchId += 1;
  supplierLoading.value = true;
  try {
    const response = await supplierApi.list({
      ...supplierFilters,
      page: supplierPage.value,
      page_size: supplierPageSize.value,
    });
    suppliers.value = Array.isArray(response.list) ? response.list : [];
    supplierTotal.value = Number(response.total || 0);
    supplierPage.value = Number(response.page || supplierPage.value);
    supplierPageSize.value = Number(response.page_size || supplierPageSize.value);
  } catch (error) {
    supplierLoadBatchId += 1;
    suppliers.value = [];
    supplierTotal.value = 0;
    MessagePlugin.error(errorMessage(error, '加载提供商失败'));
  } finally {
    supplierLoading.value = false;
  }
}

async function executeSupplierCardAction(row: SupplierRecord, action: SupplierCardAction) {
  const requestAction = String(action.request_action || '').trim();
  if (!requestAction) {
    MessagePlugin.warning('插件动作未配置执行入口');
    return;
  }

  const currentBatchId = supplierLoadBatchId;
  const loadingKey = supplierCardActionLoadingKey(row, action);
  supplierCardActionLoading.value = loadingKey;
  try {
    const response = await supplierApi.executeAction(row.id, requestAction);
    if (currentBatchId !== supplierLoadBatchId) return;
    const card = toPlainRecord(response).card;
    if (card) {
      patchSupplierCard(row.id, card);
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '执行插件动作失败'));
  } finally {
    if (supplierCardActionLoading.value === loadingKey) {
      supplierCardActionLoading.value = null;
    }
  }
}

function patchSupplierCard(supplierId: SupplierRecord['id'], card: unknown) {
  const index = suppliers.value.findIndex((item) => String(item.id) === String(supplierId));
  if (index === -1) return;
  suppliers.value[index] = {
    ...suppliers.value[index],
    card: toPlainRecord(card),
  };
}

function handleSupplierCardAction(row: SupplierRecord, action: SupplierCardAction) {
  if (supplierCardActionDisabled(action)) {
    MessagePlugin.warning(action.disabled_reason || '插件动作暂不可用');
    return;
  }

  if (action.action === 'supplier.batch_connect') {
    void openSupplierBatchDialog(row, action);
    return;
  }

  void executeSupplierCardAction(row, action);
}

function resetSupplierBatchState() {
  supplierBatchProducts.value = [];
  supplierBatchLocalProducts.value = [];
  supplierBatchCategories.value = [];
  supplierBatchCategoryTree.value = [];
  supplierBatchSelectedKeys.value = [];
  supplierBatchTargetGroupKey.value = '';
  supplierBatchResult.value = null;
  supplierBatchAction.value = null;
  supplierBatchRemoteExpandedKeys.value = [];
  supplierBatchLocalExpandedKeys.value = [];
  supplierBatchRemoteExpansionInitialized.value = false;
  supplierBatchLocalExpansionInitialized.value = false;
  Object.assign(supplierBatchForm, {
    default_status: 1,
    default_auto_setup: 1,
    sync_config_options: 1,
  });
}

async function loadSupplierBatchCategories() {
  const response = await productApi.categories();
  supplierBatchCategoryTree.value = response.tree || response.list || [];
  supplierBatchCategories.value = flattenCategories(response.tree || response.list || []).filter((item) =>
    isSelectableProductGroup(item),
  );
  syncSupplierBatchLocalExpandedKeys();
}

function normalizeSupplierBatchProduct(itemValue: unknown): SupplierBatchProduct {
  const item = toPlainRecord(itemValue);
  return {
    ...item,
    id: Number(item.id || item.product_id || 0),
    name: String(item.name || item.product_name || '').trim(),
    type_label: String(item.type_label || item.type_name || item.type || item.billingcycle || '').trim(),
    remote_group_name: String(
      item.remote_group_name || item.group_name || item.second_group_name || item._group_label || '',
    ).trim(),
    is_connected: Boolean(item.is_connected ?? item.is_bound),
    connected_display_name: String(
      item.connected_display_name || item.local_product_name || item.local_product_full_path || '',
    ).trim(),
    local_product_id: Number(item.local_product_id || 0) || null,
    local_product_name: String(item.local_product_name || item.local_product_full_path || '').trim() || null,
    local_group_path: Array.isArray(item.local_group_path)
      ? item.local_group_path.map((name) => String(name || '').trim()).filter(Boolean)
      : [],
  };
}

function buildSupplierBatchProducts(payloadValue: unknown) {
  const payload = toPlainRecord(payloadValue);
  const directProducts = Array.isArray(payload.products) ? payload.products : Array.isArray(payload) ? payload : [];
  if (directProducts.length) {
    return directProducts.map(normalizeSupplierBatchProduct).filter((item) => item.id > 0);
  }

  const groups = Array.isArray(payload.groups) ? payload.groups : [];
  return groups
    .flatMap((groupValue) => {
      const group = toPlainRecord(groupValue);
      const groupLabel = String(group.label || group.name || '').trim();
      const items = Array.isArray(group.items) ? group.items : [];
      return items.map((item) => normalizeSupplierBatchProduct({ ...toPlainRecord(item), _group_label: groupLabel }));
    })
    .filter((item) => item.id > 0);
}

function buildRemoteProductTreeRows(products: SupplierBatchProduct[]): SupplierBatchTreeRow[] {
  const roots: SupplierBatchRemoteGroupNode[] = [];
  const rootMap = new Map<string, SupplierBatchRemoteGroupNode>();

  products.forEach((product) => {
    const groupPath = remoteProductGroupPath(product);
    const segments = groupPath.length ? groupPath : ['未分组'];
    const groupKeyParts: string[] = [];
    let parentNode: SupplierBatchRemoteGroupNode | undefined;
    let siblings = roots;
    let siblingMap = rootMap;

    segments.forEach((segment) => {
      groupKeyParts.push(normalizeSupplierTreeKeyPart(segment));
      const key = `remote-group:${groupKeyParts.join('/')}`;
      let node = siblingMap.get(key);

      if (!node) {
        node = {
          key,
          label: segment,
          level: groupKeyParts.length - 1,
          parentKey: parentNode?.key,
          children: [],
          childMap: new Map<string, SupplierBatchRemoteGroupNode>(),
          products: [],
        };
        siblingMap.set(key, node);
        siblings.push(node);
      }

      parentNode = node;
      siblings = node.children;
      siblingMap = node.childMap;
    });

    parentNode?.products.push(product);
  });

  const rows: SupplierBatchTreeRow[] = [];
  appendRemoteGroupRows(roots, rows);
  return rows;
}

function appendRemoteGroupRows(nodes: SupplierBatchRemoteGroupNode[], rows: SupplierBatchTreeRow[]) {
  nodes.forEach((node) => {
    rows.push({
      key: node.key,
      node_type: 'group',
      level: node.level,
      label: node.label,
      parentKey: node.parentKey,
      count: countRemoteGroupProducts(node),
      hasChildren: node.children.length > 0 || node.products.length > 0,
    });

    appendRemoteGroupRows(node.children, rows);
    node.products.forEach((product) => {
      rows.push({
        key: `remote-product:${product.id}`,
        node_type: 'product',
        level: node.level + 1,
        label: product.name,
        parentKey: node.key,
        productId: product.id,
        product,
      });
    });
  });
}

function countRemoteGroupProducts(node: SupplierBatchRemoteGroupNode): number {
  return node.products.length + node.children.reduce((total, child) => total + countRemoteGroupProducts(child), 0);
}

function normalizeSupplierTreeKeyPart(value: string) {
  return encodeURIComponent(String(value || '未命名').trim() || '未命名');
}

function remoteProductGroupPath(product: SupplierBatchProduct): string[] {
  const pathCandidates = [product.remote_group_path, product.group_path, product.category_path];

  for (const value of pathCandidates) {
    if (Array.isArray(value)) {
      const path = value.map((name) => String(name || '').trim()).filter(Boolean);
      if (path.length) return path;
    }
  }

  return [product.remote_group_name, product.group_name, product.second_group_name, product.type_label]
    .map((name) => String(name || '').trim())
    .filter(Boolean)
    .slice(0, 2);
}

function buildLocalProductTreeRows(groups: ProductCategoryRecord[], products: ProductRecord[]): SupplierBatchTreeRow[] {
  const productsByGroupKey = groupLocalProductsByGroup(products, buildLocalGroupPathKeyMap(groups));
  const rows: SupplierBatchTreeRow[] = [];

  groups.forEach((group) => appendLocalGroupRows(group, 0, rows, productsByGroupKey));

  return rows;
}

function appendLocalGroupRows(
  group: ProductCategoryRecord,
  level: number,
  rows: SupplierBatchTreeRow[],
  productsByGroupKey: Map<string, ProductRecord[]>,
  parentKey?: string,
) {
  const groupKey = productGroupOptionKey(group);
  const children = Array.isArray(group.children) ? group.children : [];
  const groupProducts = groupKey ? productsByGroupKey.get(groupKey) || [] : [];
  const rowKey = `local-group:${groupKey || group.id}`;
  rows.push({
    key: rowKey,
    node_type: 'group',
    level,
    label: String(group.label || group.name || `分类 #${group.id}`).trim(),
    parentKey,
    count: countLocalGroupProducts(group, productsByGroupKey),
    hasChildren: children.length > 0 || groupProducts.length > 0,
    selectable: isSelectableProductGroup(group),
    groupKey,
    group,
  });

  children.forEach((child) => appendLocalGroupRows(child, level + 1, rows, productsByGroupKey, rowKey));
  groupProducts.forEach((product) => {
    rows.push({
      key: `local-product:${product.id}`,
      node_type: 'product',
      level: level + 1,
      label: localProductDisplayName(product),
      parentKey: rowKey,
      localProduct: product,
    });
  });
}

function countLocalGroupProducts(
  group: ProductCategoryRecord,
  productsByGroupKey: Map<string, ProductRecord[]>,
): number {
  const groupKey = productGroupOptionKey(group);
  const ownCount = groupKey ? productsByGroupKey.get(groupKey)?.length || 0 : 0;
  const children = Array.isArray(group.children) ? group.children : [];
  return children.reduce((total, child) => total + countLocalGroupProducts(child, productsByGroupKey), ownCount);
}

function groupLocalProductsByGroup(products: ProductRecord[], groupPathKeyMap: Map<string, string>) {
  const result = new Map<string, ProductRecord[]>();
  products.forEach((product) => {
    const key = localProductGroupKey(product, groupPathKeyMap);
    if (!key) return;
    if (!result.has(key)) result.set(key, []);
    result.get(key)?.push(product);
  });

  return result;
}

function localProductGroupKey(product: ProductRecord, groupPathKeyMap: Map<string, string>) {
  const thirdId = Number(product.third_product_group_id || 0);
  if (thirdId > 0) return `3:${thirdId}`;
  const secondId = Number(product.second_product_group_id || 0);
  if (secondId > 0) return `2:${secondId}`;
  const firstId = Number(product.first_product_group_id || 0);
  if (firstId > 0) return `1:${firstId}`;
  const effectiveId = Number(product.effective_product_group_id || 0);
  const effectiveLevel = Number(product.effective_product_group_level || 0);
  if (effectiveId > 0 && effectiveLevel > 0) return `${effectiveLevel}:${effectiveId}`;

  const pathKey = normalizeLocalGroupPath(localProductGroupPath(product));
  if (pathKey && groupPathKeyMap.has(pathKey)) return groupPathKeyMap.get(pathKey) || '';
  return '';
}

function localProductGroupPath(product: ProductRecord) {
  const explicitPath = product.local_group_path;
  if (Array.isArray(explicitPath)) {
    const path = explicitPath.map((name) => String(name || '').trim()).filter(Boolean);
    if (path.length) return path;
  }

  const fullName = String(product.effective_product_group_full_name || product.category_full_name || '').trim();
  if (fullName) {
    return fullName
      .split(/\s*[/>＞]\s*/)
      .map((name) => name.trim())
      .filter(Boolean);
  }

  return [product.first_product_group_name, product.second_product_group_name, product.third_product_group_name]
    .map((name) => String(name || '').trim())
    .filter(Boolean);
}

function buildLocalGroupPathKeyMap(groups: ProductCategoryRecord[]) {
  const result = new Map<string, string>();
  const append = (items: ProductCategoryRecord[], parentPath: string[]) => {
    items.forEach((item) => {
      const label = String(item.name || item.label || '')
        .replace(/^\u3000+/, '')
        .trim();
      const path = label ? [...parentPath, label] : parentPath;
      const key = normalizeLocalGroupPath(path);
      const groupKey = productGroupOptionKey(item);
      if (key && groupKey) result.set(key, groupKey);
      append(Array.isArray(item.children) ? item.children : [], path);
    });
  };

  append(groups, []);
  return result;
}

function normalizeLocalGroupPath(path: unknown[]) {
  return path
    .map((name) =>
      String(name || '')
        .replace(/^\u3000+/, '')
        .trim(),
    )
    .filter(Boolean)
    .join(' / ');
}

function localProductDisplayName(product?: ProductRecord) {
  if (!product) return '-';
  return (
    String(
      product.display_name ||
        product.product_display_name ||
        product.custom_display_name ||
        product.name ||
        product.id ||
        '-',
    ).trim() || '-'
  );
}

function localProductSubtitle(product?: ProductRecord) {
  if (!product) return '-';
  const upstreamBinding = toPlainRecord(product.upstream_binding);
  const supplierName = String(upstreamBinding.supplier_name || '').trim();
  const upstreamProductId = String(upstreamBinding.upstream_product_id || '').trim();
  if (supplierName && upstreamProductId) return `提供商：${supplierName} · 上游ID：${upstreamProductId}`;
  if (supplierName) return `提供商：${supplierName}`;
  return (
    String(product.effective_product_group_full_name || product.product_type_label || '本地商品').trim() || '本地商品'
  );
}

function visibleSupplierBatchTreeRows(rows: SupplierBatchTreeRow[], expandedKeySet: Set<string>) {
  const rowMap = new Map(rows.map((row) => [row.key, row]));
  return rows
    .filter((row) => isSupplierBatchTreeRowVisible(row, rowMap, expandedKeySet))
    .map((row) => (row.node_type === 'group' ? { ...row, isExpanded: expandedKeySet.has(row.key) } : row));
}

function isSupplierBatchTreeRowVisible(
  row: SupplierBatchTreeRow,
  rowMap: Map<string, SupplierBatchTreeRow>,
  expandedKeySet: Set<string>,
) {
  let parentKey = row.parentKey;
  while (parentKey) {
    if (!expandedKeySet.has(parentKey)) return false;
    parentKey = rowMap.get(parentKey)?.parentKey;
  }
  return true;
}

function expandableSupplierBatchTreeKeys(rows: SupplierBatchTreeRow[]) {
  return rows.filter((row) => row.node_type === 'group' && row.hasChildren).map((row) => row.key);
}

function syncSupplierBatchExpandedKeys(
  expandedKeys: { value: string[] },
  initialized: { value: boolean },
  rows: SupplierBatchTreeRow[],
) {
  const availableKeys = expandableSupplierBatchTreeKeys(rows);
  if (!initialized.value) {
    if (!availableKeys.length) return;
    expandedKeys.value = availableKeys;
    initialized.value = true;
    return;
  }

  const availableKeySet = new Set(availableKeys);
  expandedKeys.value = expandedKeys.value.filter((key) => availableKeySet.has(key));
}

function syncSupplierBatchRemoteExpandedKeys() {
  syncSupplierBatchExpandedKeys(
    supplierBatchRemoteExpandedKeys,
    supplierBatchRemoteExpansionInitialized,
    supplierBatchRemoteRows.value,
  );
}

function syncSupplierBatchLocalExpandedKeys() {
  syncSupplierBatchExpandedKeys(
    supplierBatchLocalExpandedKeys,
    supplierBatchLocalExpansionInitialized,
    supplierBatchConnectedRows.value,
  );
}

function toggleSupplierBatchExpandedKey(expandedKeys: { value: string[] }, key: string) {
  const keys = new Set(expandedKeys.value);
  if (keys.has(key)) {
    keys.delete(key);
  } else {
    keys.add(key);
  }
  expandedKeys.value = Array.from(keys);
}

function toggleSupplierBatchRemoteGroup(key: string) {
  toggleSupplierBatchExpandedKey(supplierBatchRemoteExpandedKeys, key);
}

function toggleSupplierBatchLocalGroup(key: string) {
  toggleSupplierBatchExpandedKey(supplierBatchLocalExpandedKeys, key);
}

function setAllSupplierBatchRemoteExpanded(expanded: boolean) {
  supplierBatchRemoteExpandedKeys.value = expanded
    ? expandableSupplierBatchTreeKeys(supplierBatchRemoteRows.value)
    : [];
  supplierBatchRemoteExpansionInitialized.value = true;
}

function setAllSupplierBatchLocalExpanded(expanded: boolean) {
  supplierBatchLocalExpandedKeys.value = expanded
    ? expandableSupplierBatchTreeKeys(supplierBatchConnectedRows.value)
    : [];
  supplierBatchLocalExpansionInitialized.value = true;
}

function uniqueLocalProducts(products: ProductRecord[]) {
  const result = new Map<string, ProductRecord>();
  products.forEach((product) => {
    const key = String(product.id || '').trim();
    if (key) result.set(key, product);
  });
  return Array.from(result.values());
}

async function loadSupplierBatchLocalProducts() {
  const pageSize = 100;
  const products: ProductRecord[] = [];
  let page = 1;

  try {
    while (true) {
      const response = await productApi.list({
        lifecycle_status: 'active',
        page,
        page_size: pageSize,
      });
      const list = Array.isArray(response.list) ? response.list : [];
      products.push(...list);

      const total = Number(response.total || products.length);
      if (!list.length || products.length >= total) break;
      page += 1;
    }

    supplierBatchLocalProducts.value = uniqueLocalProducts(products);
    syncSupplierBatchLocalExpandedKeys();
  } catch (error) {
    supplierBatchLocalProducts.value = [];
    MessagePlugin.error(errorMessage(error, '加载系统商品失败'));
  }
}

async function loadSupplierBatchProducts() {
  if (!supplierBatchSupplier.value?.id) return;
  supplierBatchLoading.value = true;
  try {
    const response = await supplierApi.products(supplierBatchSupplier.value.id, { silent: true });
    supplierBatchProducts.value = buildSupplierBatchProducts(response);
    const selectableIds = new Set(
      supplierBatchProducts.value.filter((item) => !item.is_connected).map((item) => Number(item.id)),
    );
    supplierBatchSelectedKeys.value = supplierBatchSelectedKeys.value.filter((id) => selectableIds.has(Number(id)));
    syncSupplierBatchRemoteExpandedKeys();
    syncSupplierBatchLocalExpandedKeys();
  } catch (error) {
    supplierBatchProducts.value = [];
    MessagePlugin.error(errorMessage(error, '加载上游商品失败'));
  } finally {
    supplierBatchLoading.value = false;
  }
}

async function openSupplierBatchDialog(row: SupplierRecord, action: SupplierCardAction) {
  if (!canSyncSuppliers.value) return;

  if (supplierCardActionDisabled(action)) {
    MessagePlugin.warning(action.disabled_reason || '插件动作暂不可用');
    return;
  }

  resetSupplierBatchState();
  supplierBatchSupplier.value = row;
  supplierBatchAction.value = action;
  supplierBatchDialogVisible.value = true;
  try {
    await Promise.all([loadSupplierBatchCategories(), loadSupplierBatchProducts(), loadSupplierBatchLocalProducts()]);
    setAllSupplierBatchRemoteExpanded(true);
    setAllSupplierBatchLocalExpanded(true);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载批量对接数据失败'));
  }
}

function selectPendingSupplierBatchProducts() {
  supplierBatchSelectedKeys.value = supplierBatchPendingProducts.value.map((item) => item.id);
}

function handleSupplierBatchProductCheck(productId: number, checked: boolean) {
  if (productId <= 0) return;
  if (supplierBatchProducts.value.some((item) => Number(item.id) === productId && item.is_connected)) return;
  const ids = new Set(supplierBatchSelectedKeys.value.map((id) => Number(id)));
  if (checked) {
    ids.add(productId);
  } else {
    ids.delete(productId);
  }
  supplierBatchSelectedKeys.value = Array.from(ids);
}

function selectSupplierBatchTargetGroup(groupKey: string) {
  supplierBatchTargetGroupKey.value = groupKey;
}

async function reloadSupplierBatchProducts() {
  if (!canSyncSuppliers.value) return;

  await Promise.all([loadSupplierBatchProducts(), loadSupplierBatchLocalProducts()]);
  setAllSupplierBatchRemoteExpanded(true);
  setAllSupplierBatchLocalExpanded(true);
}

function resolveSupplierBatchCategoryPayload() {
  return productGroupPayload(supplierBatchTargetGroup.value);
}

function resolveSupplierBatchTargetFirstGroupCode() {
  const group = supplierBatchTargetGroup.value;
  return String(group?.first_product_group_code || '').trim();
}

async function submitSupplierBatchConnect() {
  if (!canSyncSuppliers.value) return;
  if (!supplierBatchSupplier.value?.id) return;
  const requestAction = String(supplierBatchAction.value?.request_action || '').trim();
  if (!requestAction) {
    MessagePlugin.warning('插件未提供批量对接执行入口');
    return;
  }
  const firstGroupCode = resolveSupplierBatchTargetFirstGroupCode();
  if (!supplierBatchTargetGroup.value || !firstGroupCode) {
    MessagePlugin.warning('请选择右侧当前系统分类');
    return;
  }
  if (!supplierBatchSelectedKeys.value.length) {
    MessagePlugin.warning('请选择上游商品');
    return;
  }

  supplierBatchSubmitting.value = true;
  try {
    const response = await supplierApi.executeAction(supplierBatchSupplier.value.id, requestAction, {
      first_product_group_code: firstGroupCode,
      ...resolveSupplierBatchCategoryPayload(),
      product_ids: selectedProductIdsFromKeys(supplierBatchSelectedKeys.value),
      default_status: Number(supplierBatchForm.default_status || 0),
      default_auto_setup: Number(supplierBatchForm.default_auto_setup || 0),
      sync_config_options: Number(supplierBatchForm.sync_config_options || 0),
    });
    supplierBatchResult.value = toPlainRecord(response);
    MessagePlugin.success('批量对接完成');
    await Promise.all([loadSupplierBatchProducts(), loadSupplierBatchLocalProducts(), loadSuppliers()]);
    setAllSupplierBatchRemoteExpanded(true);
    setAllSupplierBatchLocalExpanded(true);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '批量对接失败'));
  } finally {
    supplierBatchSubmitting.value = false;
  }
}

function selectedProductIdsFromKeys(keys: Array<string | number>) {
  return keys.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0);
}

async function openSupplierDialog(row?: SupplierRecord) {
  if (!providerTypeOptions.value.length) {
    await loadProviderTypes();
  }

  const detail = row?.id ? await loadSupplierDetail(row) : null;
  const source = detail || row;
  const upstreamBinding = toPlainRecord(source?.upstream_binding);
  editingSupplier.value = source || null;
  Object.assign(supplierForm, {
    provider_key: upstreamBinding.provider_key || source?.provider_key || providerTypeOptions.value[0]?.value || '',
    name: source?.name || '',
    status: Number(source?.status ?? 1),
  });
  resetSupplierCredentialValues(source || null);
  supplierDialogVisible.value = true;
}

async function loadSupplierDetail(row: SupplierRecord) {
  try {
    return await supplierApi.detail(row.id);
  } catch {
    return row;
  }
}

async function submitSupplier() {
  if (!canManageSuppliers.value) {
    MessagePlugin.warning('当前账号无供应商管理权限');
    return;
  }

  const validateResult = await supplierFormRef.value?.validate?.();
  if (validateResult !== true) return;
  const credentialValidationMessage = validateSupplierCredentialValues();
  if (credentialValidationMessage) {
    MessagePlugin.warning(credentialValidationMessage);
    return;
  }

  supplierSubmitting.value = true;
  try {
    const payload = buildSupplierPayload();
    if (editingSupplier.value?.id) {
      await supplierApi.update(editingSupplier.value.id, payload);
      MessagePlugin.success('提供商已更新');
    } else {
      await supplierApi.create(payload);
      MessagePlugin.success('提供商已创建');
    }
    supplierDialogVisible.value = false;
    await loadSuppliers();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存提供商失败'));
  } finally {
    supplierSubmitting.value = false;
  }
}

async function handleToggleSupplier(row: SupplierRecord) {
  if (!canManageSuppliers.value) return;

  supplierActionLoading.value = row.id;
  try {
    await supplierApi.toggleStatus(row.id, Number(row.status) !== 1);
    MessagePlugin.success('状态已更新');
    await loadSuppliers();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新状态失败'));
  } finally {
    supplierActionLoading.value = null;
  }
}

function handleDeleteSupplier(row: SupplierRecord) {
  if (!canManageSuppliers.value) return;

  const dialog = DialogPlugin.confirm({
    header: '删除提供商',
    body: `确认删除「${row.name || row.id}」吗？`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    async onConfirm() {
      supplierActionLoading.value = row.id;
      try {
        await supplierApi.delete(row.id);
        MessagePlugin.success('提供商已删除');
        dialog.hide();
        await loadSuppliers();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除提供商失败'));
      } finally {
        supplierActionLoading.value = null;
      }
    },
  });
}

function normalizeProviderTypeOptions(value: unknown): ProviderTypeRecord[] {
  const record = toPlainRecord(value);
  const rawItems = Array.isArray(value)
    ? value
    : Array.isArray(record.list)
      ? record.list
      : Array.isArray(record.options)
        ? record.options
        : Array.isArray(record.items)
          ? record.items
          : Array.isArray(record.types)
            ? record.types
            : Array.isArray(record.provider_types)
              ? record.provider_types
              : record.value
                ? [record]
                : Object.entries(record).map(([entryValue, entryLabel]) => ({ value: entryValue, label: entryLabel }));

  return rawItems
    .map((item) => {
      if (typeof item === 'string') {
        return { value: item, label: providerTypeFallbackLabels[item] || item };
      }

      const rec = toPlainRecord(item);
      const val = String(rec.value ?? rec.key ?? rec.type ?? rec.code ?? '').trim();
      if (!val) return null;

      const rawLabel = rec.label ?? rec.name ?? rec.title;
      const label =
        providerTypeFallbackLabels[val] || (typeof rawLabel === 'string' ? rawLabel : String(rawLabel || val));
      return { ...rec, value: val, label };
    })
    .filter((item): item is ProviderTypeRecord => !!item);
}

function handleSupplierProviderChange() {
  resetSupplierCredentialValues(editingSupplier.value);
}

function resetSupplierCredentialValues(source: SupplierRecord | null) {
  Object.keys(supplierCredentialValues).forEach((key) => {
    delete supplierCredentialValues[key];
  });
  Object.keys(supplierSecretEdited).forEach((key) => {
    delete supplierSecretEdited[key];
  });

  const providerConfig = toPlainRecord(source?.provider_config);
  const upstreamBinding = toPlainRecord(source?.upstream_binding);
  supplierCredentialFields.value.forEach((field) => {
    if (field.key === 'api_url') {
      supplierCredentialValues[field.key] = upstreamBinding.base_url || source?.api_url || field.default || '';
      return;
    }
    if (field.key === 'api_username') {
      supplierCredentialValues[field.key] = upstreamBinding.account_name || source?.api_username || field.default || '';
      return;
    }
    if (field.key === 'api_key') {
      supplierCredentialValues[field.key] = '';
      return;
    }
    supplierCredentialValues[field.key] =
      providerConfig[field.key] ?? field.default ?? defaultSupplierFieldValue(field);
  });
}

function defaultSupplierFieldValue(field: SupplierFormField) {
  if (field.type === 'switch' || field.type === 'boolean') return false;
  if (field.type === 'number') return null;
  return '';
}

function supplierFieldPlaceholder(field: SupplierFormField) {
  if (field.placeholder) return field.placeholder;
  if (field.secret && editingSupplier.value) return '留空则保持原值';
  return `请输入${field.label}`;
}

function hasExistingSupplierSecret(field: SupplierFormField) {
  if (!field.secret || !editingSupplier.value) return false;
  if (field.key === 'api_key') return Boolean(editingSupplier.value.has_api_key);
  return Boolean(editingSupplier.value.has_provider_secret_values?.[field.key]);
}

function secretSupplierFieldValue(field: SupplierFormField) {
  const value = supplierCredentialValues[field.key];
  return value === null || value === undefined ? '' : String(value);
}

function supplierSecretResetKey(field: SupplierFormField) {
  return `${editingSupplier.value?.id || 'new'}:${supplierForm.provider_key}:${field.key}`;
}

async function revealSupplierSecret(field: SupplierFormField) {
  if (!canRevealSupplierSecrets.value) return '';
  if (!editingSupplier.value?.id) return '';
  const response = await supplierApi.revealSecret(editingSupplier.value.id, field.key);
  return response.value;
}

function validateSupplierCredentialValues() {
  for (const field of supplierCredentialFields.value) {
    if (!field.required) continue;
    const value = supplierCredentialValues[field.key];
    const hasExistingSecret = hasExistingSupplierSecret(field);
    if (hasExistingSecret && String(value || '').trim() === '') continue;
    if (String(value ?? '').trim() === '') return `请填写${field.label}`;
  }
  return '';
}

function buildSupplierPayload(): SupplierUpsertPayload {
  const providerConfig: SupplierUpsertPayload['provider_config'] = {};
  const providerKey = String(supplierForm.provider_key || '').trim();
  const payload: SupplierUpsertPayload = {
    name: supplierForm.name,
    status: Number(supplierForm.status),
    api_url: '',
    api_username: '',
    api_key: '',
    provider_config: providerConfig,
    upstream_binding: {
      provider_key: providerKey,
      base_url: '',
      account_name: '',
    },
  };

  supplierCredentialFields.value.forEach((field) => {
    const value =
      field.secret && hasExistingSupplierSecret(field) && !supplierSecretEdited[field.key]
        ? ''
        : supplierCredentialValues[field.key];
    if (field.key === 'api_url') {
      payload.api_url = value;
      if (payload.upstream_binding) payload.upstream_binding.base_url = value;
      return;
    }
    if (field.key === 'api_username') {
      payload.api_username = value;
      if (payload.upstream_binding) payload.upstream_binding.account_name = value;
      return;
    }
    if (field.key === 'api_key') {
      payload.api_key = value;
      return;
    }
    providerConfig[field.key] = value;
  });

  return payload;
}

function normalizeSupplierFormFields(value: unknown): SupplierFormField[] {
  if (!Array.isArray(value)) return [];
  return value
    .map((item) => {
      const record = toPlainRecord(item);
      const key = String(record.key || '').trim();
      if (!key) return null;
      const label = String(record.label || record.title || key).trim();
      const type = normalizeSupplierFieldType(record.type);
      const options = Array.isArray(record.options)
        ? record.options.map((option) => {
            const optionRecord = toPlainRecord(option);
            return {
              label: String(optionRecord.label ?? optionRecord.name ?? optionRecord.value ?? '').trim(),
              value: (optionRecord.value ?? optionRecord.key ?? optionRecord.label ?? '') as string | number | boolean,
            };
          })
        : [];

      const field: SupplierFormField = {
        key,
        label,
        type,
        required: Boolean(record.required),
        secret: Boolean(record.secret) || type === 'password',
        placeholder: String(record.placeholder || '').trim(),
        description: String(record.description || '').trim(),
        default: record.default,
        options,
      };

      return field;
    })
    .filter((item): item is SupplierFormField => Boolean(item));
}

function normalizeSupplierFieldType(value: unknown): SupplierFormField['type'] {
  const type = String(value || 'text').trim();
  if (['text', 'url', 'password', 'select', 'switch', 'boolean', 'number', 'textarea'].includes(type)) {
    return type as SupplierFormField['type'];
  }

  return 'text';
}

// --- Init ---
function loadSupplierTab() {
  void loadSuppliers();
}

onMounted(loadSupplierTab);
</script>
