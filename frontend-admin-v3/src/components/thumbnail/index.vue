<template>
  <img :class="className" :src="imageUrl" :alt="altText" loading="lazy" decoding="async" />
</template>
<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps({
  src: {
    type: String,
    default: '',
  },
  url: {
    type: String,
    default: '',
  },
  alt: {
    type: String,
    default: '布局缩略图',
  },
  type: {
    type: String,
    default: 'layout',
  },
});

const imageUrl = computed(() => props.url || props.src);
const altText = computed(() => props.alt || '布局缩略图');
const className = computed(() => {
  return [
    'thumbnail-container',
    {
      'thumbnail-circle': props.type === 'circle',
      'thumbnail-layout': props.type === 'layout',
    },
  ];
});
</script>
<style lang="less" scoped>
@import '@/style/index.less';

.thumbnail {
  &-container {
    display: inline-block;
  }

  &-circle {
    border-radius: var(--td-radius-circle);
  }

  &-layout {
    width: 88px;
    height: 48px;
  }
}
</style>
