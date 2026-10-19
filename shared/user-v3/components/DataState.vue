<template>
  <div class="data-state">
    <LoadingState v-if="loading" :text="loadingText" compact />
    <t-empty v-else-if="empty" :description="description || emptyText" />
    <slot v-else />
  </div>
</template>

<script setup lang="ts">
import LoadingState from './LoadingState.vue';

/*
 * 文案由调用方传入（各端都有 $t）。
 * 不在这里直接写 $t：本组件会被三端的 tsconfig 各自类型检查，
 * shared 目录里没有 vue-i18n 的全局属性增强，写 $t 会 TS2339。
 */
withDefaults(
  defineProps<{
    loading?: boolean;
    empty?: boolean;
    description?: string;
    loadingText?: string;
    emptyText?: string;
  }>(),
  {
    description: '',
    loadingText: '加载中',
    emptyText: '暂无数据',
  },
);
</script>