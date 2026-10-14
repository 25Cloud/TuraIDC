<template>
  <div
    class="mega-skeleton"
    :class="`mega-skeleton--${variant}`"
    aria-hidden="true"
  >
    <span v-for="index in count" :key="index" class="mega-skeleton-item">
      <span class="mega-skeleton-line mega-skeleton-line--title" />
      <span
        v-if="variant === 'card'"
        class="mega-skeleton-line mega-skeleton-line--desc"
      />
      <span v-else class="mega-skeleton-line mega-skeleton-line--count" />
    </span>
  </div>
</template>

<script setup>
/**
 * 顶栏 mega-menu 的取数占位。
 *
 * 之前下拉打开后到数据回来之间是整块空白（公告/帮助）或先闪一句「暂无产品分类」
 * （产品），两者都是同一个根因：没有区分「还在取」和「取完了是空」。
 * 这里只负责画形状，取数状态由各菜单 composable 的 pending 口径提供。
 *
 * 用 display:contents 把自己从布局里摘掉，让占位块直接落进父级栅格/弹性列，
 * 因此和真实条目用同一套栅格与内边距，出数据时不会跳动。
 */
defineProps({
  // card：右栏三列的一级分组 / 公告 / 文档卡片；row：左栏的目录按钮
  variant: {
    type: String,
    default: "card",
  },
  count: {
    type: Number,
    default: 6,
  },
});
</script>

<style scoped lang="scss">
.mega-skeleton {
  display: contents;
}

.mega-skeleton-item {
  display: flex;
  position: relative;
  overflow: hidden;
  gap: 4px;
}

.mega-skeleton--card .mega-skeleton-item {
  flex-direction: column;
  padding: 14px 20px;
}

.mega-skeleton--row .mega-skeleton-item {
  align-items: center;
  justify-content: space-between;
  padding: 10px 20px;
}

.mega-skeleton-line {
  display: block;
  height: 12px;
  border-radius: 999px;
  // 与首页骨架屏同一套底色与节奏，避免同一个站里出现两种灰
  background: linear-gradient(
    90deg,
    rgba(222, 230, 242, 0.92) 0%,
    rgba(245, 248, 252, 0.98) 50%,
    rgba(222, 230, 242, 0.92) 100%
  );
  background-size: 200% 100%;
  animation: mega-skeleton-shimmer 1.4s ease-out infinite;
}

.mega-skeleton-line--title {
  width: 46%;
}

.mega-skeleton--row .mega-skeleton-line--title {
  width: 56%;
}

.mega-skeleton-line--desc {
  width: 72%;
}

.mega-skeleton-line--count {
  width: 24px;
  height: 10px;
}

@keyframes mega-skeleton-shimmer {
  0% {
    background-position: 100% 50%;
  }

  100% {
    background-position: 0 50%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .mega-skeleton-line {
    animation: none;
  }
}
</style>
