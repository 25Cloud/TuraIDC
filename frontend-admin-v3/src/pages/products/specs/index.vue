<template>
  <div class="specs-page">
    <t-alert
      theme="info"
      message="这里管理的是实例规格文本本身。绑定配置后只保存关联关系，不会自动改动原始商品数据。"
    />

    <t-card :bordered="false">
      <div class="specs-filter">
        <t-input
          v-model="filters.keyword"
          clearable
          placeholder="搜索规格文本 / 别名 / 说明 / 绑定配置"
          @enter="loadCatalog"
          @clear="loadCatalog"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select v-model="filters.binding_status" clearable placeholder="绑定状态" @change="loadCatalog">
          <t-option value="bound" label="已绑定" />
          <t-option value="unbound" label="未绑定" />
        </t-select>
        <t-button theme="primary" variant="outline" @click="openSpecDialog()">
          <template #icon><add-icon /></template>
          新增规格
        </t-button>
        <t-button theme="primary" :loading="saving" @click="handleSave">保存目录</t-button>
      </div>

      <div class="table-scroll">
        <t-table row-key="id" :data="specs" :columns="columns" :loading="loading" hover table-layout="fixed">
          <template #spec="{ row }">
            <div class="spec-cell">
              <strong>{{ row.text || '-' }}</strong>
              <span>{{ row.alias || '未填写别名' }}</span>
            </div>
          </template>
          <template #note="{ row }">
            <span class="muted">{{ row.note || '-' }}</span>
          </template>
          <template #status="{ row }">
            <t-tag :theme="row.status === '隐藏' ? 'default' : 'success'" variant="light">
              {{ row.status || '展示中' }}
            </t-tag>
          </template>
          <template #bindings="{ row }">
            <product-binding-tree-select
              v-model="row.binding_ids"
              mode="batch"
              :existing-bindings="row.bindings"
              @change="handleBindingSelectionChange(row, $event)"
            />
          </template>
          <template #operation="{ row }">
            <t-space size="small">
              <t-button size="small" variant="text" theme="primary" @click="openSpecDialog(row)">编辑</t-button>
              <t-button size="small" variant="text" theme="danger" @click="handleDeleteSpec(row)">删除</t-button>
            </t-space>
          </template>
        </t-table>
      </div>

      <p class="specs-footer">规格目录会统一保存到后台设置中，绑定配置仅保存关联信息。</p>
    </t-card>

    <t-dialog
      v-model:visible="specDialogVisible"
      :header="editingSpecId ? '编辑实例规格' : '新增实例规格'"
      width="540px"
      :confirm-btn="{ content: '确认', loading: dialogSubmitting }"
      @confirm="handleSubmitSpec"
      @closed="handleDialogClosed"
    >
      <t-form ref="specFormRef" :data="specForm" :rules="specRules" label-align="top">
        <t-form-item label="实例规格文本" name="text">
          <t-input v-model="specForm.text" maxlength="80" placeholder="例如：ecs.g9i.2c2g" />
        </t-form-item>
        <t-form-item label="别名" name="alias">
          <t-input v-model="specForm.alias" maxlength="80" placeholder="例如：2 核 2G" />
        </t-form-item>
        <t-form-item label="说明" name="note">
          <t-textarea
            v-model="specForm.note"
            :autosize="{ minRows: 3, maxRows: 5 }"
            maxlength="255"
            placeholder="例如：入门款实例规格"
          />
        </t-form-item>
        <t-form-item label="状态" name="status">
          <t-select v-model="specForm.status">
            <t-option value="展示中" label="展示中" />
            <t-option value="隐藏" label="隐藏" />
          </t-select>
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { AddIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { onMounted, reactive, ref } from 'vue';

import type { InstanceSpecRecord, ProductBindingRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';
import { normalizeProductBindings } from '@/hooks/useProductBindingTree';
import { errorMessage } from '@/utils/userMessage';

type SpecRecord = Omit<
  InstanceSpecRecord,
  'id' | 'value' | 'text' | 'alias' | 'note' | 'status' | 'sort_order' | 'bindings' | 'binding_ids'
> & {
  id: string;
  value: string;
  text: string;
  alias: string;
  note: string;
  status: string;
  sort_order: number;
  bindings: ProductBindingRecord[];
  binding_ids: string[];
};

const loading = ref(false);
const saving = ref(false);
const dialogSubmitting = ref(false);
const specs = ref<SpecRecord[]>([]);
const specDialogVisible = ref(false);
const editingSpecId = ref('');
const specFormRef = ref<FormInstanceFunctions>();

const filters = reactive({
  keyword: '',
  binding_status: '',
});

const specForm = reactive({
  text: '',
  value: '',
  alias: '',
  note: '',
  status: '展示中',
});

const specRules: Record<string, FormRule[]> = {
  text: [{ required: true, message: '请输入实例规格文本', type: 'error' }],
};

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'spec', title: '实例规格文本', minWidth: 220 },
  { colKey: 'note', title: '说明', minWidth: 220, ellipsis: true },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'bindings', title: '绑定配置', minWidth: 360 },
  { colKey: 'operation', title: '操作', width: 150, fixed: 'right' },
];

function createLocalId(prefix: string) {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

function slugify(value: unknown) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9\u4E00-\u9FA5]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 60);
}

function normalizeSpec(itemValue: unknown, index = 0): SpecRecord {
  const item = toPlainRecord(itemValue);
  const bindings = normalizeProductBindings(item.bindings);
  const text = String(item.text || item.name || '').trim();
  return {
    ...item,
    id: String(item.id || createLocalId('spec')),
    value: String(item.value || slugify(text || item.alias || `spec-${index + 1}`)),
    text,
    alias: String(item.alias || '').trim(),
    note: String(item.note || '').trim(),
    status: String(item.status || '展示中').trim() || '展示中',
    sort_order: Number(item.sort_order || index + 1),
    bindings,
    binding_ids: bindings
      .map((binding) => String(binding.product_id || '').trim())
      .filter((id) => /^\d+$/.test(id) && Number(id) > 0),
  };
}

function rebuildCatalogState(list: unknown[]) {
  specs.value = list.map((item, index) => normalizeSpec(item, index));
}

async function loadCatalog() {
  loading.value = true;
  try {
    const response = await adminApi.instanceSpecCatalog.list({
      keyword: String(filters.keyword || '').trim() || undefined,
      binding_status: filters.binding_status || undefined,
    });
    rebuildCatalogState(response.list || []);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载实例规格失败'));
  } finally {
    loading.value = false;
  }
}

function openSpecDialog(spec?: SpecRecord) {
  editingSpecId.value = spec?.id || '';
  Object.assign(specForm, {
    text: spec?.text || '',
    value: spec?.value || '',
    alias: spec?.alias || '',
    note: spec?.note || '',
    status: spec?.status || '展示中',
  });
  specDialogVisible.value = true;
}

function handleDialogClosed() {
  specFormRef.value?.clearValidate?.();
}

async function handleSubmitSpec() {
  const validateResult = await specFormRef.value?.validate?.();
  if (validateResult !== true) return;

  const text = String(specForm.text || '').trim();
  const duplicated = specs.value.find((item) => item.text === text && item.id !== editingSpecId.value);
  if (duplicated) {
    MessagePlugin.warning('实例规格文本不能重复');
    return;
  }

  dialogSubmitting.value = true;
  try {
    const nextValue = String(specForm.value || '').trim() || slugify(text);
    if (editingSpecId.value) {
      specs.value = specs.value.map((item, index) =>
        item.id === editingSpecId.value
          ? {
              ...item,
              text,
              value: nextValue,
              alias: String(specForm.alias || '').trim(),
              note: String(specForm.note || '').trim(),
              status: String(specForm.status || '展示中').trim() || '展示中',
              sort_order: index + 1,
            }
          : item,
      );
    } else {
      specs.value = [
        ...specs.value,
        normalizeSpec(
          {
            id: createLocalId('spec'),
            text,
            value: nextValue,
            alias: specForm.alias,
            note: specForm.note,
            status: specForm.status,
            bindings: [],
          },
          specs.value.length,
        ),
      ];
    }
    MessagePlugin.success(editingSpecId.value ? '实例规格已更新' : '实例规格已添加');
    specDialogVisible.value = false;
  } finally {
    dialogSubmitting.value = false;
  }
}

function handleDeleteSpec(row: SpecRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除实例规格',
    body: `确认删除「${row.text || row.id}」吗？`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    onConfirm() {
      specs.value = specs.value.filter((item) => item.id !== row.id);
      MessagePlugin.success('实例规格已删除');
      dialog.hide();
    },
  });
}

function handleBindingSelectionChange(
  row: SpecRecord,
  payload: { binding_ids: string[]; bindings: ProductBindingRecord[] },
) {
  row.bindings = payload.bindings;
  row.binding_ids = payload.binding_ids;
}

function buildPayload(): InstanceSpecRecord[] {
  return specs.value.map((spec, index) => ({
    id: spec.id,
    value: String(spec.value || '').trim() || slugify(spec.text),
    text: String(spec.text || '').trim(),
    alias: String(spec.alias || '').trim(),
    note: String(spec.note || '').trim(),
    status: String(spec.status || '展示中').trim() || '展示中',
    sort_order: index + 1,
    bindings: spec.bindings,
  }));
}

async function handleSave() {
  if (!specs.value.length) {
    MessagePlugin.warning('请至少添加一个实例规格');
    return;
  }
  saving.value = true;
  try {
    const response = await adminApi.instanceSpecCatalog.save({ list: buildPayload() });
    rebuildCatalogState(response.list || []);
    MessagePlugin.success('实例规格目录已保存');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存实例规格失败'));
  } finally {
    saving.value = false;
  }
}

function toPlainRecord(value: unknown) {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

onMounted(() => {
  void loadCatalog();
});
</script>
