<template>
  <section class="detail-scaffold">
    <header class="detail-scaffold__header">
      <div>
        <p v-if="eyebrow" class="detail-scaffold__eyebrow">{{ eyebrow }}</p>
        <h2>{{ title }}</h2>
      </div>
      <slot name="actions" />
    </header>
    <slot />
  </section>
</template>

<script setup lang="ts">
defineProps<{
  title: string;
  eyebrow?: string;
}>();
</script>
