<template>
  <div class="shop-width-control">
    <button
      type="button"
      class="shop-width-trigger"
      :class="{ 'is-open': widthPanelOpen }"
      :aria-expanded="widthPanelOpen"
      aria-haspopup="dialog"
      @click.stop="toggleWidthPanel"
    >
      <svg
        viewBox="0 0 16 16"
        width="14"
        height="14"
        fill="none"
        aria-hidden="true"
      >
        <path
          d="M2.5 3.5v9M13.5 3.5v9"
          stroke="currentColor"
          stroke-width="1.4"
          stroke-linecap="round"
        />
        <path
          d="M5 8h2M9 8h2"
          stroke="currentColor"
          stroke-width="1.4"
          stroke-linecap="round"
        />
        <path
          d="M7 6.2 5.4 8 7 9.8M9 6.2 10.6 8 9 9.8"
          stroke="currentColor"
          stroke-width="1.2"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
      <span>{{ $t("product.list.pageWidth") }}</span>
    </button>

    <div
      v-if="widthPanelOpen"
      class="shop-width-panel"
      role="dialog"
      :aria-label="$t('product.list.pageWidth')"
      @click.stop
    >
      <div class="shop-width-panel__head">
        <strong>{{ $t("product.list.pageWidth") }}</strong>
        <button
          v-if="followsUserWidthChoice"
          type="button"
          class="shop-width-reset"
          @click="resetLayoutWidth"
        >
          {{ $t("product.list.widthReset") }}
        </button>
      </div>

      <div class="shop-width-mode">
        <button
          type="button"
          :class="{ 'is-active': layoutMode === 'contained' }"
          :aria-pressed="layoutMode === 'contained'"
          @click="setLayoutMode('contained')"
        >
          {{ $t("product.list.widthContained") }}
        </button>
        <button
          type="button"
          :class="{ 'is-active': layoutMode === 'full' }"
          :aria-pressed="layoutMode === 'full'"
          @click="setLayoutMode('full')"
        >
          {{ $t("product.list.widthFull") }}
        </button>
      </div>

      <div v-if="layoutMode === 'contained'" class="shop-width-values">
        <button
          v-for="width in widthOptions"
          :key="width"
          type="button"
          :class="{ 'is-active': contentMaxWidth === width }"
          :aria-pressed="contentMaxWidth === width"
          @click="setContentMaxWidth(width)"
        >
          {{ width }}
        </button>
      </div>

      <p class="shop-width-hint">{{ $t("product.list.widthHint") }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import {
  PRODUCT_PAGE_WIDTH_PRESETS,
  useProductPageWidth,
} from "./useProductDisplaySettings";

/**
 * 页面宽度（版心）控件：选购页与卡片式配置页共用。
 *
 * 两页的版心偏好来自同一个 localStorage 键（useProductPageWidth），
 * 这里只负责面板 UI；容器宽度由各页自己的 CSS 变量承载。
 */
const {
  layoutMode,
  contentMaxWidth,
  followsUserWidthChoice,
  setLayoutMode,
  setContentMaxWidth,
  resetLayoutWidth,
} = useProductPageWidth();

const widthPanelOpen = ref(false);

// 站点默认宽度可能不在预设档位里（后台允许 960–1920 的任意值），
// 把它并进可选档，避免面板里没有当前值。
const widthOptions = computed(() => {
  const options = [...PRODUCT_PAGE_WIDTH_PRESETS];

  if (!options.includes(contentMaxWidth.value)) {
    options.push(contentMaxWidth.value);
  }

  return options.sort((left, right) => left - right);
});

function toggleWidthPanel() {
  widthPanelOpen.value = !widthPanelOpen.value;
}

function closeWidthPanel() {
  widthPanelOpen.value = false;
}

function handleWidthPanelOutsideClick(event) {
  if (!widthPanelOpen.value) {
    return;
  }

  const target = event.target;
  if (target instanceof Element && target.closest(".shop-width-control")) {
    return;
  }

  closeWidthPanel();
  // 面板打开时，点外部只关闭面板：吞掉这次点击，避免顺带触发下面的卡片跳转
  event.stopPropagation();
}

function handleWidthPanelKeydown(event) {
  if (event.key === "Escape") {
    closeWidthPanel();
  }
}

onMounted(() => {
  document.addEventListener("click", handleWidthPanelOutsideClick, true);
  document.addEventListener("keydown", handleWidthPanelKeydown);
});

onBeforeUnmount(() => {
  document.removeEventListener("click", handleWidthPanelOutsideClick, true);
  document.removeEventListener("keydown", handleWidthPanelKeydown);
});
</script>

<style scoped lang="scss">
/* ===== 页面宽度面板（宽屏） ===== */
.shop-width-control {
  position: relative;
}

.shop-width-trigger {
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
}

.shop-width-panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 60;
  width: 292px;
  padding: 14px;
  border: 1px solid $divider-color;
  border-radius: 10px;
  background: #fff;
  box-shadow: $shadow-md;
}

.shop-width-panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;

  strong {
    color: $text-color-primary;
    font-size: 13px;
    font-weight: 600;
  }
}

.shop-width-reset {
  padding: 0;
  border: 0;
  background: transparent;
  color: $color-primary;
  font-size: 12px;
  cursor: pointer;

  &:hover {
    text-decoration: underline;
  }
}

.shop-width-mode {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;

  button {
    height: 32px;
    border: 1px solid $divider-color;
    border-radius: 4px;
    background: $bg-color-soft;
    color: $text-color-secondary;
    font-size: 12px;
    cursor: pointer;
    transition:
      background-color $motion-fast ease,
      border-color $motion-fast ease,
      color $motion-fast ease;

    &:hover {
      color: $color-primary;
    }

    &.is-active {
      border-color: $color-primary;
      background: $color-primary-soft;
      color: $color-primary;
      font-weight: 600;
    }
  }
}

.shop-width-values {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;

  button {
    min-width: 60px;
    height: 28px;
    padding: 0 8px;
    border: 1px solid $divider-color;
    border-radius: 4px;
    background: #fff;
    color: $text-color-secondary;
    font-size: 12px;
    cursor: pointer;
    transition:
      background-color $motion-fast ease,
      border-color $motion-fast ease,
      color $motion-fast ease;

    &:hover {
      border-color: $color-primary-border;
      color: $color-primary;
    }

    &.is-active {
      border-color: $color-primary;
      background: $color-primary-soft;
      color: $color-primary;
      font-weight: 600;
    }
  }
}

.shop-width-hint {
  margin: 10px 0 0;
  color: $text-color-placeholder;
  font-size: 12px;
  line-height: 1.6;
}
</style>
