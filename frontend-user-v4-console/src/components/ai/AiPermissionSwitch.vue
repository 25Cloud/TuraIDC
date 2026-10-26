<template>
  <t-select
    class="ai-permission-switch"
    size="small"
    :value="modelValue"
    :disabled="disabled"
    :placeholder="$t('ai.permission.label')"
    @change="handleChange"
  >
    <t-option v-for="option in modeOptions" :key="option.value" :value="option.value" :label="$t(option.labelKey)" />
  </t-select>
</template>
<script setup lang="ts">
import type { AiPermissionMode } from '@turaidc/shared/runtime';
import { computed } from 'vue';

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

// 只声明 key，文案在模板里用 $t 取，保证切换语言时下拉项跟着变
const MODE_LABEL_KEYS: Record<AiPermissionMode, string> = {
  read_only: 'ai.permission.readOnly',
  confirm: 'ai.permission.confirm',
  auto_approve: 'ai.permission.autoApprove',
};

/** 后端没下发 allowedModes 时只放行前两档，自动审批必须显式开启后才出现 */
const modeOptions = computed(() =>
  props.allowedModes.map((mode) => ({ value: mode, labelKey: MODE_LABEL_KEYS[mode] })),
);

const handleChange = (value: unknown): void => {
  const mode = value as AiPermissionMode;
  if (mode === props.modelValue || !props.allowedModes.includes(mode)) {
    return;
  }
  emit('update:modelValue', mode);
};
</script>
<style scoped lang="less">
.ai-permission-switch {
  display: inline-block;
  width: 8.25rem;
}
</style>
