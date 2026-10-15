<template>
  <t-card :bordered="false">
    <div class="filter-row supplier-filter-row">
      <t-input
        v-model="supplierFilters.keyword"
        class="supplier-filter-keyword"
        clearable
        :placeholder="$t('products.suppliers.searchPlaceholder')"
        @enter="handleSupplierSearch"
        @clear="handleSupplierSearch"
      >
        <template #suffix-icon><search-icon /></template>
      </t-input>
      <t-select
        v-model="supplierFilters.status"
        class="supplier-filter-status"
        clearable
        :placeholder="$t('products.suppliers.statusPlaceholder')"
        @change="handleSupplierSearch"
      >
        <t-option :value="1" :label="$t('products.suppliers.enabled')" />
        <t-option :value="0" :label="$t('products.suppliers.disabled')" />
      </t-select>
      <div class="supplier-filter-actions">
        <t-button v-if="canManageSuppliers" theme="primary" @click="openSupplierDialog()">
          <template #icon><add-icon /></template>
          {{ $t('products.suppliers.addProvider') }}
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
            canManageSuppliers ? $t('products.suppliers.edit') : $t('products.suppliers.view')
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
            {{ Number(row.status) === 1 ? $t('products.suppliers.disable') : $t('products.suppliers.enable') }}
          </t-button>
          <t-button
            v-if="canManageSuppliers"
            size="small"
            variant="text"
            theme="danger"
            @click="handleDeleteSupplier(row)"
            >{{ $t('products.suppliers.delete') }}</t-button
          >
        </div>
      </article>
      <t-empty v-if="!supplierLoading && suppliers.length === 0" :description="$t('products.suppliers.empty')" />
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
    :header="
      supplierBatchSupplier?.name
        ? `${$t('products.suppliers.batchConnect')} · ${supplierBatchSupplier.name}`
        : $t('products.suppliers.batchConnect')
    "
    width="1080px"
    :confirm-btn="
      canSyncSuppliers ? { content: $t('products.suppliers.runConnect'), loading: supplierBatchSubmitting } : null
    "
    @confirm="submitSupplierBatchConnect"
  >
    <div class="split-dialog-intro">
      <strong>{{ $t('products.suppliers.batchTransferTitle') }}</strong>
      <p>{{ $t('products.suppliers.batchTransferIntro', { plugin: supplierBatchPluginLabel }) }}</p>
    </div>

    <t-form class="supplier-batch-form" :data="supplierBatchForm" label-width="90px">
      <div class="supplier-batch-settings">
        <t-form-item :label="$t('products.suppliers.defaultListed')">
          <t-switch v-model="supplierBatchForm.default_status" :custom-value="[1, 0]" />
        </t-form-item>
        <t-form-item :label="$t('products.suppliers.autoProvision')">
          <t-switch v-model="supplierBatchForm.default_auto_setup" :custom-value="[1, 0]" />
        </t-form-item>
        <t-form-item :label="$t('products.suppliers.syncConfigOptions')">
          <t-switch v-model="supplierBatchForm.sync_config_options" :custom-value="[1, 0]" />
        </t-form-item>
      </div>
    </t-form>

    <div class="supplier-batch-toolbar">
      <span>
        {{
          $t('products.suppliers.selectedUnconnected', {
            selected: supplierBatchSelectedKeys.length,
            total: supplierBatchPendingProducts.length,
          })
        }}
        <template v-if="supplierBatchTargetGroupLabel">
          {{ $t('products.suppliers.connectTo', { group: supplierBatchTargetGroupLabel }) }}
        </template>
      </span>
      <t-space size="small">
        <t-button size="small" variant="outline" :loading="supplierBatchLoading" @click="reloadSupplierBatchProducts">{{
          $t('products.suppliers.refreshProducts')
        }}</t-button>
        <t-button
          size="small"
          variant="text"
          :disabled="!supplierBatchPendingProducts.length"
          @click="selectPendingSupplierBatchProducts"
        >
          {{ $t('products.suppliers.selectUnconnected') }}
        </t-button>
        <t-button
          size="small"
          variant="text"
          :disabled="!supplierBatchSelectedKeys.length"
          @click="supplierBatchSelectedKeys = []"
        >
          {{ $t('products.suppliers.clear') }}
        </t-button>
      </t-space>
    </div>

    <div class="supplier-batch-transfer">
      <section class="supplier-batch-panel">
        <div class="supplier-batch-panel__head">
          <div class="supplier-batch-panel__title">
            <strong>{{ $t('products.suppliers.unconnected') }}</strong>
            <span>{{ supplierBatchPluginLabel }}{{ $t('products.suppliers.productStructure') }}</span>
          </div>
          <div class="supplier-batch-panel__actions">
            <t-tag variant="light" theme="warning">{{
              $t('products.suppliers.unconnectedCount', {
                pending: supplierBatchPendingProducts.length,
                total: supplierBatchProducts.length,
              })
            }}</t-tag>
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchRemoteRows.length"
              @click="setAllSupplierBatchRemoteExpanded(true)"
            >
              {{ $t('products.suppliers.expand') }}
            </t-button>
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchRemoteRows.length"
              @click="setAllSupplierBatchRemoteExpanded(false)"
            >
              {{ $t('products.suppliers.collapse') }}
            </t-button>
          </div>
        </div>
        <div class="supplier-batch-panel__search">
          <t-input
            v-model="supplierBatchRemoteKeyword"
            size="small"
            clearable
            :placeholder="$t('products.suppliers.remoteSearchPlaceholder')"
          >
            <template #prefix-icon><search-icon /></template>
          </t-input>
          <span v-if="supplierBatchRemoteKeyword.trim()" class="supplier-batch-panel__search-hint">
            {{ $t('products.suppliers.matchCount', { count: supplierBatchRemoteMatchCount }) }}
          </span>
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
                :aria-label="
                  row.isExpanded
                    ? $t('products.suppliers.collapseWith') + row.label
                    : $t('products.suppliers.expandWith') + row.label
                "
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
                    :aria-label="`${row.product?.name || $t('products.suppliers.productFallback')}${$t('products.suppliers.toggleSelectSuffix')}`"
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
                      <template v-if="row.product?.is_connected"> · {{ $t('products.suppliers.connected') }}</template>
                    </span>
                  </div>
                </div>
              </template>
            </div>
          </template>
          <t-empty
            v-else
            :description="
              supplierBatchLoading
                ? $t('products.suppliers.loadingRemoteProducts')
                : $t('products.suppliers.emptyRemoteProducts')
            "
          />
        </div>
      </section>

      <div class="supplier-batch-transfer__action">
        <t-button
          theme="primary"
          :disabled="!supplierBatchSelectedKeys.length || !supplierBatchTargetGroupKey"
          :loading="supplierBatchSubmitting"
          @click="submitSupplierBatchConnect"
        >
          {{ $t('products.suppliers.connect') }}
        </t-button>
      </div>

      <section class="supplier-batch-panel">
        <div class="supplier-batch-panel__head">
          <div class="supplier-batch-panel__title">
            <strong>{{ $t('products.suppliers.connected') }}</strong>
            <span>{{ $t('products.suppliers.localProductStructure') }}</span>
          </div>
          <div class="supplier-batch-panel__actions">
            <t-tag variant="light" theme="success">{{ supplierBatchLocalProducts.length }}</t-tag>
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchConnectedRows.length"
              @click="setAllSupplierBatchLocalExpanded(true)"
            >
              {{ $t('products.suppliers.expand') }}
            </t-button>
            <t-button
              size="small"
              variant="text"
              :disabled="!supplierBatchConnectedRows.length"
              @click="setAllSupplierBatchLocalExpanded(false)"
            >
              {{ $t('products.suppliers.collapse') }}
            </t-button>
          </div>
        </div>
        <div class="supplier-batch-panel__search">
          <t-input
            v-model="supplierBatchLocalKeyword"
            size="small"
            clearable
            :placeholder="$t('products.suppliers.localSearchPlaceholder')"
          >
            <template #prefix-icon><search-icon /></template>
          </t-input>
          <span v-if="supplierBatchLocalKeyword.trim()" class="supplier-batch-panel__search-hint">
            {{ $t('products.suppliers.matchCount', { count: supplierBatchLocalMatchCount }) }}
          </span>
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
                :aria-label="
                  row.isExpanded
                    ? $t('products.suppliers.collapseWith') + row.label
                    : $t('products.suppliers.expandWith') + row.label
                "
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
          <t-empty v-else :description="$t('products.suppliers.emptyLocalCategories')" />
        </div>
      </section>
    </div>

    <div v-if="supplierBatchResult" class="supplier-batch-result">
      {{
        $t('products.suppliers.resultSummary', {
          created: supplierBatchResult.created_count || 0,
          updated: supplierBatchResult.updated_count || 0,
          skipped: supplierBatchResult.skipped_count || 0,
        })
      }}
    </div>
  </t-dialog>

  <t-dialog
    v-model:visible="supplierDialogVisible"
    class="supplier-form-dialog"
    :header="editingSupplier ? $t('products.suppliers.editProvider') : $t('products.suppliers.addProviderTitle')"
    width="620px"
    :confirm-btn="canManageSuppliers ? { content: $t('products.suppliers.save'), loading: supplierSubmitting } : null"
    @confirm="submitSupplier"
  >
    <!-- 标签在上、输入框在下：字段说明较长（如密钥的 scope 提示），
         左右两列会把可用宽度压掉一半导致换行错位 -->
    <t-form
      ref="supplierFormRef"
      :data="supplierForm"
      :rules="supplierRules"
      label-align="top"
      :disabled="!canManageSuppliers"
    >
      <t-form-item :label="$t('products.suppliers.pluginProvider')" name="provider_key">
        <t-select v-model="supplierForm.provider_key" filterable @change="handleSupplierProviderChange">
          <t-option v-for="item in providerTypeOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </t-form-item>
      <t-form-item :label="$t('products.suppliers.interfaceName')" name="name"
        ><t-input v-model="supplierForm.name"
      /></t-form-item>
      <div v-if="selectedSupplierFormHelp" class="supplier-form-help">{{ selectedSupplierFormHelp }}</div>
      <t-form-item v-for="field in supplierCredentialFields" :key="field.key" :label="field.label" :name="field.key">
        <t-select
          v-if="field.type === 'select'"
          v-model="supplierCredentialValues[field.key]"
          :placeholder="field.placeholder || $t('products.suppliers.selectFieldPrefix') + field.label"
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
          :model-value="supplierCredentialValues[field.key] as boolean"
          @update:model-value="(value: unknown) => (supplierCredentialValues[field.key] = value as boolean)"
        />
        <t-input-number
          v-else-if="field.type === 'number'"
          :model-value="supplierCredentialValues[field.key] as number | null"
          :placeholder="field.placeholder || $t('products.suppliers.inputFieldPrefix') + field.label"
          style="width: 100%"
          @update:model-value="(value: unknown) => (supplierCredentialValues[field.key] = value as number | null)"
        />
        <t-textarea
          v-else-if="field.type === 'textarea'"
          :model-value="supplierCredentialValues[field.key] as string | number | null"
          :autosize="{ minRows: 3, maxRows: 6 }"
          :placeholder="field.placeholder || $t('products.suppliers.inputFieldPrefix') + field.label"
          @update:model-value="
            (value: unknown) => (supplierCredentialValues[field.key] = value as string | number | null)
          "
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
          :model-value="supplierCredentialValues[field.key] as string | number | null"
          :type="field.type === 'password' ? 'password' : 'text'"
          clearable
          :placeholder="supplierFieldPlaceholder(field)"
          @update:model-value="
            (value: unknown) => (supplierCredentialValues[field.key] = value as string | number | null)
          "
        />
        <p v-if="field.description" class="supplier-field-tip">{{ field.description }}</p>
      </t-form-item>
      <t-form-item :label="$t('products.suppliers.lowBalanceAlert')" name="low_balance_alert_enabled">
        <t-switch v-model="supplierForm.low_balance_alert_enabled" />
        <p class="supplier-field-tip">{{ $t('products.suppliers.lowBalanceAlertHint') }}</p>
      </t-form-item>
      <t-form-item :label="$t('products.suppliers.lowBalanceThreshold')" name="low_balance_threshold">
        <t-input-number
          v-model="supplierForm.low_balance_threshold"
          :min="0"
          :max="99999999"
          :decimal-places="2"
          style="width: 100%"
        />
        <p class="supplier-field-tip">{{ $t('products.suppliers.lowBalanceThresholdHint') }}</p>
      </t-form-item>
      <t-form-item :label="$t('products.suppliers.status')" name="status"
        ><t-switch v-model="supplierForm.status" :custom-value="[1, 0]"
      /></t-form-item>
    </t-form>
  </t-dialog>
</template>
<script setup lang="ts">
import { AddIcon, ChevronRightIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { FormRules, PageInfo, TagProps } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

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
import { t } from '@/locales';
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

type ThemeType = NonNullable<TagProps['theme']>;
type TagVariantType = 'outline' | 'light' | 'dark' | 'light-outline';
type ButtonVariantType = 'base' | 'text' | 'outline' | 'dashed';
type TDesignSupplierCardStatus = Required<SupplierCardStatus> & { theme: ThemeType; variant: TagVariantType };
type TDesignSupplierCardAction = SupplierCardAction & { theme?: ThemeType; variant?: ButtonVariantType };

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
// 弹窗文案曾写死「ZJMF 财务」，换成任意上游插件后说明与事实不符，改为按当前供应商的插件名渲染
const supplierBatchPluginLabel = computed(
  () => String(supplierBatchSupplier.value?.provider_label || '').trim() || '上游',
);
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
const supplierBatchRemoteKeyword = ref('');
const supplierBatchLocalKeyword = ref('');
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
  low_balance_threshold: 20 as number | null,
  low_balance_alert_enabled: true,
});
const supplierCredentialValues = reactive<Record<string, string | number | boolean | null>>({});
const supplierSecretEdited = reactive<Record<string, boolean>>({});
const canManageSuppliers = computed(() => hasAdminPermission(AdminPermissions.SUPPLIER_MANAGE));
const canSyncSuppliers = computed(() => hasAdminPermission(AdminPermissions.SUPPLIER_SYNC));
const canBulkConnectSupplierProducts = computed(() => hasAdminPermission(AdminPermissions.PRODUCT_SYNC));
const canRevealSupplierSecrets = computed(() => hasAdminPermission(AdminPermissions.SUPPLIER_SECRET_REVEAL));

const supplierRules: FormRules<typeof supplierForm> = {
  provider_key: [{ required: true, message: t('products.suppliers.providerRequired'), trigger: 'change' }],
  name: [{ required: true, message: t('products.suppliers.interfaceNameRequired'), trigger: 'blur' }],
};

const fallbackSupplierCredentialFields: SupplierFormField[] = [
  {
    key: 'api_url',
    label: t('products.suppliers.apiUrlLabel'),
    type: 'url',
    required: true,
    placeholder: 'https://panel.example.com',
  },
  {
    key: 'api_username',
    label: t('products.suppliers.apiAccountLabel'),
    type: 'text',
    required: true,
  },
  {
    key: 'api_key',
    label: t('products.suppliers.apiSecretLabel'),
    type: 'password',
    required: true,
    secret: true,
    placeholder: t('products.suppliers.apiSecretPlaceholder'),
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
const supplierBatchRemoteFilteredRows = computed(() =>
  filterSupplierBatchTreeRows(supplierBatchRemoteRows.value, supplierBatchRemoteKeyword.value),
);
const supplierBatchLocalFilteredRows = computed(() =>
  filterSupplierBatchTreeRows(supplierBatchConnectedRows.value, supplierBatchLocalKeyword.value),
);
// 搜索态同样按展开状态渲染：命中项的祖先会在关键字变化时自动展开（见下方 watch），
// 既不会让命中项藏在折叠分组里，又保留了手动折叠的能力——早先搜索时直接返回过滤结果，
// 折叠状态被完全绕过，点箭头没有任何反应，看起来就像按钮失灵。
const supplierBatchVisibleRemoteRows = computed(() =>
  visibleSupplierBatchTreeRows(
    supplierBatchRemoteKeyword.value.trim() ? supplierBatchRemoteFilteredRows.value : supplierBatchRemoteRows.value,
    supplierBatchRemoteExpandedKeySet.value,
  ),
);
const supplierBatchVisibleConnectedRows = computed(() =>
  visibleSupplierBatchTreeRows(
    supplierBatchLocalKeyword.value.trim() ? supplierBatchLocalFilteredRows.value : supplierBatchConnectedRows.value,
    supplierBatchLocalExpandedKeySet.value,
  ),
);
// 关键字变化时把命中项的祖先补进展开集合，保证结果默认可见；只做并集，不覆盖用户
// 已有的展开选择，因此搜索后仍可自由折叠任意分组。
watch(supplierBatchRemoteKeyword, () => {
  if (!supplierBatchRemoteKeyword.value.trim()) return;
  supplierBatchRemoteExpandedKeys.value = mergeSupplierBatchExpandedKeys(
    supplierBatchRemoteExpandedKeys.value,
    supplierBatchRemoteFilteredRows.value,
  );
});
watch(supplierBatchLocalKeyword, () => {
  if (!supplierBatchLocalKeyword.value.trim()) return;
  supplierBatchLocalExpandedKeys.value = mergeSupplierBatchExpandedKeys(
    supplierBatchLocalExpandedKeys.value,
    supplierBatchLocalFilteredRows.value,
  );
});

const supplierBatchRemoteMatchCount = computed(
  () => supplierBatchRemoteFilteredRows.value.filter((row) => row.node_type === 'product').length,
);
const supplierBatchLocalMatchCount = computed(
  () => supplierBatchLocalFilteredRows.value.filter((row) => row.node_type === 'product').length,
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

function supplierCardStatus(row: SupplierRecord): TDesignSupplierCardStatus {
  const status = toPlainRecord(supplierCard(row).status);
  return {
    label: String(status.label || '').trim(),
    theme: toThemeType(status.theme || 'default'),
    variant: toTagVariantType(status.variant || 'light'),
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

function canRunSupplierCardAction(action: SupplierCardAction) {
  if (!canSyncSuppliers.value) return false;
  const requestAction = String(action.request_action || '').trim();
  if (requestAction === 'server.supplier.bulk_connect' || action.action === 'supplier.batch_connect') {
    return canBulkConnectSupplierProducts.value;
  }

  return true;
}

function supplierCardActions(row: SupplierRecord): TDesignSupplierCardAction[] {
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
        theme: toThemeType(record.theme || 'default'),
        variant: toButtonVariantType(record.variant || 'text'),
        disabled: Boolean(record.disabled),
        disabled_reason: String(record.disabled_reason || '').trim(),
      };
    })
    .filter(
      (action) =>
        String(action.key || '').trim() &&
        String(action.action || '').trim() &&
        String(action.label || '').trim() &&
        canRunSupplierCardAction(action),
    );
}

function toThemeType(value: unknown): ThemeType {
  const theme = String(value || 'default').trim();
  if (theme === 'warning' || theme === 'success' || theme === 'primary' || theme === 'danger') return theme;
  return 'default';
}

function toTagVariantType(value: unknown): TagVariantType {
  const variant = String(value || 'light').trim();
  if (variant === 'outline' || variant === 'dark' || variant === 'light-outline') return variant;
  return 'light';
}

function toButtonVariantType(value: unknown): ButtonVariantType {
  const variant = String(value || 'text').trim();
  if (variant === 'base' || variant === 'outline' || variant === 'dashed') return variant;
  return 'text';
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
    MessagePlugin.warning(t('products.suppliers.pluginActionNoEntry'));
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
  if (!canRunSupplierCardAction(action)) {
    MessagePlugin.warning(t('products.suppliers.pluginActionForbidden'));
    return;
  }

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
  supplierBatchRemoteKeyword.value = '';
  supplierBatchLocalKeyword.value = '';
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

/**
 * 判断一行是否命中关键字。
 * 上游侧匹配商品名、类型、上游分组名与上游商品 ID（运维常直接拿 ID 找货）；
 * 本地侧匹配展示名与分类名——右栏本身就是「选导入位置」，按分类搜同样要能用。
 */
function supplierBatchRowMatches(row: SupplierBatchTreeRow, keyword: string): boolean {
  const candidates: Array<string | number | undefined> = [
    row.label,
    row.product?.name,
    row.product?.type_label,
    row.product?.remote_group_name,
    row.product?.id,
    row.localProduct ? localProductDisplayName(row.localProduct) : undefined,
  ];

  return candidates.some((item) =>
    String(item ?? '')
      .toLowerCase()
      .includes(keyword),
  );
}

/**
 * 按关键字过滤树行，并回溯保留命中行的全部祖先分组。
 * 不保留祖先的话，命中商品会脱离层级、右栏还会失去可选的父分类。
 */
function filterSupplierBatchTreeRows(rows: SupplierBatchTreeRow[], keyword: string): SupplierBatchTreeRow[] {
  const normalized = keyword.trim().toLowerCase();
  if (!normalized) return rows;

  const rowByKey = new Map(rows.map((row) => [row.key, row]));
  const kept = new Set<string>();

  rows.forEach((row) => {
    if (!supplierBatchRowMatches(row, normalized)) return;
    kept.add(row.key);

    let parentKey = row.parentKey;
    while (parentKey && !kept.has(parentKey)) {
      kept.add(parentKey);
      parentKey = rowByKey.get(parentKey)?.parentKey;
    }
  });

  return rows.filter((row) => kept.has(row.key));
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

function mergeSupplierBatchExpandedKeys(current: string[], rows: SupplierBatchTreeRow[]) {
  const keys = new Set(current);
  expandableSupplierBatchTreeKeys(rows).forEach((key) => keys.add(key));

  return Array.from(keys);
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
  if (!canRunSupplierCardAction(action)) {
    MessagePlugin.warning(t('products.suppliers.batchConnectForbidden'));
    return;
  }

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
  if (!canRunSupplierCardAction(supplierBatchAction.value || ({} as SupplierCardAction))) return;

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
  if (!canRunSupplierCardAction(supplierBatchAction.value || ({} as SupplierCardAction))) {
    MessagePlugin.warning(t('products.suppliers.batchConnectForbidden'));
    return;
  }
  if (!supplierBatchSupplier.value?.id) return;
  const requestAction = String(supplierBatchAction.value?.request_action || '').trim();
  if (!requestAction) {
    MessagePlugin.warning(t('products.suppliers.pluginBatchNoEntry'));
    return;
  }
  const firstGroupCode = resolveSupplierBatchTargetFirstGroupCode();
  if (!supplierBatchTargetGroup.value || !firstGroupCode) {
    MessagePlugin.warning(t('products.suppliers.selectTargetCategory'));
    return;
  }
  if (!supplierBatchSelectedKeys.value.length) {
    MessagePlugin.warning(t('products.suppliers.selectRemoteProduct'));
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
    MessagePlugin.success(t('products.suppliers.batchConnectDone'));
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
  const balance = toPlainRecord(source?.balance_setting);
  Object.assign(supplierForm, {
    provider_key: upstreamBinding.provider_key || source?.provider_key || providerTypeOptions.value[0]?.value || '',
    name: source?.name || '',
    status: Number(source?.status ?? 1),
    // 新建供应商时给出默认阈值 20；编辑时回填已保存的配置
    low_balance_threshold:
      balance.low_balance_threshold === undefined || balance.low_balance_threshold === null
        ? 20
        : Number(balance.low_balance_threshold),
    low_balance_alert_enabled:
      balance.low_balance_alert_enabled === undefined ? true : Boolean(balance.low_balance_alert_enabled),
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
    MessagePlugin.warning(t('products.suppliers.noPermission'));
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
      MessagePlugin.success(t('products.suppliers.providerUpdated'));
    } else {
      await supplierApi.create(payload);
      MessagePlugin.success(t('products.suppliers.providerCreated'));
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
    MessagePlugin.success(t('products.suppliers.statusUpdated'));
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
    confirmBtn: t('common.confirmDelete'),
    cancelBtn: t('common.cancel'),
    async onConfirm() {
      supplierActionLoading.value = row.id;
      try {
        await supplierApi.delete(row.id);
        MessagePlugin.success(t('products.suppliers.providerDeleted'));
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
      supplierCredentialValues[field.key] = normalizeSupplierCredentialValue(
        upstreamBinding.base_url || source?.api_url || field.default || '',
      );
      return;
    }
    if (field.key === 'api_username') {
      supplierCredentialValues[field.key] = normalizeSupplierCredentialValue(
        upstreamBinding.account_name || source?.api_username || field.default || '',
      );
      return;
    }
    if (field.key === 'api_key') {
      supplierCredentialValues[field.key] = '';
      return;
    }
    supplierCredentialValues[field.key] = normalizeSupplierCredentialValue(
      providerConfig[field.key] ?? field.default ?? defaultSupplierFieldValue(field),
    );
  });
}

function normalizeSupplierCredentialValue(value: unknown): string | number | boolean | null {
  if (typeof value === 'string') return value;
  if (typeof value === 'number') return value;
  if (typeof value === 'boolean') return value;
  if (value === null) return null;
  if (value === undefined) return '';
  return String(value);
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
    low_balance_threshold:
      supplierForm.low_balance_threshold === null ? undefined : Number(supplierForm.low_balance_threshold),
    low_balance_alert_enabled: Boolean(supplierForm.low_balance_alert_enabled),
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
