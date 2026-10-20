<template>
  <section
    class="home-section-skeleton"
    :class="`home-section-skeleton--${type}`"
    aria-hidden="true"
  >
    <div class="container">
      <div v-if="type === 'hero'" class="home-section-skeleton__hero">
        <div class="home-section-skeleton__hero-rail">
          <span
            v-for="item in 5"
            :key="`hero-rail-${item}`"
            class="home-section-skeleton__pill home-section-skeleton__pill--rail"
          ></span>
        </div>

        <div class="home-section-skeleton__hero-body">
          <span
            class="home-section-skeleton__line home-section-skeleton__line--hero-title"
          ></span>
          <span
            class="home-section-skeleton__line home-section-skeleton__line--hero-desc"
          ></span>
          <span
            class="home-section-skeleton__line home-section-skeleton__line--hero-desc short"
          ></span>
          <div class="home-section-skeleton__actions">
            <span
              class="home-section-skeleton__pill home-section-skeleton__pill--button"
            ></span>
            <span
              class="home-section-skeleton__pill home-section-skeleton__pill--button ghost"
            ></span>
          </div>
        </div>

        <div class="home-section-skeleton__hero-features">
          <span
            v-for="item in 5"
            :key="`hero-feature-${item}`"
            class="home-section-skeleton__feature"
          ></span>
        </div>
      </div>

      <div v-else-if="type === 'products'" class="home-section-skeleton__stack">
        <div class="home-section-skeleton__heading">
          <span
            class="home-section-skeleton__line home-section-skeleton__line--title"
          ></span>
          <span
            class="home-section-skeleton__line home-section-skeleton__line--desc"
          ></span>
        </div>

        <div class="home-section-skeleton__tabs">
          <span
            v-for="item in 5"
            :key="`product-tab-${item}`"
            class="home-section-skeleton__pill"
          ></span>
        </div>

        <div
          class="home-section-skeleton__grid home-section-skeleton__grid--products"
        >
          <div
            v-for="item in 4"
            :key="`product-card-${item}`"
            class="home-section-skeleton__card"
          >
            <span
              class="home-section-skeleton__line home-section-skeleton__line--card-title"
            ></span>
            <span
              class="home-section-skeleton__line home-section-skeleton__line--card-desc"
            ></span>
            <span
              class="home-section-skeleton__line home-section-skeleton__line--card-desc short"
            ></span>
            <span
              class="home-section-skeleton__line home-section-skeleton__line--cta"
            ></span>
          </div>
        </div>
      </div>

      <div
        v-else-if="type === 'solutions'"
        class="home-section-skeleton__stack"
      >
        <div class="home-section-skeleton__heading">
          <span
            class="home-section-skeleton__line home-section-skeleton__line--title"
          ></span>
          <span
            class="home-section-skeleton__line home-section-skeleton__line--desc"
          ></span>
        </div>

        <div class="home-section-skeleton__panel">
          <div class="home-section-skeleton__panel-rail">
            <span
              v-for="item in 6"
              :key="`solution-rail-${item}`"
              class="home-section-skeleton__pill home-section-skeleton__pill--rail"
            ></span>
          </div>
          <div class="home-section-skeleton__panel-body">
            <span
              class="home-section-skeleton__line home-section-skeleton__line--panel-title"
            ></span>
            <span
              class="home-section-skeleton__line home-section-skeleton__line--panel-desc"
            ></span>
            <span
              class="home-section-skeleton__line home-section-skeleton__line--panel-desc short"
            ></span>
            <div class="home-section-skeleton__panel-points">
              <span
                v-for="item in 4"
                :key="`solution-point-${item}`"
                class="home-section-skeleton__line home-section-skeleton__line--point"
              ></span>
            </div>
            <div class="home-section-skeleton__actions">
              <span
                class="home-section-skeleton__pill home-section-skeleton__pill--button"
              ></span>
              <span
                class="home-section-skeleton__pill home-section-skeleton__pill--button ghost"
              ></span>
            </div>
          </div>
        </div>
      </div>

      <div v-else-if="type === 'news'" class="home-section-skeleton__stack">
        <div class="home-section-skeleton__heading">
          <span
            class="home-section-skeleton__line home-section-skeleton__line--title"
          ></span>
          <span
            class="home-section-skeleton__line home-section-skeleton__line--desc"
          ></span>
        </div>

        <div
          class="home-section-skeleton__grid home-section-skeleton__grid--news"
        >
          <div class="home-section-skeleton__feature-card">
            <span class="home-section-skeleton__media"></span>
            <div class="home-section-skeleton__feature-copy">
              <span
                class="home-section-skeleton__line home-section-skeleton__line--panel-title"
              ></span>
              <span
                class="home-section-skeleton__line home-section-skeleton__line--panel-desc"
              ></span>
              <span
                class="home-section-skeleton__line home-section-skeleton__line--panel-desc short"
              ></span>
            </div>
          </div>

          <div class="home-section-skeleton__list">
            <span
              v-for="item in 6"
              :key="`news-item-${item}`"
              class="home-section-skeleton__list-item"
            ></span>
          </div>
        </div>

        <div class="home-section-skeleton__promo-grid">
          <span
            v-for="item in 4"
            :key="`news-promo-${item}`"
            class="home-section-skeleton__promo"
          ></span>
        </div>
      </div>

      <div
        v-else-if="type === 'partner'"
        class="home-section-skeleton__partner"
      >
        <div class="home-section-skeleton__heading">
          <span
            class="home-section-skeleton__line home-section-skeleton__line--title"
          ></span>
          <span
            class="home-section-skeleton__line home-section-skeleton__line--desc"
          ></span>
        </div>
        <div class="home-section-skeleton__partner-rows">
          <span
            v-for="item in 5"
            :key="`partner-row-${item}`"
            class="home-section-skeleton__partner-strip"
          ></span>
        </div>
      </div>

      <div v-else class="home-section-skeleton__register">
        <span
          class="home-section-skeleton__line home-section-skeleton__line--register-title"
        ></span>
        <span
          class="home-section-skeleton__pill home-section-skeleton__pill--button"
        ></span>
      </div>
    </div>
  </section>
</template>

<script setup>
defineProps({
  type: {
    type: String,
    default: "products",
  },
});
</script>

<style scoped lang="scss">
@use "@/components/common/skeleton.scss" as sk;

.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.home-section-skeleton {
  padding: 72px 0;
  background: #ffffff;
}

.home-section-skeleton--hero {
  // 与 HomeHeroCarousel 的真实 Hero 对齐：上移一个头部高度让背景铺到顶栏下面，
  // 92/44 的上下内边距 + 460px 主舞台 + 32px 间距 + 136px 能力条，
  // 总高与真实 Hero 一致，数据到达替换时不会整体位移。
  margin-top: -64px;
  padding: 92px 0 44px;
  background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 52%, #ffffff 100%);
}

.home-section-skeleton--solutions,
.home-section-skeleton--register {
  background: #f4f7fc;
}

.home-section-skeleton--register {
  padding: 24px 0 64px;
}

.home-section-skeleton__stack {
  display: grid;
  gap: 28px;
}

.home-section-skeleton__hero {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr) minmax(340px, 420px);
  // 主舞台 460px 已包含 40/24 的上下内边距：这里写 396，加上 grid 自身 padding
  // 正好等于真实 .hero-stage 的 460，避免骨架比真实 Hero 高 64px。
  grid-template-rows: minmax(396px, auto) auto;
  column-gap: clamp(28px, 3.2vw, 48px);
  row-gap: 0;
  align-items: center;
  padding: 40px 0 24px;
}

.home-section-skeleton__hero-rail {
  display: grid;
  gap: 12px;
}

.home-section-skeleton__hero-body {
  display: grid;
  align-content: center;
  gap: 18px;
}

.home-section-skeleton__hero-features {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  // 1px 间隔露出白色底，模拟真实能力条的分隔线；高度仍与真实条一致
  gap: 1px;
  margin-top: 32px;
  background: #ffffff;
}

.home-section-skeleton__heading {
  display: grid;
  justify-items: center;
  gap: 12px;
}

.home-section-skeleton__tabs,
.home-section-skeleton__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 12px;
}

.home-section-skeleton__grid {
  display: grid;
  gap: 16px;
}

.home-section-skeleton__grid--products {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.home-section-skeleton__grid--news {
  grid-template-columns: 340px minmax(0, 1fr);
}

.home-section-skeleton__card,
.home-section-skeleton__feature-card,
.home-section-skeleton__panel,
.home-section-skeleton__register {
  position: relative;
  overflow: hidden;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #ffffff;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
}

.home-section-skeleton__card,
.home-section-skeleton__feature-card {
  padding: 20px;
}

.home-section-skeleton__panel {
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  min-height: 420px;
}

.home-section-skeleton__panel-rail {
  display: grid;
  align-content: start;
  gap: 14px;
  padding: 24px 20px;
  background: #f4f6fb;
}

.home-section-skeleton__panel-body {
  display: grid;
  align-content: start;
  gap: 14px;
  padding: 28px 32px;
}

.home-section-skeleton__panel-points {
  display: grid;
  gap: 12px;
}

.home-section-skeleton__feature-card {
  display: grid;
  gap: 18px;
}

.home-section-skeleton__media {
  display: block;
  width: 100%;
  height: 208px;
  border-radius: 6px;
  background: linear-gradient(135deg, #dfe9fb 0%, #f1f5fb 100%);
}

.home-section-skeleton__feature-copy,
.home-section-skeleton__list {
  display: grid;
  gap: 12px;
}

.home-section-skeleton__list-item,
.home-section-skeleton__promo {
  display: block;
  height: 76px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #ffffff;
}

.home-section-skeleton__promo-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}

.home-section-skeleton__register {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 28px 40px;
  background: $color-primary-hover;
  border: none;
}

.home-section-skeleton__line,
.home-section-skeleton__pill,
.home-section-skeleton__media,
.home-section-skeleton__feature,
.home-section-skeleton__list-item,
.home-section-skeleton__promo {
  position: relative;
  overflow: hidden;
  /* 底色与 1.2s 扫光统一来自 skeleton.scss，与页面骨架/顶栏骨架同一节奏 */
  @include sk.tura-skeleton-surface;
}

.home-section-skeleton__line {
  display: block;
  border-radius: 999px;
}

.home-section-skeleton__line--title {
  width: min(320px, 72%);
  height: 30px;
}

.home-section-skeleton__line--desc {
  width: min(520px, 82%);
  height: 14px;
}

.home-section-skeleton__line--hero-title {
  width: min(520px, 72%);
  height: 46px;
}

.home-section-skeleton__line--hero-desc {
  width: min(620px, 88%);
  height: 16px;
}

.home-section-skeleton__line--desc.short,
.home-section-skeleton__line--panel-desc.short,
.home-section-skeleton__line--card-desc.short,
.home-section-skeleton__line--hero-desc.short {
  width: 58%;
}

.home-section-skeleton__line--card-title {
  width: 62%;
  height: 18px;
  margin-bottom: 20px;
}

.home-section-skeleton__line--card-desc {
  width: 100%;
  height: 12px;
  margin-bottom: 10px;
}

.home-section-skeleton__line--cta {
  width: 88px;
  height: 12px;
  margin-top: 22px;
  margin-left: auto;
}

.home-section-skeleton__line--panel-title {
  width: 46%;
  height: 22px;
}

.home-section-skeleton__line--panel-desc {
  width: 96%;
  height: 13px;
}

.home-section-skeleton__line--point {
  width: 88%;
  height: 12px;
}

.home-section-skeleton__line--register-title {
  width: min(420px, 72%);
  height: 22px;
  background: rgba(255, 255, 255, 0.35);
}

.home-section-skeleton__pill {
  display: block;
  height: 34px;
  border-radius: 999px;
}

.home-section-skeleton__pill--rail {
  width: 100%;
}

.home-section-skeleton__feature {
  display: block;
  min-height: 116px;
  border-radius: 0;
}

.home-section-skeleton__pill--button {
  width: 132px;
}

.home-section-skeleton__pill--button.ghost {
  width: 108px;
}

.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(1) {
  width: 88px;
}
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(2) {
  width: 104px;
}
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(3) {
  width: 96px;
}
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(4) {
  width: 118px;
}
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(5) {
  width: 90px;
}

@media (prefers-reduced-motion: reduce) {
  .home-section-skeleton__line,
  .home-section-skeleton__pill,
  .home-section-skeleton__media,
  .home-section-skeleton__feature,
  .home-section-skeleton__list-item,
  .home-section-skeleton__promo,
  .home-section-skeleton__partner-strip {
    &::after {
      animation: none;
      display: none;
    }
  }
}

@media (max-width: 1180px) {
  .home-section-skeleton__grid--products,
  .home-section-skeleton__promo-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .home-section-skeleton__hero {
    grid-template-columns: 220px minmax(0, 1fr);
    grid-template-rows: minmax(0, auto) auto;
    padding: 28px 0 16px;
    align-items: start;
  }

  .home-section-skeleton__hero-rail {
    padding-top: 8px;
  }

  .home-section-skeleton__hero-features {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 24px;
  }
}

@media (max-width: 960px) {
  .container {
    width: calc(100% - 32px);
  }

  .home-section-skeleton__grid--news,
  .home-section-skeleton__panel,
  .home-section-skeleton__hero {
    grid-template-columns: 1fr;
  }

  .home-section-skeleton__hero {
    grid-template-rows: auto;
    gap: 18px;
    padding: 12px 0 4px;
  }

  .home-section-skeleton__hero-rail {
    display: flex;
    flex-direction: row;
    gap: 8px;
    padding: 0 0 4px;
    overflow: hidden;
  }

  .home-section-skeleton__pill--rail {
    flex: 0 0 auto;
    width: 96px;
    height: 32px;
    border-radius: 999px;
  }

  .home-section-skeleton__hero-features {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 22px;
  }

  .home-section-skeleton__register {
    flex-direction: column;
    align-items: flex-start;
    padding: 24px 20px;
  }
}

@media (max-width: 640px) {
  .container {
    width: calc(100% - 24px);
  }

  .home-section-skeleton {
    padding: 48px 0 56px;
  }

  .home-section-skeleton--register {
    padding: 24px 0 56px;
  }

  .home-section-skeleton__grid--products,
  .home-section-skeleton__promo-grid,
  .home-section-skeleton__hero-features {
    grid-template-columns: 1fr;
  }

  .home-section-skeleton__feature {
    min-height: 108px;
  }

  .home-section-skeleton__hero-rail {
    display: none;
  }

  .home-section-skeleton--hero {
    padding: 80px 0 24px;
  }
}

.home-section-skeleton__partner {
  display: grid;
  gap: 36px;
}

.home-section-skeleton__partner-rows {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

// 行数与高度需与 HomePartnerSection 的 partner-marquee 保持一致，
// 否则真实内容替换骨架时会因高度变化产生布局偏移。
.home-section-skeleton__partner-strip {
  display: block;
  height: 76px;
  border-radius: 6px;
  position: relative;
  overflow: hidden;
  @include sk.tura-skeleton-surface;
}

@media (max-width: 640px) {
  .home-section-skeleton__partner-strip {
    height: 56px;
  }
  .home-section-skeleton__partner-rows {
    gap: 8px;
  }
}
</style>
