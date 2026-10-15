<template>
  <section class="partner-section">
    <header class="partner-section__head">
      <h2 class="partner-section__title">{{ $t('home.partners.title') }}</h2>
      <p class="partner-section__desc">
        以优质资源与服务领航开拓，助力更多企业高效上云
      </p>
    </header>

    <!--
      条幅是一整张已排版好的长图，图内含多个 Logo 卡片，无法拆成单个 Logo
      渲染，只能整体横向滚动。列表语义保留：首份图片的 alt 写明了具体合作方，
      读屏用户能获知这部分内容。
    -->
    <ul ref="marquee" class="partner-marquee">
      <li
        v-for="strip in partnerStrips"
        :key="strip.id"
        class="partner-marquee__row"
        :class="`partner-marquee__row--${strip.direction}`"
      >
        <!--
          轨道整体左移一个条幅宽度完成循环，位移量为轨道宽度的一半。
          单份条幅窄于可视宽度时轨道右端盖不住视口，右侧就会露出空白，
          因此按可视宽度动态补足份数，详见 syncCopyCount。
        -->
        <div
          class="partner-marquee__track"
          :style="{ animationDuration: strip.speed }"
        >
          <img
            v-for="copy in copyCount"
            :key="copy"
            class="partner-marquee__strip"
            :src="strip.src"
            :alt="copy === 1 ? strip.alt : ''"
            :aria-hidden="copy !== 1"
            loading="lazy"
            decoding="async"
            draggable="false"
          />
        </div>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";

import {
  MIN_MARQUEE_COPIES,
  partnerStrips,
  resolveMarqueeCopies,
} from "@/data/homePartners";

/** 每行轨道渲染的条幅份数，按可视宽度动态补足。 */
const copyCount = ref(MIN_MARQUEE_COPIES);

const marquee = ref<HTMLElement | null>(null);
let observer: ResizeObserver | null = null;

/**
 * 条幅等高渲染，宽度只由素材原始比例决定，各行差异极小，
 * 因此量第一行即可代表全部，无需每行单独计算。
 */
function firstRow(): HTMLElement | null {
  return marquee.value?.querySelector(".partner-marquee__row") ?? null;
}

function syncCopyCount(): void {
  const row = firstRow();
  const stripWidth =
    row?.querySelector(".partner-marquee__strip")?.getBoundingClientRect()
      .width ?? 0;

  copyCount.value = resolveMarqueeCopies(row?.clientWidth ?? 0, stripWidth);
}

onMounted(() => {
  const row = firstRow();
  const strip = row?.querySelector(".partner-marquee__strip");

  if (!row || !strip) {
    return;
  }

  syncCopyCount();

  // 容器尺寸变化影响可视宽度；条幅在图片加载完成前宽度为 0，
  // 加载完成后才有真实尺寸，因此两处都要观察，否则会一直停留在兜底份数。
  observer = new ResizeObserver(syncCopyCount);
  observer.observe(row);
  observer.observe(strip);
});

onBeforeUnmount(() => {
  observer?.disconnect();
  observer = null;
});
</script>

<style scoped lang="scss">
// 设计 token 由 vite.config.js 的 css.preprocessorOptions.additionalData
// 全局注入（@use ".../variables.scss" as *），此处无需重复引入。
.partner-section {
  padding: 72px 0 64px;
  background: $bg-color-card;

  &__head {
    margin-bottom: 36px;
    text-align: center;
  }

  &__title {
    margin: 0 0 10px;
    color: $text-color-primary;
    font-size: clamp(24px, 2.6vw, 30px);
    font-weight: 700;
    line-height: 1.3;
  }

  &__desc {
    margin: 0;
    color: $text-color-secondary;
    font-size: 14px;
    line-height: 1.8;
  }
}

.partner-marquee {
  display: grid;
  gap: 12px;
  margin: 0;
  padding: 0 0 2px;
  list-style: none;

  // 左右渐隐：条幅在容器边缘淡出，避免生硬切断。
  mask-image: linear-gradient(
    to right,
    transparent,
    #000 10%,
    #000 90%,
    transparent
  );
}

.partner-marquee__row {
  overflow: hidden;

  // 悬停或键盘聚焦时暂停，让用户能停下来看清具体是哪些合作伙伴。
  &:hover,
  &:focus-within {
    .partner-marquee__track {
      animation-play-state: paused;
    }
  }

  &--reverse .partner-marquee__track {
    animation-direction: reverse;
  }
}

.partner-marquee__track {
  display: flex;
  width: max-content;
  animation-name: partner-marquee-scroll;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}

.partner-marquee__strip {
  display: block;
  flex: 0 0 auto;
  height: 76px;
  width: auto;
  user-select: none;
}

@keyframes partner-marquee-scroll {
  from {
    transform: translateX(0);
  }

  to {
    transform: translateX(-50%);
  }
}

// 尊重系统的「减少动态效果」设置：静止展示，不做任何位移动画。
@media (prefers-reduced-motion: reduce) {
  .partner-marquee__track {
    animation: none;
  }
}

@media (max-width: 640px) {
  .partner-section {
    padding: 48px 0 40px;

    &__head {
      margin-bottom: 24px;
    }
  }

  .partner-marquee {
    gap: 8px;

    // 窄屏两侧渐隐范围收窄，避免中间可见 Logo 过少。
    mask-image: linear-gradient(
      to right,
      transparent,
      #000 6%,
      #000 94%,
      transparent
    );
  }

  .partner-marquee__strip {
    height: 56px;
  }
}
</style>
