<template>
  <t-drawer :visible="visible" :header="title" :size="size || '420px'" @close="$emit('close')">
    <slot />
  </t-drawer>
</template>

<script setup lang="ts">
defineProps<{
  visible: boolean;
  title: string;
  size?: string;
}>();

defineEmits<{
  close: [];
}>();
</script>
