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
@use "@/components/common/skeleton.scss" as sk;

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

/* 底色与 1.2s 扫光统一走 skeleton.scss，和首页/页面骨架同一节奏 */
.mega-skeleton-line {
  display: block;
  height: 12px;
  border-radius: 999px;
  @include sk.tura-skeleton-surface;
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

@media (prefers-reduced-motion: reduce) {
  .mega-skeleton-line {
    &::after {
      animation: none;
      display: none;
    }
  }
}
</style>
