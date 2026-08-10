<template>
  <t-dialog :visible="visible" :header="title" width="520px" :confirm-btn="confirmText" :cancel-btn="cancelText" @confirm="$emit('confirm')" @close="$emit('close')">
    <slot />
  </t-dialog>
</template>

<script setup lang="ts">
defineProps<{
  visible: boolean;
  title: string;
  confirmText?: string;
  cancelText?: string;
}>();

defineEmits<{
  confirm: [];
  close: [];
}>();
</script>
