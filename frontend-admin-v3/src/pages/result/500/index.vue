<template>
  <result :title="$t('result.error.title')" type="500" :tip="t('pages.result.500.subtitle')">
    <t-button @click="() => router.push('/')">{{ t('pages.result.500.back') }}</t-button>
  </result>
</template>
<script setup lang="ts">
import { useRouter } from 'vue-router';

import Result from '@/components/result/index.vue';
import { t } from '@/locales';

defineOptions({
  name: 'Result500',
});

const router = useRouter();
</script>
