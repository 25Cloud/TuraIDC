<template>
  <div class="auto-refund-product-rules-page">
    <t-card :bordered="false">
      <div class="product-rules-filter">
        <t-input
          v-model="filters.keyword"
          class="filter-keyword"
          clearable
          :placeholder="$t('autoRefund.productRules.filterKeyword')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select
          v-model="filters.status"
          class="filter-status"
          :placeholder="$t('autoRefund.productRules.filterStatus')"
          @change="handleSearch"
        >
          <t-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </div>

      <div class="admin-list-toolbar">
        <admin-view-mode-switch v-if="!isMobile" inline :mode="viewMode" @change="setViewMode" />
      </div>

      <div v-if="!isMobile && !isCardView" class="table-scroll">
        <t-table row-key="product_id" :data="list" :columns="columns" :loading="loading" hover table-layout="fixed">
          <template #productId="{ row }">
            <span class="product-rule-mono">{{ fieldValue(row.product_id) }}</span>
          </template>
          <template #productName="{ row }">
            <button type="button" class="product-rule-link" @click="openEditor(row)">
              <strong>{{ fieldValue(row.product_name) }}</strong>
            </button>
          </template>
          <template #groupName="{ row }">{{ fieldValue(row.group_name) }}</template>
          <template #refundType="{ row }">
            <t-tag theme="primary" variant="light">{{ refundTypeLabel(row.refund_type) }}</t-tag>
          </template>
          <template #refundRule="{ row }">{{ refundRuleLabel(row.refund_rule) }}</template>
          <template #requirement="{ row }">{{ requirementLabel(row.requirement) }}</template>
          <template #windowHours="{ row }">{{ windowHoursText(row) }}</template>
          <template #status="{ row }">
            <status-tag :status-map="statusMap" :status="productRuleStatusOf(row)" />
          </template>
          <template #updatedAt="{ row }">
            <span class="product-rule-time">{{ row.configured ? formatDateTime(row.updated_at) : '-' }}</span>
          </template>
          <template #operation="{ row }">
            <t-button v-if="canManage" size="small" variant="text" theme="primary" @click="openEditor(row)">
              {{ $t('autoRefund.productRules.edit') }}
            </t-button>
            <span v-else>-</span>
          </template>
        </t-table>
      </div>

      <div v-if="isMobile || isCardView" class="product-rule-mobile-list">
        <t-loading :loading="loading" size="small">
          <div
            v-if="list.length"
            class="product-rule-mobile-stack"
            :style="isCardView && !isMobile ? ADMIN_CARD_GRID_STYLE : undefined"
          >
            <mobile-record-card
              v-for="row in list"
              :key="row.product_id"
              :title="fieldValue(row.product_name)"
              :eyebrow="cardEyebrow(row)"
              :subtitle="row.group_name || ''"
              :description="refundTypeLabel(row.refund_type)"
              :status-map="statusMap"
              :status="productRuleStatusOf(row)"
              :rows="cardRows(row)"
              :action-options="cardActionOptions(row)"
              linkable
              :link-disabled="!canManage"
              @title-click="openEditor(row)"
              @action="() => openEditor(row)"
            />
          </div>
          <t-empty v-else :description="$t('autoRefund.productRules.empty')" />
        </t-loading>
      </div>

      <div v-if="total > 0" class="pagination-row">
        <t-pagination
          :current="pagination.page"
          :page-size="pagination.page_size"
          :total="total"
          :page-size-options="pagination.pageSizeOptions"
          show-jumper
          @change="handlePaginationChange"
        />
      </div>
    </t-card>

    <!-- 策略编辑器：四种类型 × 三种规则 × 三种要求，每个取值旁边都要有人话说明 -->
    <t-drawer
      v-model:visible="editor.visible"
      :header="$t('autoRefund.productRules.editTitle', { name: fieldValue(editor.row?.product_name) })"
      :size="drawerSize"
      :confirm-btn="{ content: $t('autoRefund.productRules.save'), loading: editor.saving }"
      :cancel-btn="$t('common.cancel')"
      @confirm="submitEditor"
      @close="closeEditor"
    >
      <t-form ref="editorFormRef" :data="editor.form" :rules="editorRules" label-align="top">
        <p class="product-rule-target">{{ editorTargetText }}</p>

        <t-form-item :label="$t('autoRefund.productRules.refundType')" name="refund_type">
          <t-radio-group v-model="editor.form.refund_type" class="product-rule-options">
            <div v-for="option in refundTypeChoices" :key="option.value" class="product-rule-option">
              <t-radio :value="option.value">{{ option.label }}</t-radio>
              <span class="product-rule-option__hint">{{ option.hint }}</span>
            </div>
          </t-radio-group>
        </t-form-item>

        <t-form-item :label="$t('autoRefund.productRules.refundRule')" name="refund_rule">
          <t-radio-group v-model="editor.form.refund_rule" class="product-rule-options">
            <div v-for="option in refundRuleChoices" :key="option.value" class="product-rule-option">
              <t-radio :value="option.value">{{ option.label }}</t-radio>
              <span class="product-rule-option__hint">{{ option.hint }}</span>
            </div>
          </t-radio-group>
        </t-form-item>

        <t-form-item :label="$t('autoRefund.productRules.requirement')" name="requirement">
          <t-radio-group v-model="editor.form.requirement" class="product-rule-options">
            <div v-for="option in requirementChoices" :key="option.value" class="product-rule-option">
              <t-radio :value="option.value">{{ option.label }}</t-radio>
              <span class="product-rule-option__hint">{{ option.hint }}</span>
            </div>
          </t-radio-group>
        </t-form-item>

        <!-- 只有「指定时间内」才需要小时数：联动禁用由 requirement 决定，不自造一套开关 -->
        <t-form-item :label="$t('autoRefund.productRules.windowHours')" name="window_hours">
          <div class="product-rule-window">
            <t-input-number
              v-model="editor.form.window_hours"
              theme="normal"
              :min="1"
              :step="1"
              :decimal-places="0"
              :disabled="!windowHoursEditable"
              :placeholder="$t('autoRefund.productRules.windowHoursPlaceholder')"
            />
            <span class="product-rule-window__unit">{{ $t('autoRefund.productRules.hoursUnit') }}</span>
          </div>
          <template #help>
            <span>{{ windowHoursHint }}</span>
          </template>
        </t-form-item>

        <t-form-item :label="$t('autoRefund.productRules.enabled')" name="enabled">
          <t-switch v-model="editor.form.enabled" />
          <template #help>
            <span>{{ $t('autoRefund.productRules.enabledHint') }}</span>
          </template>
        </t-form-item>
      </t-form>
    </t-drawer>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { SearchIcon } from 'tdesign-icons-vue-next';
import type { DropdownOption, FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, nextTick, reactive, ref } from 'vue';

import { adminApi } from '@/api/admin';
import type { AutoRefundProductRule, AutoRefundProductRulePayload } from '@/api/admin/autoRefund';
import AdminViewModeSwitch from '@/components/admin-view-mode-switch/index.vue';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import StatusTag from '@/components/status-tag/index.vue';
import { ADMIN_CARD_GRID_STYLE, useAdminViewMode } from '@/composables/useAdminViewMode';
import { useListPage } from '@/hooks/useListPage';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { fieldValue, formatDateTime } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

import {
  productRuleStatusMap,
  productRuleStatusOf,
  refundRuleLabel,
  refundRuleOptions,
  refundTypeLabel,
  refundTypeOptions,
  requirementLabel,
  requirementOptions,
} from '../enums';

defineOptions({ name: 'AdminAutoRefundProductRules' });

/**
 * 退款商品策略（按商品复刻上游 AutoRefund 的 type / rules / request 三项配置）。
 *
 * 契约：执行计划第二阶段「第二阶段接口增量」——列表 `GET /product-rules`，
 * 保存 `PUT /product-rules/{product}`，请求体键名由契约钉死，不要改名。
 *
 * 权限：路由与查询走 `invoice.list`（能看账单就能看策略），保存走 `invoice.manage`。
 * 插件不能自注册权限码，所以这里不引入 `auto_refund.*`。
 */
const AUTO_REFUND_MANAGE = 'invoice.manage';

const isMobile = useMediaQuery('(max-width: 768px)');
const {
  mode: viewMode,
  isCard: isCardView,
  setMode: setViewMode,
} = useAdminViewMode('admin-auto-refund-product-rules', 'list');

const canManage = computed(() => hasAdminPermission(AUTO_REFUND_MANAGE));
const statusMap = computed(() => productRuleStatusMap());

const statusOptions = computed(() => [
  { value: 'all', label: t('autoRefund.productRules.statusOptions.all') },
  { value: 'configured', label: t('autoRefund.productRules.statusOptions.configured') },
  { value: 'unconfigured', label: t('autoRefund.productRules.statusOptions.unconfigured') },
]);

const { filters, list, total, loading, pagination, handleSearch, handlePaginationChange, loadList } = useListPage<
  { keyword: string; status: string },
  AutoRefundProductRule
>({
  fetch: (params) =>
    adminApi.autoRefund.productRules({
      page: params.page,
      page_size: params.page_size,
      keyword: params.keyword.trim() || undefined,
      status: params.status || 'all',
    }),
  defaultFilters: { keyword: '', status: 'all' },
  onError: (error) => MessagePlugin.error(errorMessage(error, t('autoRefund.productRules.loadFailed'))),
});

const columns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { colKey: 'productId', title: t('autoRefund.productRules.columns.productId'), width: 92 },
  { colKey: 'productName', title: t('autoRefund.productRules.columns.productName'), minWidth: 160 },
  { colKey: 'groupName', title: t('autoRefund.productRules.columns.groupName'), minWidth: 130 },
  { colKey: 'refundType', title: t('autoRefund.productRules.columns.refundType'), width: 118 },
  { colKey: 'refundRule', title: t('autoRefund.productRules.columns.refundRule'), width: 104 },
  { colKey: 'requirement', title: t('autoRefund.productRules.columns.requirement'), width: 112 },
  { colKey: 'windowHours', title: t('autoRefund.productRules.columns.windowHours'), width: 104, align: 'right' },
  { colKey: 'status', title: t('common.status'), width: 96 },
  { colKey: 'updatedAt', title: t('autoRefund.productRules.columns.updatedAt'), width: 152, align: 'right' },
  { colKey: 'operation', title: t('common.action'), width: 80, fixed: 'right', align: 'right' },
]);

/** 时间窗口只对「指定时间内」有意义：其它要求下窗口值不参与判定，显示 '-' 免得被误读 */
function windowHoursText(row: AutoRefundProductRule): string {
  if (row.requirement !== 'within_hours') return '-';
  if (!row.window_hours) return '-';

  return String(row.window_hours);
}

function cardEyebrow(row: AutoRefundProductRule): string {
  return `${t('autoRefund.productRules.columns.productId')} ${fieldValue(row.product_id)}`;
}

function cardRows(row: AutoRefundProductRule) {
  return [
    { label: t('autoRefund.productRules.columns.refundRule'), value: refundRuleLabel(row.refund_rule) },
    { label: t('autoRefund.productRules.columns.requirement'), value: requirementLabel(row.requirement) },
    { label: t('autoRefund.productRules.columns.windowHours'), value: windowHoursText(row) },
    {
      label: t('autoRefund.productRules.columns.updatedAt'),
      value: row.configured ? formatDateTime(row.updated_at) : '-',
    },
  ];
}

function cardActionOptions(row: AutoRefundProductRule): DropdownOption[] {
  if (!canManage.value) return [];

  return [{ content: t('autoRefund.productRules.edit'), value: 'edit', disabled: !row.product_id }];
}

/* ===== 策略编辑器 ===== */

interface EditorForm {
  refund_type: string;
  refund_rule: string;
  requirement: string;
  window_hours: number | undefined;
  enabled: boolean;
}

const editorFormRef = ref<FormInstanceFunctions>();
const drawerSize = computed(() => (isMobile.value ? '100%' : '560px'));

const editor = reactive({
  visible: false,
  saving: false,
  row: null as AutoRefundProductRule | null,
  form: createForm(),
});

const refundTypeChoices = computed(() => refundTypeOptions());
const refundRuleChoices = computed(() => refundRuleOptions());
const requirementChoices = computed(() => requirementOptions());

const windowHoursEditable = computed(() => editor.form.requirement === 'within_hours');

const windowHoursHint = computed(() =>
  windowHoursEditable.value
    ? t('autoRefund.productRules.windowEnabledHint')
    : t('autoRefund.productRules.windowDisabledHint'),
);

const editorTargetText = computed(() => {
  const row = editor.row;
  if (!row) return '';

  return t('autoRefund.productRules.target', {
    id: fieldValue(row.product_id),
    name: fieldValue(row.product_name),
    // 商品没有一级分组时不要把 '-' 拼进句子
    group: row.group_name ? ` · ${row.group_name}` : '',
  });
});

/** 未配置的商品按后端建表默认值预填，省得运营每次从零选一遍 */
function createForm(): EditorForm {
  return {
    refund_type: 'manual',
    refund_rule: 'duration',
    requirement: 'product_first',
    window_hours: undefined,
    enabled: true,
  };
}

const editorRules = computed<Record<string, FormRule[]>>(() => {
  // 只有「指定时间内」才校验小时数：其它要求下输入框是禁用态，校验它只会挡住保存
  if (!windowHoursEditable.value) return {};

  return {
    window_hours: [
      required(t('autoRefund.productRules.windowHoursRequired')),
      {
        validator: (value: unknown) => Number(value) > 0,
        message: t('autoRefund.productRules.windowHoursInvalid'),
        type: 'error',
        trigger: 'blur',
      },
    ],
  };
});

function openEditor(row: AutoRefundProductRule) {
  if (!canManage.value) {
    MessagePlugin.warning(t('autoRefund.productRules.noManagePermission'));
    return;
  }

  editor.row = row;
  editor.form = row.configured
    ? {
        refund_type: row.refund_type || 'manual',
        refund_rule: row.refund_rule || 'duration',
        requirement: row.requirement || 'product_first',
        window_hours: row.window_hours ?? undefined,
        enabled: row.enabled,
      }
    : createForm();
  editor.visible = true;
  void nextTick(() => editorFormRef.value?.clearValidate?.());
}

function closeEditor() {
  editor.visible = false;
  editor.row = null;
}

/** TDesign 校验通过时会 resolve true（无规则时可能是 undefined），失败返回 { errors } */
function isValidationPass(result: unknown) {
  return result === true || result === undefined;
}

async function submitEditor() {
  const row = editor.row;
  if (!row) return;
  if (!canManage.value) {
    MessagePlugin.warning(t('autoRefund.productRules.noManagePermission'));
    return;
  }

  const result = await editorFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;

  const payload: AutoRefundProductRulePayload = {
    refund_type: editor.form.refund_type,
    refund_rule: editor.form.refund_rule,
    requirement: editor.form.requirement,
    // 非「指定时间内」一律写 0：窗口值只在 within_hours 下生效，留着旧数字会误导对账
    window_hours: windowHoursEditable.value ? Number(editor.form.window_hours ?? 0) : 0,
    enabled: editor.form.enabled,
  };

  editor.saving = true;
  try {
    await adminApi.autoRefund.saveProductRule(row.product_id, payload);
    MessagePlugin.success(t('autoRefund.productRules.saved'));
    editor.visible = false;
    editor.row = null;
    await loadList();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('autoRefund.productRules.saveFailed')));
  } finally {
    editor.saving = false;
  }
}
</script>
