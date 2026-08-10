<template>
  <aside class="console-sidebar">
    <button
      v-for="item in items"
      :key="item.key"
      type="button"
      class="console-sidebar-item"
      :class="{ 'is-active': activeTab === item.key }"
      @click="$emit('update:activeTab', item.key)"
    >
      <component :is="item.icon" />
      <span>{{ item.label }}</span>
    </button>
  </aside>
</template>
<script setup lang="ts">
import type { ServiceConsoleNavItem } from './registry';

defineProps<{
  items: ServiceConsoleNavItem[];
  activeTab: string;
}>();

defineEmits<{
  'update:activeTab': [value: string];
}>();
</script>
