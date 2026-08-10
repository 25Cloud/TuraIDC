<template>
  <result title="无访问权限" :tip="t('pages.result.403.tips')">
    <t-button @click="() => router.push('/admin/dashboard')">{{ t('pages.result.403.back') }}</t-button>
  </result>
</template>
<script setup lang="ts">
import { useRouter } from 'vue-router';

import Result from '@/components/result/index.vue';
import { t } from '@/locales';

defineOptions({
  name: 'Result403',
});

const router = useRouter();
</script>
