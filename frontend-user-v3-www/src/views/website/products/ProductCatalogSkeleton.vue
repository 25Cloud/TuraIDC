<template>
  <!--
    商品区加载骨架：切换分组时先占住产品区，不让页面变空白，
    也不用全屏 loading 盖住侧栏（侧栏选中态与三级分组在这期间已经切好）。
    卡片式 = 卡片骨架网格，列表式 = 表格行骨架，形态与真实内容同尺寸。
  -->
  <div
    class="catalog-skeleton"
    :class="`catalog-skeleton--${mode}`"
    role="status"
    :aria-label="$t('common.loading')"
  >
    <div v-if="mode === 'card'" class="catalog-skeleton-grid">
      <div
        v-for="index in count"
        :key="index"
        class="catalog-skeleton-card"
        aria-hidden="true"
      >
        <div class="catalog-skeleton-card-head">
          <span class="skeleton-block skeleton-chip" />
          <span class="skeleton-block skeleton-chip skeleton-chip--sm" />
        </div>
        <span class="skeleton-block skeleton-line skeleton-line--title" />
        <span class="skeleton-block skeleton-line" />
        <span class="skeleton-block skeleton-line" />
        <span class="skeleton-block skeleton-line skeleton-line--short" />
        <div class="catalog-skeleton-card-foot">
          <span class="skeleton-block skeleton-price" />
          <span class="skeleton-block skeleton-button" />
        </div>
      </div>
    </div>

    <div v-else class="catalog-skeleton-table" aria-hidden="true">
      <div class="catalog-skeleton-table-head">
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
      </div>
      <div
        v-for="index in count"
        :key="index"
        class="catalog-skeleton-table-row"
      >
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
        <span class="skeleton-block skeleton-line skeleton-line--cell" />
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  /** card = 卡片式网格骨架，list = 列表式表格行骨架 */
  mode: { type: String, default: "card" },
  /** 骨架条数 */
  count: { type: Number, default: 6 },
});
</script>

<style scoped lang="scss">
.catalog-skeleton {
  padding: 0 20px;
}

@media (max-width: 900px) {
  .catalog-skeleton {
    padding: 0 14px;
  }
}

/* ===== 卡片式骨架 ===== */
.catalog-skeleton-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 18px;
  padding: 22px 0 32px;

  @media (max-width: 900px) {
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 14px;
    padding: 18px 0 24px;
  }

  @media (max-width: 520px) {
    grid-template-columns: 1fr;
    gap: 12px;
  }
}

.catalog-skeleton-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
  max-width: 380px;
  padding: 16px 16px 14px;
  border: 1px solid $divider-color;
  border-radius: 10px;
  background: $bg-color-card;
  box-sizing: border-box;
}

.catalog-skeleton-card-head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.catalog-skeleton-card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 6px;
  padding-top: 12px;
  border-top: 1px solid $divider-color;
}

/* ===== 列表式骨架 ===== */
.catalog-skeleton-table {
  margin: 20px 0 28px;
  border: 1px solid $divider-color;
  border-bottom: 0;
  background: #fff;
}

.catalog-skeleton-table-head,
.catalog-skeleton-table-row {
  display: grid;
  grid-template-columns:
    minmax(220px, 1.7fr) 90px 90px minmax(140px, 1fr)
    minmax(160px, 1.1fr);
  align-items: center;
  gap: 0;
}

.catalog-skeleton-table-head {
  min-height: 38px;
  background: $bg-color-soft;
  border-bottom: 1px solid $divider-color;
}

.catalog-skeleton-table-row {
  min-height: 62px;
  border-bottom: 1px solid $divider-color;
}

.skeleton-line--cell {
  height: 12px;
  margin: 0 12px;
}

/* ===== 骨架块 ===== */
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
  animation: catalog-skeleton-shimmer 1.2s ease infinite;
}

.skeleton-chip {
  width: 72px;
  height: 20px;
  border-radius: 4px;
}

.skeleton-chip--sm {
  width: 44px;
}

.skeleton-line {
  width: 100%;
  height: 12px;
}

.skeleton-line--title {
  width: 72%;
  height: 16px;
}

.skeleton-line--short {
  width: 56%;
}

.skeleton-price {
  width: 84px;
  height: 22px;
}

.skeleton-button {
  width: 92px;
  height: 32px;
  border-radius: 6px;
}

@keyframes catalog-skeleton-shimmer {
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
