<template>
  <button
    type="button"
    class="shop-group-nav-trigger"
    :class="{ 'is-active': !visible }"
    :aria-pressed="!visible"
    @click="emit('toggle')"
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
        y="3"
        width="12"
        height="10"
        rx="1.5"
        stroke="currentColor"
        stroke-width="1.3"
      />
      <path d="M6.5 3v10" stroke="currentColor" stroke-width="1.3" />
    </svg>
    <span>{{
      visible
        ? $t("product.list.hideCatalogNav")
        : $t("product.list.showCatalogNav")
    }}</span>
  </button>
</template>

<script setup>
/**
 * 「收起 / 展开目录」触发按钮：选购页与卡片式配置页共用，
 * 保证两页按钮的位置、文案与折叠动画入口一致。
 */
defineProps({
  visible: { type: Boolean, default: true },
});

const emit = defineEmits(["toggle"]);
</script>

<style scoped lang="scss">
.shop-group-nav-trigger {
  display: inline-flex;
  height: 32px;
  align-items: center;
  gap: 5px;
  padding: 0 10px;
  border: 1px solid $divider-color;
  border-radius: 4px;
  background: #fff;
  color: $text-color-secondary;
  font-size: 12px;
  cursor: pointer;
  white-space: nowrap;
  transition:
    border-color $motion-fast ease,
    color $motion-fast ease;

  &:hover,
  &.is-open,
  &.is-active {
    border-color: $color-primary;
    color: $color-primary;
  }

  &:focus-visible {
    outline: 2px solid rgba($color-primary, 0.45);
    outline-offset: 1px;
  }

  @media (max-width: 900px) {
    display: none;
  }
}
</style>
