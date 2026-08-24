<template>
  <div class="result-500">
    <t-result theme="error" title="服务器异常" description="服务暂时不可用，请稍后重试；若持续出现请联系客服。">
      <template #extra>
        <t-space size="small">
          <t-button theme="primary" @click="goHome">返回首页</t-button>
          <t-button variant="outline" @click="reload">重新加载</t-button>
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
