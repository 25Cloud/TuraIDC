<template>
  <section class="home-section-skeleton" :class="`home-section-skeleton--${type}`" aria-hidden="true">
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
          <span class="home-section-skeleton__line home-section-skeleton__line--hero-title"></span>
          <span class="home-section-skeleton__line home-section-skeleton__line--hero-desc"></span>
          <span class="home-section-skeleton__line home-section-skeleton__line--hero-desc short"></span>
          <div class="home-section-skeleton__actions">
            <span class="home-section-skeleton__pill home-section-skeleton__pill--button"></span>
            <span class="home-section-skeleton__pill home-section-skeleton__pill--button ghost"></span>
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
          <span class="home-section-skeleton__line home-section-skeleton__line--title"></span>
          <span class="home-section-skeleton__line home-section-skeleton__line--desc"></span>
        </div>

        <div class="home-section-skeleton__tabs">
          <span
            v-for="item in 5"
            :key="`product-tab-${item}`"
            class="home-section-skeleton__pill"
          ></span>
        </div>

        <div class="home-section-skeleton__grid home-section-skeleton__grid--products">
          <div
            v-for="item in 4"
            :key="`product-card-${item}`"
            class="home-section-skeleton__card"
          >
            <span class="home-section-skeleton__line home-section-skeleton__line--card-title"></span>
            <span class="home-section-skeleton__line home-section-skeleton__line--card-desc"></span>
            <span class="home-section-skeleton__line home-section-skeleton__line--card-desc short"></span>
            <span class="home-section-skeleton__line home-section-skeleton__line--cta"></span>
          </div>
        </div>
      </div>

      <div v-else-if="type === 'solutions'" class="home-section-skeleton__stack">
        <div class="home-section-skeleton__heading">
          <span class="home-section-skeleton__line home-section-skeleton__line--title"></span>
          <span class="home-section-skeleton__line home-section-skeleton__line--desc"></span>
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
            <span class="home-section-skeleton__line home-section-skeleton__line--panel-title"></span>
            <span class="home-section-skeleton__line home-section-skeleton__line--panel-desc"></span>
            <span class="home-section-skeleton__line home-section-skeleton__line--panel-desc short"></span>
            <div class="home-section-skeleton__panel-points">
              <span
                v-for="item in 4"
                :key="`solution-point-${item}`"
                class="home-section-skeleton__line home-section-skeleton__line--point"
              ></span>
            </div>
            <div class="home-section-skeleton__actions">
              <span class="home-section-skeleton__pill home-section-skeleton__pill--button"></span>
              <span class="home-section-skeleton__pill home-section-skeleton__pill--button ghost"></span>
            </div>
          </div>
        </div>
      </div>

      <div v-else-if="type === 'news'" class="home-section-skeleton__stack">
        <div class="home-section-skeleton__heading">
          <span class="home-section-skeleton__line home-section-skeleton__line--title"></span>
          <span class="home-section-skeleton__line home-section-skeleton__line--desc"></span>
        </div>

        <div class="home-section-skeleton__grid home-section-skeleton__grid--news">
          <div class="home-section-skeleton__feature-card">
            <span class="home-section-skeleton__media"></span>
            <div class="home-section-skeleton__feature-copy">
              <span class="home-section-skeleton__line home-section-skeleton__line--panel-title"></span>
              <span class="home-section-skeleton__line home-section-skeleton__line--panel-desc"></span>
              <span class="home-section-skeleton__line home-section-skeleton__line--panel-desc short"></span>
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

      <div v-else-if="type === 'partner'" class="home-section-skeleton__partner">
        <div class="home-section-skeleton__heading">
          <span class="home-section-skeleton__line home-section-skeleton__line--title"></span>
          <span class="home-section-skeleton__line home-section-skeleton__line--desc"></span>
        </div>
        <div class="home-section-skeleton__partner-rows">
          <span
            v-for="item in 6"
            :key="`partner-row-${item}`"
            class="home-section-skeleton__partner-strip"
          ></span>
        </div>
      </div>

      <div v-else class="home-section-skeleton__register">
        <span class="home-section-skeleton__line home-section-skeleton__line--register-title"></span>
        <span class="home-section-skeleton__pill home-section-skeleton__pill--button"></span>
      </div>
    </div>
  </section>
</template>

<script setup>
defineProps({
  type: {
    type: String,
    default: 'products',
  },
})
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.home-section-skeleton {
  padding: 72px 0;
  background: #ffffff;
}

.home-section-skeleton--hero {
  min-height: 640px;
  padding: 120px 0 32px;
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
  grid-template-columns: 240px minmax(0, 1fr);
  grid-template-rows: minmax(320px, auto) auto;
  gap: 28px 48px;
  align-items: center;
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
  gap: 14px;
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
  border-radius: 14px;
  background: #ffffff;
  box-shadow: 0 14px 28px rgba(15, 23, 42, 0.05);
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
  border-radius: 10px;
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
  border-radius: 12px;
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
  background: #1e49cf;
  border: none;
  box-shadow: 0 24px 48px rgba(27, 58, 166, 0.18);
}

.home-section-skeleton__line,
.home-section-skeleton__pill,
.home-section-skeleton__media,
.home-section-skeleton__feature,
.home-section-skeleton__list-item,
.home-section-skeleton__promo {
  position: relative;
  overflow: hidden;
  background: linear-gradient(90deg, rgba(222, 230, 242, 0.92) 0%, rgba(245, 248, 252, 0.98) 50%, rgba(222, 230, 242, 0.92) 100%);
  background-size: 200% 100%;
  animation: home-section-skeleton-shimmer 1.4s ease-out infinite;
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
  min-height: 108px;
  border-radius: 14px;
}

.home-section-skeleton__pill--button {
  width: 132px;
}

.home-section-skeleton__pill--button.ghost {
  width: 108px;
}

.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(1) { width: 88px; }
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(2) { width: 104px; }
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(3) { width: 96px; }
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(4) { width: 118px; }
.home-section-skeleton__tabs .home-section-skeleton__pill:nth-child(5) { width: 90px; }

@keyframes home-section-skeleton-shimmer {
  0% {
    background-position: 100% 50%;
  }

  100% {
    background-position: 0 50%;
  }
}

@media (max-width: 1180px) {
  .home-section-skeleton__grid--products,
  .home-section-skeleton__promo-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
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
    gap: 24px;
  }

  .home-section-skeleton__hero-features {
    grid-template-columns: repeat(2, minmax(0, 1fr));
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

  .home-section-skeleton--hero {
    min-height: 560px;
    padding: 92px 0 28px;
  }
}

.home-section-skeleton__partner {
  display: grid;
  gap: 28px;
}

.home-section-skeleton__partner-rows {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.home-section-skeleton__partner-strip {
  display: block;
  height: 80px;
  border-radius: 8px;

  position: relative;
  overflow: hidden;
  background: linear-gradient(90deg, rgba(222, 230, 242, 0.92) 0%, rgba(245, 248, 252, 0.98) 50%, rgba(222, 230, 242, 0.92) 100%);
  background-size: 200% 100%;
  animation: home-section-skeleton-shimmer 1.4s ease-out infinite;
}

@media (max-width: 640px) {
  .home-section-skeleton__partner-strip {
    height: 56px;
  }
  .home-section-skeleton__partner-rows {
    gap: 6px;
  }
}
</style>
