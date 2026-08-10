<!--
  TDesign 版通用状态标签。admin 端专用，避免引入 shared/StatusTag（el-tag）。
  用法：
    <StatusTag :status-map="ORDER_STATUS_MAP" :status="row.status" />
    <StatusTag :status-map="INVOICE_STATUS_MAP" :status="row.status" size="small" />
-->
<template>
  <t-tag :theme="tagTheme" :variant="variant" :size="size">
    {{ label }}
  </t-tag>
</template>
<script setup lang="ts">
import { getStatusConfig } from '@shared/statusConfig';
import type { PropType } from 'vue';
import { computed } from 'vue';

const props = defineProps({
  statusMap: { type: Object as PropType<Record<string, any>>, required: true },
  status: { type: [Number, String] as PropType<number | string>, required: true },
  size: { type: String as PropType<'small' | 'medium' | 'large'>, default: 'small' },
  variant: { type: String as PropType<'dark' | 'light' | 'outline' | 'light-outline'>, default: 'light' },
});

// shared/statusConfig 的 tagType 用 Element Plus 命名（success/info/warning/danger/''/purple），
// TDesign theme 仅有 default/primary/success/warning/danger。
// 与现有各页 *StatusTheme helper 行为对齐：info/purple/'' 统一落到 default（灰），其余同名。
const TDESIGN_THEME_MAP: Record<string, string> = {
  success: 'success',
  warning: 'warning',
  danger: 'danger',
  info: 'default',
  purple: 'default',
  primary: 'primary',
  default: 'default',
};

const config = computed(() => getStatusConfig(props.statusMap, props.status));
const tagTheme = computed(() => TDESIGN_THEME_MAP[config.value?.tagType] || 'default');
const label = computed(() => config.value?.label ?? '-');
</script>
