<template>
  <section class="service-console-entry">
    <t-loading :loading="loading" text="正在打开实例控制台" />
  </section>
</template>
<script setup lang="ts">
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { isNatConsole } from '@/domains/services/console/useConsoleCore';
import type { ConsoleServiceDetail } from '@/types/client';

const route = useRoute();
const router = useRouter();
const loading = ref(true);
const serviceId = computed(() => Number(route.params.id));

onMounted(async () => {
  const id = serviceId.value;

  if (!Number.isFinite(id) || id <= 0) {
    await router.replace({ name: 'ClientServices' });
    return;
  }

  try {
    const response = await clientApi.serviceDetail(id);
    const service = (response.data || {}) as ConsoleServiceDetail;
    await router.replace({
      name: isNatConsole(service) ? 'ClientNatConsole' : 'ClientComputeConsole',
      params: { id: String(id) },
    });
  } catch {
    MessagePlugin.error('实例控制台加载失败');
    await router.replace({ name: 'ClientServices' });
  } finally {
    loading.value = false;
  }
});
</script>
<style scoped lang="less">
.service-console-entry {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 15rem;
}
</style>
