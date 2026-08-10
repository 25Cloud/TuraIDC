<template>
  <div class="console-breadcrumb">
    <button type="button" class="console-breadcrumb__link" @click="router.push('/client/services')">用户中心</button>
    <span class="console-breadcrumb__sep">/</span>
    <span>{{ detail.product?.type_label || '实例服务' }}</span>
    <span class="console-breadcrumb__sep">/</span>
    <strong>{{ detail.name || `实例 #${serviceId}` }}</strong>
  </div>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from './context';

const { detail, serviceId, router } = useServiceConsoleContext();
</script>
