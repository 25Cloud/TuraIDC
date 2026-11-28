<template>
  <div class="solutions-index">
    <section class="si-hero">
      <div class="container">
        <h1 class="si-title">{{ $t("home.solutions.title") }}</h1>
        <p class="si-desc">{{ $t("home.solutions.subtitle") }}</p>
      </div>
    </section>

    <section v-reveal class="si-section">
      <div class="container">
        <div class="si-grid">
          <router-link
            v-for="item in solutions"
            :key="item.key"
            class="si-card"
            :to="`/solutions/${item.key}`"
            :style="{ '--si-accent': item.accent, '--si-accent-soft': item.accentSoft }"
          >
            <span class="si-card__media" aria-hidden="true">
              <img :src="item.image" :alt="item.title" loading="lazy" decoding="async" />
            </span>
            <h2 class="si-card__title">{{ item.title }}</h2>
            <p class="si-card__desc">{{ item.description }}</p>
            <span class="si-card__cta">
              {{ $t("home.solutions.learnMore") }}
              <span aria-hidden="true">→</span>
            </span>
          </router-link>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { buildIndustrySolutions } from "@/data/homeSolutions";

const solutions = computed(() => buildIndustrySolutions());
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.si-hero {
  padding: 72px 0 32px;
  text-align: center;
  background: linear-gradient(180deg, #fff 0%, #f5f8fd 100%);
}

.si-kicker {
  margin: 0 0 12px;
  color: $color-primary;
  font-size: 14px;
  font-weight: 600;
}

.si-title {
  margin: 0;
  color: #0f172a;
  font-size: clamp(28px, 2.8vw, 38px);
  font-weight: 700;
  line-height: 1.28;
}

.si-desc {
  max-width: 720px;
  margin: 16px auto 0;
  color: #5b6b82;
  font-size: 15px;
  line-height: 1.9;
}

.si-section {
  padding: 40px 0 72px;
  background: #f5f8fd;
}

.si-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
}

.si-card {
  display: flex;
  flex-direction: column;
  padding: 26px 26px 22px;
  border: 1px solid #e5eaf3;
  border-radius: 8px;
  background: #fff;
  color: inherit;
  text-decoration: none;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease,
    transform 0.2s ease;
}

.si-card:hover {
  border-color: var(--si-accent, #{$color-primary});
  box-shadow: 0 10px 28px rgba(15, 23, 42, 0.09);
  transform: translateY(-3px);
}

.si-card__media {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  margin-bottom: 18px;
  border-radius: 12px;
  background: var(--si-accent-soft, rgba(22, 93, 255, 0.12));
}

.si-card__media img {
  width: 40px;
  height: 40px;
  object-fit: contain;
}

.si-card__title {
  margin: 0;
  color: #0f172a;
  font-size: 17px;
  font-weight: 700;
  line-height: 1.4;
}

.si-card__desc {
  margin: 10px 0 0;
  flex: 1;
  color: #64748b;
  font-size: 13.5px;
  line-height: 1.8;
}

.si-card__cta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 18px;
  color: var(--si-accent, #{$color-primary});
  font-size: 13px;
  font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
  .si-card:hover {
    transform: none;
  }
}
</style>