<template>
  <div class="cpu-models-page">
    <div class="page-inline-actions">
      <t-button theme="primary" :loading="saving" @click="handleSave">
        {{ $t('products.cpuModels.saveCatalog') }}
      </t-button>
    </div>

    <div class="cpu-layout">
      <t-card :bordered="false">
        <div class="group-head">
          <strong>{{ $t('products.cpuModels.groupTitle') }}</strong>
          <t-button theme="primary" variant="outline" @click="openGroupDialog()">
            <template #icon><add-icon /></template>
            {{ $t('products.cpuModels.addGroup') }}
          </t-button>
        </div>

        <div v-if="groups.length" class="cpu-group-list">
          <article
            v-for="group in groups"
            :key="group.id"
            class="cpu-group-item"
            :class="{ active: activeGroupId === group.id }"
          >
            <button type="button" @click="activeGroupId = group.id">
              <strong>{{ group.name || '-' }}</strong>
              <span>{{
                $t('products.cpuModels.modelCount', { count: group.model_count || group.models.length })
              }}</span>
            </button>
            <t-space size="small">
              <t-button size="small" variant="text" theme="primary" @click="openGroupDialog(group)">
                {{ $t('products.cpuModels.edit') }}
              </t-button>
              <t-button size="small" variant="text" theme="danger" @click="handleDeleteGroup(group)">
                {{ $t('products.cpuModels.delete') }}
              </t-button>
            </t-space>
          </article>
        </div>
        <t-empty v-else :description="$t('products.cpuModels.emptyGroups')">
          <template #action>
            <t-button theme="primary" @click="openGroupDialog()">
              {{ $t('products.cpuModels.addFirstGroup') }}
            </t-button>
          </template>
        </t-empty>
      </t-card>

      <t-card :bordered="false">
        <div class="model-head">
          <div>
            <h2>{{ $t('products.cpuModels.modelTitle') }}</h2>
            <p>
              {{
                currentGroup
                  ? $t('products.cpuModels.currentGroup', { name: currentGroup.name })
                  : $t('products.cpuModels.selectGroupFirst')
              }}
            </p>
          </div>
          <t-button theme="primary" variant="outline" :disabled="!currentGroup" @click="openModelDialog()">
            <template #icon><add-icon /></template>
            {{ $t('products.cpuModels.addModel') }}
          </t-button>
        </div>

        <template v-if="currentGroup">
          <div class="table-scroll">
            <t-table
              row-key="id"
              :data="currentGroup.models"
              :columns="columns"
              :loading="loading"
              hover
              table-layout="fixed"
            >
              <template #frequency="{ row }">
                <div class="frequency-summary">
                  <span>{{ $t('products.cpuModels.baseFrequency') }}{{ displayFrequency(row.base_frequency) }}</span>
                  <span>{{ $t('products.cpuModels.turboFrequency') }}{{ displayFrequency(row.turbo_frequency) }}</span>
                </div>
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
                  <t-button size="small" variant="text" theme="primary" @click="openModelDialog(row)">
                    {{ $t('products.cpuModels.edit') }}
                  </t-button>
                  <t-button size="small" variant="text" theme="danger" @click="handleDeleteModel(row)">
                    {{ $t('products.cpuModels.delete') }}
                  </t-button>
                </t-space>
              </template>
            </t-table>
          </div>
          <p class="cpu-footer">{{ $t('products.cpuModels.footer', { count: currentGroup.models.length }) }}</p>
        </template>
        <t-empty v-else :description="$t('products.cpuModels.selectGroupFirst')" />
      </t-card>
    </div>

    <t-dialog
      v-model:visible="groupDialogVisible"
      :header="editingGroupId ? $t('products.cpuModels.editGroupTitle') : $t('products.cpuModels.addGroupTitle')"
      width="520px"
      :confirm-btn="{ content: $t('products.cpuModels.confirm'), loading: groupSubmitting }"
      @confirm="handleSubmitGroup"
    >
      <t-form ref="groupFormRef" :data="groupForm" :rules="groupRules" label-align="top">
        <t-form-item :label="$t('products.cpuModels.groupName')" name="name">
          <t-input
            v-model="groupForm.name"
            maxlength="80"
            :placeholder="$t('products.cpuModels.groupNamePlaceholder')"
          />
        </t-form-item>
        <t-form-item :label="$t('products.cpuModels.internalKey')" name="value">
          <t-input
            v-model="groupForm.value"
            maxlength="60"
            :placeholder="$t('products.cpuModels.internalKeyPlaceholder')"
          />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="modelDialogVisible"
      :header="editingModelId ? $t('products.cpuModels.editModelTitle') : $t('products.cpuModels.addModelTitle')"
      width="520px"
      :confirm-btn="{ content: $t('products.cpuModels.confirm'), loading: modelSubmitting }"
      @confirm="handleSubmitModel"
    >
      <t-form ref="modelFormRef" :data="modelForm" :rules="modelRules" label-align="top">
        <t-form-item :label="$t('products.cpuModels.modelName')" name="name">
          <t-input
            v-model="modelForm.name"
            maxlength="80"
            :placeholder="$t('products.cpuModels.modelNamePlaceholder')"
          />
        </t-form-item>
        <t-form-item :label="$t('products.cpuModels.internalKey')" name="value">
          <t-input
            v-model="modelForm.value"
            maxlength="60"
            :placeholder="$t('products.cpuModels.internalKeyPlaceholder')"
          />
        </t-form-item>
        <t-form-item :label="$t('products.cpuModels.baseFrequency')" name="base_frequency">
          <t-input
            v-model="modelForm.base_frequency"
            maxlength="40"
            :placeholder="$t('products.cpuModels.frequencyPlaceholder')"
          >
            <template #suffix>{{ FREQUENCY_UNIT }}</template>
          </t-input>
        </t-form-item>
        <t-form-item :label="$t('products.cpuModels.turboFrequency')" name="turbo_frequency">
          <t-input
            v-model="modelForm.turbo_frequency"
            maxlength="40"
            :placeholder="$t('products.cpuModels.frequencyPlaceholder')"
          >
            <template #suffix>{{ FREQUENCY_UNIT }}</template>
          </t-input>
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { AddIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import type { CpuModelGroupRecord, CpuModelRecord, ProductBindingRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';
import { normalizeProductBindings as normalizeBindings } from '@/hooks/useProductBindingTree';
import { t } from '@/locales';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminCpuModels' });

const FREQUENCY_UNIT = 'GHz';

type CpuModel = Omit<
  CpuModelRecord,
  'id' | 'value' | 'name' | 'base_frequency' | 'turbo_frequency' | 'sort_order' | 'bindings' | 'binding_ids'
> & {
  id: string;
  value: string;
  name: string;
  base_frequency: string;
  turbo_frequency: string;
  sort_order: number;
  bindings: ProductBindingRecord[];
  binding_ids: string[];
};

type CpuGroup = Omit<CpuModelGroupRecord, 'id' | 'value' | 'name' | 'sort_order' | 'model_count' | 'models'> & {
  id: string;
  value: string;
  name: string;
  sort_order: number;
  model_count: number;
  models: CpuModel[];
};

const loading = ref(false);
const saving = ref(false);
const groupSubmitting = ref(false);
const modelSubmitting = ref(false);
const groups = ref<CpuGroup[]>([]);
const activeGroupId = ref('');
const groupDialogVisible = ref(false);
const modelDialogVisible = ref(false);
const editingGroupId = ref('');
const editingModelId = ref('');
const groupFormRef = ref<FormInstanceFunctions>();
const modelFormRef = ref<FormInstanceFunctions>();

const groupForm = reactive({ name: '', value: '' });
const modelForm = reactive({ name: '', value: '', base_frequency: '', turbo_frequency: '' });

const groupRules: Record<string, FormRule[]> = {
  name: [{ required: true, message: t('products.cpuModels.groupNameRequired'), type: 'error' }],
};
const modelRules: Record<string, FormRule[]> = {
  name: [{ required: true, message: t('products.cpuModels.modelNameRequired'), type: 'error' }],
};

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'name', title: t('products.cpuModels.modelName'), minWidth: 220 },
  { colKey: 'frequency', title: t('products.cpuModels.frequency'), minWidth: 180 },
  { colKey: 'value', title: t('products.cpuModels.internalKey'), minWidth: 180 },
  { colKey: 'sort_order', title: t('products.cpuModels.sortOrder'), width: 90 },
  { colKey: 'bindings', title: t('products.cpuModels.bindingConfig'), minWidth: 360 },
  { colKey: 'operation', title: t('common.action'), width: 140, fixed: 'right' },
];

const currentGroup = computed(() => groups.value.find((item) => item.id === activeGroupId.value) || null);

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

function stripFrequencyUnit(value: unknown) {
  return String(value || '')
    .trim()
    .replace(/\s*GHz$/i, '');
}

function buildFrequencyPayload(value: unknown) {
  const normalized = stripFrequencyUnit(value);
  if (!normalized) return '';
  return /^[+-]?\d+(?:\.\d+)?$/.test(normalized) ? `${normalized}${FREQUENCY_UNIT}` : normalized;
}

function displayFrequency(value: unknown) {
  const normalized = stripFrequencyUnit(value);
  if (!normalized) return '-';
  return /^[+-]?\d+(?:\.\d+)?$/.test(normalized) ? `${normalized}${FREQUENCY_UNIT}` : normalized;
}

function normalizeModel(modelValue: unknown, index = 0): CpuModel {
  const model = toPlainRecord(modelValue);
  const bindings = normalizeBindings(model.bindings);
  const name = String(model.name || '').trim();
  return {
    ...model,
    id: String(model.id || createLocalId('model')),
    value: String(model.value || slugify(name || `model-${index + 1}`)),
    name,
    base_frequency: stripFrequencyUnit(model.base_frequency),
    turbo_frequency: stripFrequencyUnit(model.turbo_frequency),
    sort_order: Number(model.sort_order || index + 1),
    bindings,
    binding_ids: bindings
      .map((binding) => String(binding.product_id || '').trim())
      .filter((id) => /^\d+$/.test(id) && Number(id) > 0),
  };
}

function normalizeGroup(groupValue: unknown, index = 0): CpuGroup {
  const group = toPlainRecord(groupValue);
  const rawModels = Array.isArray(group.models) ? group.models : [];
  const models = rawModels.map((model, modelIndex) => normalizeModel(model, modelIndex));
  const name = String(group.name || '').trim();
  return {
    ...group,
    id: String(group.id || createLocalId('group')),
    value: String(group.value || slugify(name || `group-${index + 1}`)),
    name,
    sort_order: Number(group.sort_order || index + 1),
    model_count: Number(group.model_count || models.length),
    models,
  };
}

function rebuildCatalogState(list: unknown[]) {
  groups.value = list.map((item, index) => normalizeGroup(item, index));
  if (!groups.value.length) {
    activeGroupId.value = '';
    return;
  }
  if (!groups.value.some((item) => item.id === activeGroupId.value)) {
    activeGroupId.value = groups.value[0].id;
  }
}

async function loadCatalog() {
  loading.value = true;
  try {
    const response = await adminApi.cpuModelCatalog.list();
    rebuildCatalogState(response.list || []);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.cpuModels.loadFailed')));
  } finally {
    loading.value = false;
  }
}

function openGroupDialog(group?: CpuGroup) {
  editingGroupId.value = group?.id || '';
  Object.assign(groupForm, {
    name: group?.name || '',
    value: group?.value || '',
  });
  groupDialogVisible.value = true;
}

function openModelDialog(model?: CpuModel) {
  if (!currentGroup.value) {
    MessagePlugin.warning(t('products.cpuModels.selectGroupRequired'));
    return;
  }
  editingModelId.value = model?.id || '';
  Object.assign(modelForm, {
    name: model?.name || '',
    value: model?.value || '',
    base_frequency: stripFrequencyUnit(model?.base_frequency),
    turbo_frequency: stripFrequencyUnit(model?.turbo_frequency),
  });
  modelDialogVisible.value = true;
}

async function handleSubmitGroup() {
  const validateResult = await groupFormRef.value?.validate?.();
  if (validateResult !== true) return;
  const name = String(groupForm.name || '').trim();
  const duplicated = groups.value.find((item) => item.name === name && item.id !== editingGroupId.value);
  if (duplicated) {
    MessagePlugin.warning(t('products.cpuModels.duplicateGroupName'));
    return;
  }

  groupSubmitting.value = true;
  try {
    if (editingGroupId.value) {
      groups.value = groups.value.map((item, index) =>
        item.id === editingGroupId.value
          ? {
              ...item,
              name,
              value: String(groupForm.value || '').trim() || slugify(name),
              sort_order: index + 1,
            }
          : item,
      );
    } else {
      const nextGroup = normalizeGroup(
        {
          id: createLocalId('group'),
          name,
          value: String(groupForm.value || '').trim() || slugify(name),
          models: [],
        },
        groups.value.length,
      );
      groups.value = [...groups.value, nextGroup];
      activeGroupId.value = nextGroup.id;
    }
    groupDialogVisible.value = false;
    MessagePlugin.success(
      editingGroupId.value ? t('products.cpuModels.groupUpdated') : t('products.cpuModels.groupCreated'),
    );
  } finally {
    groupSubmitting.value = false;
  }
}

async function handleSubmitModel() {
  const validateResult = await modelFormRef.value?.validate?.();
  if (validateResult !== true) return;
  if (!currentGroup.value) return;

  const name = String(modelForm.name || '').trim();
  const duplicated = currentGroup.value.models.find((item) => item.name === name && item.id !== editingModelId.value);
  if (duplicated) {
    MessagePlugin.warning(t('products.cpuModels.duplicateModelName'));
    return;
  }

  modelSubmitting.value = true;
  try {
    const groupId = currentGroup.value.id;
    groups.value = groups.value.map((group) => {
      if (group.id !== groupId) return group;
      const nextModel = {
        id: editingModelId.value || createLocalId('model'),
        value: String(modelForm.value || '').trim() || slugify(name),
        name,
        base_frequency: stripFrequencyUnit(modelForm.base_frequency),
        turbo_frequency: stripFrequencyUnit(modelForm.turbo_frequency),
        sort_order: group.models.length + 1,
        bindings: [] as ProductBindingRecord[],
        binding_ids: [] as string[],
      };
      const models = editingModelId.value
        ? group.models.map((item, index) =>
            item.id === editingModelId.value ? { ...item, ...nextModel, sort_order: index + 1 } : item,
          )
        : [...group.models, nextModel];
      return {
        ...group,
        model_count: models.length,
        models,
      };
    });
    modelDialogVisible.value = false;
    MessagePlugin.success(
      editingModelId.value ? t('products.cpuModels.modelUpdated') : t('products.cpuModels.modelCreated'),
    );
  } finally {
    modelSubmitting.value = false;
  }
}

function handleDeleteGroup(group: CpuGroup) {
  const dialog = DialogPlugin.confirm({
    header: t('products.cpuModels.deleteGroupTitle'),
    body: t('products.cpuModels.deleteGroupConfirm', { name: group.name || group.id }),
    theme: 'warning',
    confirmBtn: t('common.confirmDelete'),
    cancelBtn: t('common.cancel'),
    onConfirm() {
      rebuildCatalogState(groups.value.filter((item) => item.id !== group.id));
      MessagePlugin.success(t('products.cpuModels.groupDeleted'));
      dialog.hide();
    },
  });
}

function handleDeleteModel(model: CpuModel) {
  if (!currentGroup.value) return;
  const groupId = currentGroup.value.id;
  const dialog = DialogPlugin.confirm({
    header: t('products.cpuModels.deleteModelTitle'),
    body: t('products.cpuModels.deleteModelConfirm', { name: model.name || model.id }),
    theme: 'warning',
    confirmBtn: t('common.confirmDelete'),
    cancelBtn: t('common.cancel'),
    onConfirm() {
      groups.value = groups.value.map((group) => {
        if (group.id !== groupId) return group;
        const models = group.models
          .filter((item) => item.id !== model.id)
          .map((item, index) => ({ ...item, sort_order: index + 1 }));
        return {
          ...group,
          model_count: models.length,
          models,
        };
      });
      MessagePlugin.success(t('products.cpuModels.modelDeleted'));
      dialog.hide();
    },
  });
}

function handleBindingSelectionChange(
  row: CpuModel,
  payload: { binding_ids: string[]; bindings: ProductBindingRecord[] },
) {
  row.binding_ids = payload.binding_ids;
  row.bindings = payload.bindings;
}

function buildPayload(): CpuModelGroupRecord[] {
  return groups.value.map((group, groupIndex) => ({
    id: group.id,
    value: String(group.value || '').trim() || slugify(group.name),
    name: String(group.name || '').trim(),
    sort_order: groupIndex + 1,
    models: group.models.map((model, modelIndex) => ({
      id: model.id,
      value: String(model.value || '').trim() || slugify(model.name),
      name: String(model.name || '').trim(),
      base_frequency: buildFrequencyPayload(model.base_frequency),
      turbo_frequency: buildFrequencyPayload(model.turbo_frequency),
      sort_order: modelIndex + 1,
      bindings: model.bindings,
    })),
  }));
}

async function handleSave() {
  if (!groups.value.length) {
    MessagePlugin.warning(t('products.cpuModels.groupRequired'));
    return;
  }
  saving.value = true;
  try {
    const response = await adminApi.cpuModelCatalog.save({ list: buildPayload() });
    rebuildCatalogState(response.list || []);
    MessagePlugin.success(t('products.cpuModels.catalogSaved'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.cpuModels.saveFailed')));
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
