<template>
  <t-tree-select
    v-model="localValue"
    class="binding-tree-select"
    :class="{ 'binding-tree-select--compact': props.compact }"
    :data="treeOptions"
    :tree-props="treeProps"
    :loading="treeLoading"
    :disabled="props.disabled || treeLoading"
    :multiple="props.mode !== 'single'"
    filterable
    clearable
    :min-collapsed-num="minCollapsedNum"
    :placeholder="placeholderText"
    :popup-props="treePopupProps"
    :collapsed-items="collapsedItems"
    @change="handleChange"
  />
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { ProductBindingRecord } from '@/api/admin';
import type { BindingTreeMode } from '@/hooks/useProductBindingTree';
import { useProductBindingTree } from '@/hooks/useProductBindingTree';

const props = withDefaults(
  defineProps<{
    modelValue: string | number | (string | number)[] | null | undefined;
    existingBindings?: ProductBindingRecord[];
    mode?: BindingTreeMode;
    placeholder?: string;
    compact?: boolean;
    hideTypeGroup?: boolean;
    expandAll?: boolean;
    popupMaxHeight?: number;
    disabled?: boolean;
  }>(),
  {
    mode: 'multiple',
    placeholder: '',
    compact: false,
    hideTypeGroup: false,
    expandAll: false,
    popupMaxHeight: 360,
    disabled: false,
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: (string | number)[]];
  change: [payload: { binding_ids: string[]; bindings: ProductBindingRecord[] }];
}>();

const {
  treeLoading,
  treeOptions,
  treeProps,
  loadTree,
  normalizeSelectionForTree,
  firstSelectionForTree,
  selectionToBindings,
} = useProductBindingTree(props.mode, {
  hideTypeGroup: () => props.hideTypeGroup,
  expandAll: () => props.expandAll,
});

const localValue = ref<string | string[]>(
  props.mode === 'single' ? firstSelectionForTree(props.modelValue) : normalizeSelectionForTree(props.modelValue),
);

const placeholderText = computed(() => {
  if (props.placeholder) return props.placeholder;

  switch (props.mode) {
    case 'single':
      return '选择一个商品配置';
    case 'batch':
      return '按分类批量选择绑定配置';
    default:
      return '按分类选择绑定配置';
  }
});

const minCollapsedNum = computed(() => {
  if (props.mode === 'single') return 0;
  return props.compact ? 0 : 1;
});

const collapsedItems = computed(() => {
  if (!props.compact || props.mode === 'single') return undefined;
  return (_h: unknown, context: { count: number }) => `已选 ${context.count} 个配置`;
});

const treePopupProps = computed(() => ({
  overlayClassName: 'binding-tree-select-popup',
  overlayInnerStyle: {
    maxHeight: `${props.popupMaxHeight}px`,
    overflowY: 'auto',
  },
}));

watch([() => props.modelValue, treeOptions], ([modelValue]) => {
  if (props.mode === 'single') {
    const strVal = firstSelectionForTree(modelValue);
    if (localValue.value !== strVal) localValue.value = strVal;
  } else {
    const arrVal = normalizeSelectionForTree(modelValue);
    if (JSON.stringify(arrVal) !== JSON.stringify(localValue.value)) {
      localValue.value = arrVal;
    }
  }
});

function handleChange(value: unknown) {
  const result = selectionToBindings(value, props.existingBindings);

  if (props.mode === 'batch') {
    // 批量模式：localValue 保持原始选中值（含分类节点 ID）
    localValue.value = normalizeTreeValue(value);
  } else if (props.mode === 'single') {
    // 单选模式：TDesign 传回的是 string
    localValue.value = firstTreeValue(value);
  } else {
    // 多选模式
    localValue.value = normalizeTreeValue(value);
  }

  const currentValue = normalizeRawModelValue(props.modelValue);
  const useNumber = currentValue.length > 0 && typeof currentValue[0] === 'number';
  const emitValue: (string | number)[] = useNumber
    ? result.binding_ids.map((id) => Number(id)).filter((n) => !Number.isNaN(n))
    : result.binding_ids;
  emit('update:modelValue', emitValue);
  emit('change', result);
}

function normalizeRawModelValue(value: string | number | (string | number)[] | null | undefined): (string | number)[] {
  if (value === null || value === undefined || value === '') return [];
  return Array.isArray(value) ? value : [value];
}

function normalizeTreeValue(value: unknown): string[] {
  return (Array.isArray(value) ? value : [value]).map((item) => String(item || '').trim()).filter(Boolean);
}

function firstTreeValue(value: unknown): string {
  return normalizeTreeValue(value)[0] || '';
}

loadTree();
</script>
<style scoped lang="less">
.binding-tree-select {
  width: 100%;
}

.binding-tree-select--compact {
  :deep(.t-tag-input) {
    min-height: 32px;
  }

  :deep(.t-tag) {
    max-width: 100%;
  }
}

:global(.binding-tree-select-popup) {
  max-width: calc(100vw - 32px);
}
</style>
