<template>
  <t-tag :theme="theme" :variant="variant">{{ label }}</t-tag>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { getStatusConfig } from '@shared/statusConfig';

interface StatusConfig {
  label?: string;
  tagType?: string;
  color?: string;
}

const props = withDefaults(
  defineProps<{
    statusMap: Record<string, unknown>;
    status: unknown;
    variant?: 'light' | 'dark' | 'outline' | 'light-outline';
  }>(),
  {
    variant: 'light',
  },
);

const config = computed<StatusConfig>(() => (getStatusConfig(props.statusMap, props.status as string | number) || {}) as StatusConfig);
const label = computed(() => config.value.label || String(props.status ?? '-'));
const theme = computed(() => {
  const tagType = String(config.value.tagType || config.value.color || 'default');
  if (['success', 'warning', 'danger', 'primary', 'default'].includes(tagType)) return tagType as 'success' | 'warning' | 'danger' | 'primary' | 'default';
  return 'default';
});
</script>
