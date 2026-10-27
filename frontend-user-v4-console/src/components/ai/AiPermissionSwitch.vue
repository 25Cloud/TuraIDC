<template>
  <t-dropdown
    trigger="click"
    placement="bottom-right"
    :disabled="disabled"
    :options="menuOptions"
    :popup-props="{ overlayInnerStyle: { minWidth: '12rem' } }"
    @click="handleSelect"
  >
    <t-button
      class="ai-permission-switch"
      variant="outline"
      shape="round"
      :style="switchStyle"
      :disabled="disabled"
      :aria-label="$t('ai.permission.label')"
    >
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

/**
 * 宽度按内容、最小 96px（下拉项里还有 `✓` 与图标，不能按文字硬算宽度）。
 * 高度交给项目全局基线（reset.less: ≥32px，≤768px 40px），不再写死 28px。
 */
const switchStyle = {
  minWidth: '6rem',
  fontSize: 'var(--td-font-size-body-small)',
};

const handleSelect = (dropdownItem: string | number | Record<string, unknown>): void => {
  const mode = (
    typeof dropdownItem === 'object' && dropdownItem !== null ? dropdownItem.value : dropdownItem
  ) as AiPermissionMode;
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
  justify-content: center;
  max-width: 100%;
  padding: 0 10px;
  font: var(--td-font-body-small);
  white-space: nowrap;

  :deep(.t-icon) {
    font-size: 0.875rem;
  }
}

// 文案过长时省略，但不撑破 96px 的下限
.ai-permission-switch__label {
  max-width: 7rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-permission-switch__caret {
  flex: none;
  margin-left: 2px;
  font-size: 0.75rem;
}
</style>
