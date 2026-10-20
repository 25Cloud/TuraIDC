<template>
  <!--
    商品配置区加载骨架。
    page   = 卡片式独立配置页首屏（标题 / 描述 / 价格 / 按钮轮廓）；
    inline = 列表式选购页内联配置区（配置块 + 右侧费用摘要）。
    只用占位块表达结构，不显示真实数据，也不使用全屏 loading 遮罩。
  -->
  <div
    class="config-skeleton"
    :class="`config-skeleton--${mode}`"
    role="status"
    :aria-label="$t('common.loading')"
    aria-hidden="false"
  >
    <div class="config-skeleton-main">
      <div class="config-skeleton-hero">
        <span class="skeleton-block skeleton-chip" />
        <span class="skeleton-block skeleton-line skeleton-line--title" />
        <span class="skeleton-block skeleton-line skeleton-line--sub" />
      </div>

      <div class="config-skeleton-card">
        <span class="skeleton-block skeleton-line skeleton-line--card-title" />
        <div class="config-skeleton-rows">
          <span
            v-for="index in 4"
            :key="index"
            class="skeleton-block skeleton-line"
          />
        </div>
      </div>

      <div class="config-skeleton-card">
        <span class="skeleton-block skeleton-line skeleton-line--card-title" />
        <div class="config-skeleton-rows">
          <span
            v-for="index in 3"
            :key="index"
            class="skeleton-block skeleton-line skeleton-line--short"
          />
        </div>
      </div>
    </div>

    <div class="config-skeleton-aside">
      <span class="skeleton-block skeleton-line skeleton-line--card-title" />
      <span class="skeleton-block skeleton-line" />
      <span class="skeleton-block skeleton-line skeleton-line--short" />
      <span class="skeleton-block skeleton-line" />
      <div class="config-skeleton-total">
        <span class="skeleton-block skeleton-price" />
      </div>
      <span class="skeleton-block skeleton-button" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  mode: { type: String, default: "page" },
});
</script>

<style scoped lang="scss">
.config-skeleton {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 268px;
  gap: 12px;
  padding: 20px;
}

.config-skeleton--inline {
  padding: 16px 20px;
}

@media (max-width: 900px) {
  .config-skeleton {
    grid-template-columns: 1fr;
    padding: 14px;
  }
}

.config-skeleton-main {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.config-skeleton-hero,
.config-skeleton-card,
.config-skeleton-aside {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
  padding: 16px;
  border: 1px solid $divider-color;
  border-radius: 4px;
  background: $bg-color-card;
}

.config-skeleton--page .config-skeleton-hero {
  background: linear-gradient(135deg, #ffffff 0%, #f5f9ff 100%);
}

.config-skeleton-rows {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: 4px;
}

.config-skeleton-aside {
  align-self: start;
}

.config-skeleton-total {
  display: flex;
  justify-content: flex-end;
  margin-top: 6px;
  padding-top: 12px;
  border-top: 1px solid $divider-color;
}

.skeleton-block {
  display: block;
  border-radius: 4px;
  background: linear-gradient(
    90deg,
    rgba(15, 23, 42, 0.05) 25%,
    rgba(15, 23, 42, 0.09) 37%,
    rgba(15, 23, 42, 0.05) 63%
  );
  background-size: 400% 100%;
  animation: config-skeleton-shimmer 1.4s ease infinite;
}

.skeleton-chip {
  width: 72px;
  height: 20px;
}

.skeleton-line {
  width: 100%;
  height: 12px;
}

.skeleton-line--title {
  width: 46%;
  height: 24px;
}

.skeleton-line--sub {
  width: 64%;
  height: 14px;
}

.skeleton-line--card-title {
  width: 30%;
  height: 16px;
}

.skeleton-line--short {
  width: 58%;
}

.skeleton-price {
  width: 120px;
  height: 26px;
}

.skeleton-button {
  width: 100%;
  height: 40px;
  margin-top: 4px;
  border-radius: 6px;
}

@keyframes config-skeleton-shimmer {
  0% {
    background-position: 100% 50%;
  }

  100% {
    background-position: 0 50%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .skeleton-block {
    animation: none;
  }
}
</style>
