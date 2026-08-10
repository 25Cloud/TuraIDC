<template>
  <div class="data-state">
    <LoadingState v-if="loading" text="加载中" compact />
    <t-empty v-else-if="empty" :description="description || '暂无数据'" />
    <slot v-else />
  </div>
</template>

<script setup lang="ts">
import LoadingState from './LoadingState.vue';

defineProps<{
  loading?: boolean;
  empty?: boolean;
  description?: string;
}>();
</script>
