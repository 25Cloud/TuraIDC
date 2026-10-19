<template>
  <div class="console-breadcrumb">
    <button type="button" class="console-breadcrumb__link" @click="router.push('/client/services')">
      {{ $t('serviceConsole.breadcrumb.userCenter') }}
    </button>
    <span class="console-breadcrumb__sep">/</span>
    <span>{{ detail.product?.type_label || $t('serviceConsole.breadcrumb.instanceService') }}</span>
    <span class="console-breadcrumb__sep">/</span>
    <strong>{{ detail.name || $t('serviceConsole.breadcrumb.instanceHash', { id: serviceId }) }}</strong>
  </div>
</template>
<script setup lang="ts">
import { useServiceConsoleContext } from './context';

const { detail, serviceId, router } = useServiceConsoleContext();
</script>
