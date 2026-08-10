<template>
  <section class="catalog-page">
    <t-card class="catalog-card" :bordered="false">
      <div class="catalog-result">
        <t-empty type="maintenance" description="统一使用官网产品目录" />
        <p>购买、报价和配置流程已经收口到 /products，客户端这里保留为稳定入口。</p>
        <t-button theme="primary" @click="router.push('/products')">打开产品目录</t-button>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { useRouter } from 'vue-router';

const router = useRouter();
</script>
<style scoped lang="less">
.catalog-page {
  // padding 由 Starter 布局层统一提供
}

.catalog-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.catalog-result {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  align-items: center;
  padding: var(--td-comp-paddingTB-xl) var(--td-comp-paddingLR-l);
  text-align: center;

  p {
    margin: 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
  }
}
</style>
