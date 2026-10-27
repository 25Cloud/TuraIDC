<template>
  <svg
    class="ai-robot-avatar"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    aria-hidden="true"
    focusable="false"
  >
    <g stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
      <!-- 天线：细杆 + 小圆头 -->
      <path d="M12 6.3V3.6" />
      <circle cx="12" cy="2.6" r="1" fill="currentColor" fill-opacity="0.18" />
      <!-- 左右耳片 -->
      <rect x="1.5" y="10.6" width="1.9" height="4.6" rx="0.95" fill="currentColor" fill-opacity="0.14" />
      <rect x="20.6" y="10.6" width="1.9" height="4.6" rx="0.95" fill="currentColor" fill-opacity="0.14" />
      <!-- 圆角头 -->
      <rect x="3.7" y="6.3" width="16.6" height="13.2" rx="4.4" fill="currentColor" fill-opacity="0.1" />
      <!-- 眼睛 -->
      <circle cx="9.3" cy="12.4" r="1.5" fill="currentColor" stroke="none" />
      <circle cx="14.7" cy="12.4" r="1.5" fill="currentColor" stroke="none" />
      <!-- 嘴 -->
      <path d="M9.9 16.3h4.2" />
    </g>
  </svg>
</template>
<script setup lang="ts">
withDefaults(
  defineProps<{
    /** 图标边长，默认跟随字号（1em） */
    size?: number | string;
  }>(),
  { size: '1em' },
);
</script>
<style scoped lang="less">
.ai-robot-avatar {
  display: inline-block;
  flex: none;
  overflow: visible;
  vertical-align: middle;
}
</style>
