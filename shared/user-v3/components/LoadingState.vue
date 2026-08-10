<template>
  <div v-if="loading" class="loading-state" :class="{ 'is-compact': compact }">
    <div class="loading-state__spinner">
      <span class="loading-state__dot"></span>
      <span class="loading-state__dot"></span>
      <span class="loading-state__dot"></span>
    </div>
    <span v-if="text" class="loading-state__text">{{ text }}</span>
  </div>
  <slot v-else />
</template>

<script setup lang="ts">
defineProps<{
  loading?: boolean;
  text?: string;
  compact?: boolean;
}>();
</script>

<style scoped>
.loading-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 60vh;
  gap: 16px;
  animation: loading-state-fade 0.4s ease-out;
}

.loading-state.is-compact {
  min-height: 0;
  padding: 24px 0;
}

.loading-state__spinner {
  display: flex;
  gap: 8px;
  align-items: center;
}

.loading-state__dot {
  display: block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--td-brand-color);
  animation: loading-state-bounce 1.2s ease-in-out infinite;
}

.loading-state__dot:nth-child(2) {
  animation-delay: 0.15s;
}

.loading-state__dot:nth-child(3) {
  animation-delay: 0.3s;
}

.loading-state__text {
  font-size: 14px;
  color: var(--td-text-color-placeholder);
  letter-spacing: 0.5px;
}

@keyframes loading-state-bounce {
  0%, 80%, 100% {
    transform: scale(0.6);
    opacity: 0.4;
  }
  40% {
    transform: scale(1);
    opacity: 1;
  }
}

@keyframes loading-state-fade {
  from { opacity: 0; }
  to   { opacity: 1; }
}
</style>
