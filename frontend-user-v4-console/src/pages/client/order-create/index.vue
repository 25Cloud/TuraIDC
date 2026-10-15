<template>
  <section class="client-order-create">
    <t-card class="order-create-card" :bordered="false">
      <div class="order-create-state">
        <t-tag theme="warning" variant="light">{{ $t('orderCreate.title') }}</t-tag>
        <h1>{{ $t('orderCreate.redirected') }}</h1>
        <p>{{ $t('orderCreate.description') }}</p>
        <div class="order-create-actions">
          <t-button theme="primary" @click="openProducts">{{ $t('orderCreate.open') }}</t-button>
        </div>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { openPublicProducts } from '@/utils/publicSite';

function openProducts() {
  openPublicProducts();
}
</script>
<style scoped lang="less">
.client-order-create {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100vh - var(--td-comp-size-xxxxxl));
  // padding 由 Starter 布局层统一提供
}

.order-create-card {
  width: min(100%, 42rem);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.order-create-state {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  align-items: center;
  padding: var(--td-comp-paddingTB-xl) var(--td-comp-paddingLR-xl);
  text-align: center;

  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-headline-medium);
  }

  p {
    max-width: 34rem;
    margin: 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
    line-height: var(--td-line-height-body-medium);
  }
}

.order-create-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  justify-content: center;
}

@media (max-width: @screen-sm-rem) {
  .client-order-create {
    align-items: stretch;
    min-height: auto;
  }

  .order-create-state {
    align-items: stretch;
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-m);
    text-align: left;
  }

  .order-create-actions :deep(.t-button) {
    width: 100%;
  }
}
</style>
