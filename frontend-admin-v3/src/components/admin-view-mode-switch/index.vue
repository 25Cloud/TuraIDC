<template>
  <div class="admin-view-mode-bar" :class="{ 'admin-view-mode-bar--inline': inline }">
    <div class="admin-view-mode-switch" role="group" :aria-label="label">
      <button
        type="button"
        class="admin-view-mode-btn"
        :class="{ 'is-active': mode === 'list' }"
        :title="listLabel"
        :aria-label="listLabel"
        :aria-pressed="mode === 'list'"
        @click="select('list')"
      >
        <!-- 列表图标：三行「圆点 + 文本条」 -->
        <svg viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true">
          <circle cx="3" cy="4" r="1.15" fill="currentColor" />
          <circle cx="3" cy="8" r="1.15" fill="currentColor" />
          <circle cx="3" cy="12" r="1.15" fill="currentColor" />
          <rect x="6" y="3.25" width="8" height="1.5" rx="0.75" fill="currentColor" />
          <rect x="6" y="7.25" width="8" height="1.5" rx="0.75" fill="currentColor" />
          <rect x="6" y="11.25" width="8" height="1.5" rx="0.75" fill="currentColor" />
        </svg>
      </button>
      <button
        type="button"
        class="admin-view-mode-btn"
        :class="{ 'is-active': mode === 'card' }"
        :title="cardLabel"
        :aria-label="cardLabel"
        :aria-pressed="mode === 'card'"
        @click="select('card')"
      >
        <!-- 卡片图标：2×2 圆角方块 -->
        <svg viewBox="0 0 16 16" width="16" height="16" fill="none" aria-hidden="true">
          <rect x="2" y="2" width="5.2" height="5.2" rx="1.2" fill="currentColor" />
          <rect x="8.8" y="2" width="5.2" height="5.2" rx="1.2" fill="currentColor" />
          <rect x="2" y="8.8" width="5.2" height="5.2" rx="1.2" fill="currentColor" />
          <rect x="8.8" y="8.8" width="5.2" height="5.2" rx="1.2" fill="currentColor" />
        </svg>
      </button>
    </div>
  </div>
</template>
<script setup lang="ts">
/**
 * 列表 / 卡片 展示方式开关。
 *
 * 用两个图标按钮而不是「列表式 / 卡片式」文字单选：文字单选在筛选栏里又长又抢眼，
 * 还要和旁边的按钮抢横向空间。图标沿用「三行条目 = 列表」「四宫格 = 卡片」两种最通用的
 * 隐喻，并用 title / aria-label 给出文字说明，不靠颜色猜。
 */
import { computed } from 'vue';

import type { AdminViewMode } from '@/composables/useAdminViewMode';
import { t } from '@/locales';

const props = withDefaults(
  defineProps<{
    mode: AdminViewMode;
    /** 放进筛选行内联使用：不占满整行、不带下边距 */
    inline?: boolean;
  }>(),
  { inline: false },
);

const emit = defineEmits<{ change: [mode: AdminViewMode] }>();

const label = computed(() => t('adminViewMode.switchLabel'));
const listLabel = computed(() => t('settings.index.productDisplayModeList'));
const cardLabel = computed(() => t('settings.index.productDisplayModeCard'));

function select(mode: AdminViewMode): void {
  if (mode === props.mode) {
    return;
  }

  emit('change', mode);
}
</script>
<style lang="less" scoped>
.admin-view-mode-bar {
  display: flex;
  justify-content: flex-end;
  margin: 0 0 12px;
}

.admin-view-mode-bar--inline {
  margin: 0;
}

.admin-view-mode-switch {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  padding: 2px;
  border: 1px solid var(--td-component-stroke);
  border-radius: var(--td-radius-default);
  background: var(--td-bg-color-secondarycontainer);
}

.admin-view-mode-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 26px;
  padding: 0;
  border: none;
  border-radius: calc(var(--td-radius-default) - 2px);
  background: transparent;
  color: var(--td-text-color-secondary);
  cursor: pointer;
  transition:
    background-color 0.15s ease,
    color 0.15s ease,
    box-shadow 0.15s ease;

  &:hover {
    color: var(--td-text-color-primary);
  }

  &.is-active {
    background: var(--td-bg-color-container);
    color: var(--td-brand-color);
    box-shadow: var(--td-shadow-1);
  }

  &:focus-visible {
    outline: 2px solid var(--td-brand-color-focus);
    outline-offset: 1px;
  }
}
</style>
