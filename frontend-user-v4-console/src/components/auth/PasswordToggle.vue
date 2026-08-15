<template>
  <button
    type="button"
    class="password-toggle"
    :aria-label="modelValue ? '隐藏密码' : '显示密码'"
    :aria-pressed="modelValue"
    @click="$emit('update:modelValue', !modelValue)"
    @keydown.enter.prevent="$emit('update:modelValue', !modelValue)"
    @keydown.space.prevent="$emit('update:modelValue', !modelValue)"
  >
    <browse-icon v-if="modelValue" aria-hidden="true" />
    <browse-off-icon v-else aria-hidden="true" />
  </button>
</template>
<script setup lang="ts">
import { BrowseIcon, BrowseOffIcon } from 'tdesign-icons-vue-next';

defineProps<{ modelValue: boolean }>();
defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>
<style scoped>
.password-toggle {
  background: none;
  border: none;
  cursor: pointer;
  padding: 2px;
  color: var(--td-text-color-secondary);
  display: inline-flex;
  align-items: center;
}

.password-toggle:focus-visible {
  outline: 2px solid var(--td-brand-color);
  outline-offset: 1px;
}
</style>
