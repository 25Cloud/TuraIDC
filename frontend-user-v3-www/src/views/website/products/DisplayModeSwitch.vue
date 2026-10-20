<template>
  <div
    class="shop-view-switch"
    role="group"
    :aria-label="ariaLabel || $t('product.list.displayMode')"
  >
    <button
      type="button"
      class="shop-view-switch__btn"
      :class="{ 'is-active': modelValue === DISPLAY_MODE_CARD }"
      :aria-pressed="modelValue === DISPLAY_MODE_CARD"
      @click="select(DISPLAY_MODE_CARD)"
    >
      <svg
        viewBox="0 0 16 16"
        width="14"
        height="14"
        fill="none"
        aria-hidden="true"
      >
        <rect
          x="2"
          y="2"
          width="5.2"
          height="5.2"
          rx="1.2"
          fill="currentColor"
        />
        <rect
          x="8.8"
          y="2"
          width="5.2"
          height="5.2"
          rx="1.2"
          fill="currentColor"
        />
        <rect
          x="2"
          y="8.8"
          width="5.2"
          height="5.2"
          rx="1.2"
          fill="currentColor"
        />
        <rect
          x="8.8"
          y="8.8"
          width="5.2"
          height="5.2"
          rx="1.2"
          fill="currentColor"
        />
      </svg>
      <span>{{ $t("product.list.displayCard") }}</span>
    </button>
    <button
      type="button"
      class="shop-view-switch__btn"
      :class="{ 'is-active': modelValue === DISPLAY_MODE_LIST }"
      :aria-pressed="modelValue === DISPLAY_MODE_LIST"
      @click="select(DISPLAY_MODE_LIST)"
    >
      <svg
        viewBox="0 0 16 16"
        width="14"
        height="14"
        fill="none"
        aria-hidden="true"
      >
        <circle cx="3" cy="4" r="1.15" fill="currentColor" />
        <circle cx="3" cy="8" r="1.15" fill="currentColor" />
        <circle cx="3" cy="12" r="1.15" fill="currentColor" />
        <rect
          x="6"
          y="3.25"
          width="8"
          height="1.5"
          rx="0.75"
          fill="currentColor"
        />
        <rect
          x="6"
          y="7.25"
          width="8"
          height="1.5"
          rx="0.75"
          fill="currentColor"
        />
        <rect
          x="6"
          y="11.25"
          width="8"
          height="1.5"
          rx="0.75"
          fill="currentColor"
        />
      </svg>
      <span>{{ $t("product.list.displayList") }}</span>
    </button>
  </div>
</template>

<script setup>
/**
 * 卡片式 / 列表式展示方式切换。
 *
 * 选购页工具栏与卡片式配置页顶栏共用同一套控件：之前配置页另写了一份裸文字按钮，
 * 没有图标、圆角底色和选中态阴影，和站内其它切换器观感不一致。样式只在这里维护。
 */
import { DISPLAY_MODE_CARD, DISPLAY_MODE_LIST } from "./useProductDisplayMode";

const props = defineProps({
  modelValue: {
    type: String,
    default: "",
  },
  ariaLabel: {
    type: String,
    default: "",
  },
});

const emit = defineEmits(["update:modelValue"]);

function select(mode) {
  if (mode !== props.modelValue) {
    emit("update:modelValue", mode);
  }
}
</script>

<style scoped lang="scss">
.shop-view-switch {
  display: inline-flex;
  flex-shrink: 0;
  gap: 2px;
  padding: 2px;
  border: 1px solid $divider-color;
  border-radius: 4px;
  background: $bg-color-soft;
}

.shop-view-switch__btn {
  display: inline-flex;
  height: 28px;
  align-items: center;
  gap: 4px;
  padding: 0 10px;
  border: 0;
  border-radius: 3px;
  background: transparent;
  color: $text-color-secondary;
  font-size: 12px;
  cursor: pointer;
  transition:
    background-color $motion-fast ease,
    color $motion-fast ease,
    box-shadow $motion-fast ease;

  &:hover {
    color: $color-primary;
  }

  &.is-active {
    background: #fff;
    color: $color-primary;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
  }

  &:focus-visible {
    outline: 2px solid rgba($color-primary, 0.45);
    outline-offset: 1px;
  }
}

@media (max-width: 520px) {
  .shop-view-switch__btn {
    padding: 0 8px;
  }
}
</style>
