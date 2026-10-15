<template>
  <div class="result-500">
    <t-result
      theme="error"
      :title="$t('result.serverError.title')"
      :description="$t('result.serverError.description')"
    >
      <template #extra>
        <t-space size="small">
          <t-button theme="primary" @click="goHome">{{ $t('result.serverError.backHome') }}</t-button>
          <t-button variant="outline" @click="reload">{{ $t('result.serverError.reload') }}</t-button>
        </t-space>
      </template>
    </t-result>
  </div>
</template>
<script setup lang="ts">
import { useRouter } from 'vue-router';

defineOptions({
  name: 'Result500',
});

const router = useRouter();

function goHome() {
  router.push('/');
}

function reload() {
  window.location.reload();
}
</script>
<style lang="less" scoped>
.result-500 {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 60vh;
  padding: 24px;
}
</style>
