<template>
  <t-dropdown
    trigger="click"
    placement="bottom-right"
    :disabled="disabled"
    :options="menuOptions"
    @click="handleSelect"
  >
    <t-button class="ai-permission-switch" size="small" variant="outline" shape="round" :disabled="disabled">
      <template #icon><lock-on-icon v-if="modelValue === 'read_only'" /><lock-off-icon v-else /></template>
      <span class="ai-permission-switch__label">{{ currentLabel }}</span>
      <chevron-down-icon class="ai-permission-switch__caret" />
    </t-button>
  </t-dropdown>
</template>
<script setup lang="ts">
import type { AiPermissionMode } from '@turaidc/shared/runtime';
import { ChevronDownIcon, LockOffIcon, LockOnIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
  defineProps<{
    /** 当前权限档位 */
    modelValue: AiPermissionMode;
    /** 后端允许的档位，未在其中的档位不渲染 */
    allowedModes: AiPermissionMode[];
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [mode: AiPermissionMode] }>();

const { t } = useI18n();

const MODE_LABEL_KEYS: Record<AiPermissionMode, string> = {
  read_only: 'ai.permission.readOnly',
  confirm: 'ai.permission.confirm',
  auto_approve: 'ai.permission.autoApprove',
};

/**
 * 用下拉菜单而不是 select：
 * 抽屉的 body 是独立滚动容器，select 的浮层在部分浏览器里会被裁切且点击无响应；
 * dropdown 的菜单默认挂到 body 上，层级也更稳。
 */
const menuOptions = computed(() =>
  props.allowedModes.map((mode) => ({
    value: mode,
    content: `${mode === props.modelValue ? '✓ ' : ''}${t(MODE_LABEL_KEYS[mode])}`,
  })),
);

const currentLabel = computed(() => t(MODE_LABEL_KEYS[props.modelValue]));

const handleSelect = (dropdownItem: string | number | Record<string, unknown>): void => {
  const mode = (typeof dropdownItem === 'object' && dropdownItem !== null
    ? dropdownItem.value
    : dropdownItem) as AiPermissionMode;
  if (!mode || mode === props.modelValue || !props.allowedModes.includes(mode)) {
    return;
  }
  emit('update:modelValue', mode);
};
</script>
<style scoped lang="less">
.ai-permission-switch {
  display: inline-flex;
  gap: 2px;
  align-items: center;
  height: 1.75rem;
  padding: 0 var(--td-comp-paddingLR-xs);
  font: var(--td-font-body-small);
}

.ai-permission-switch__label {
  max-width: 6rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-permission-switch__caret {
  margin-left: 2px;
  font-size: 0.75rem;
}
</style>
