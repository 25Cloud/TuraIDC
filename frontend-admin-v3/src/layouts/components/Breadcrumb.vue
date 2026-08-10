<template>
  <t-breadcrumb max-item-width="150" class="tdesign-breadcrumb">
    <t-breadcrumbItem v-for="item in crumbs" :key="item.to" :to="item.to">
      {{ item.title }}
    </t-breadcrumbItem>
  </t-breadcrumb>
</template>
<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import type { LocalizedTitle } from '@/locales';
import { useLocale } from '@/locales/useLocale';

const { locale } = useLocale();
const route = useRoute();

const renderTitle = (title?: LocalizedTitle, fallback?: string) => {
  if (!title) return fallback || '';
  return title[locale.value as keyof LocalizedTitle] || fallback || '';
};

const crumbs = computed(() => {
  return route.matched
    .filter((r) => r.meta?.title && !r.meta.hiddenBreadcrumb && !r.path.includes('/menu/'))
    .map((r) => ({
      to: r.path,
      title: renderTitle(r.meta.title as LocalizedTitle),
    }));
});
</script>
<style scoped>
.tdesign-breadcrumb {
  margin-bottom: 24px;
}
</style>
