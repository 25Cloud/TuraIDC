<template>
  <div v-if="solution" class="solution-detail">
    <section class="sd-hero">
      <div class="container sd-hero__inner">
        <div class="sd-hero__copy">
          <p class="sd-kicker">
            {{ $t("home.solutions.detail.heroKicker") }} · {{ solution.label }}
          </p>
          <h1 class="sd-title">{{ solution.title }}</h1>
          <p class="sd-desc">{{ solution.description }}</p>
          <div class="sd-actions">
            <button
              type="button"
              class="sd-action sd-action--primary"
              @click="router.push(solution.productPath)"
            >
              {{ $t("home.solutions.detail.ctaPrimary") }}
            </button>
            <button
              type="button"
              class="sd-action"
              @click="router.push(solution.consultPath)"
            >
              {{ $t("home.solutions.detail.ctaSecondary") }}
            </button>
          </div>
        </div>
        <div class="sd-hero__media" aria-hidden="true">
          <img
            class="sd-hero__image"
            :src="solution.image"
            :alt="solution.title"
            loading="eager"
            decoding="async"
          />
        </div>
      </div>
    </section>

    <section v-reveal class="sd-section">
      <div class="container">
        <h2 class="sd-section__title">
          {{ $t("home.solutions.detail.capabilitiesTitle") }}
        </h2>
        <ul class="sd-grid">
          <li v-for="point in solution.points" :key="point" class="sd-grid__item">
            <span class="sd-grid__mark" aria-hidden="true">
              <el-icon><Select /></el-icon>
            </span>
            <span>{{ point }}</span>
          </li>
        </ul>
      </div>
    </section>

    <section
      v-if="solution.scenarios.length > 0"
      v-reveal
      class="sd-section sd-section--alt"
    >
      <div class="container">
        <h2 class="sd-section__title">
          {{ $t("home.solutions.detail.scenariosTitle") }}
        </h2>
        <ul class="sd-scenarios">
          <li
            v-for="scenario in solution.scenarios"
            :key="scenario"
            class="sd-scenario"
          >
            {{ scenario }}
          </li>
        </ul>
      </div>
    </section>

    <section v-reveal class="sd-section">
      <div class="container">
        <h2 class="sd-section__title">{{ $t("home.solutions.detail.flowTitle") }}</h2>
        <ol class="sd-flow">
          <li v-for="step in flowSteps" :key="step.no" class="sd-flow__item">
            <span class="sd-flow__no">{{ step.no }}</span>
            <h3 class="sd-flow__title">{{ step.title }}</h3>
            <p class="sd-flow__desc">{{ step.desc }}</p>
          </li>
        </ol>
      </div>
    </section>

    <section class="sd-cta">
      <div class="container">
        <h2 class="sd-cta__title">{{ $t("home.solutions.detail.ctaTitle") }}</h2>
        <p class="sd-cta__desc">{{ $t("home.solutions.detail.ctaDesc") }}</p>
        <div class="sd-actions sd-actions--center">
          <button
            type="button"
            class="sd-action sd-action--primary"
            @click="router.push(solution.productPath)"
          >
            {{ $t("home.solutions.detail.ctaPrimary") }}
          </button>
          <button
            type="button"
            class="sd-action"
            @click="router.push(solution.consultPath)"
          >
            {{ $t("home.solutions.detail.ctaSecondary") }}
          </button>
        </div>
      </div>
    </section>

    <section v-reveal class="sd-section sd-section--alt">
      <div class="container">
        <h2 class="sd-section__title">{{ $t("home.solutions.detail.otherTitle") }}</h2>
        <div class="sd-others">
          <router-link
            v-for="item in otherSolutions"
            :key="item.key"
            class="sd-other"
            :to="`/solutions/${item.key}`"
          >
            {{ item.label }}
          </router-link>
        </div>
        <router-link class="sd-back" to="/about">
          {{ $t("home.solutions.detail.backToList") }}
        </router-link>
      </div>
    </section>
  </div>

  <div v-else class="solution-detail solution-detail--missing">
    <div class="container">
      <h1 class="sd-title">{{ $t("home.solutions.detail.notFoundTitle") }}</h1>
      <p class="sd-desc">{{ $t("home.solutions.detail.notFoundDesc") }}</p>
      <button
        type="button"
        class="sd-action sd-action--primary"
        @click="router.push('/about')"
      >
        {{ $t("home.solutions.detail.backToList") }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElIcon } from "element-plus/es/components/icon/index.mjs";
import { Select } from "@element-plus/icons-vue";
import { t } from "@/locales";
import { buildIndustrySolutions, findIndustrySolution } from "@/data/homeSolutions";

const route = useRoute();
const router = useRouter();

const solution = computed(() => findIndustrySolution(String(route.params.key || "")));

// 其余行业用于底部互链；排除当前页，避免自己链自己
const otherSolutions = computed(() =>
  buildIndustrySolutions().filter((item) => item.key !== solution.value?.key),
);

// 接入流程固定四步，文案在语言包里
const flowSteps = computed(() =>
  [1, 2, 3, 4].map((index) => ({
    no: `0${index}`,
    title: t(`home.solutions.detail.flowStep${index}`),
    desc: t(`home.solutions.detail.flowStep${index}Desc`),
  })),
);
</script>

<style scoped lang="scss">
.container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.solution-detail--missing {
  padding: 96px 0;
}

.sd-hero {
  padding: 64px 0 56px;
  background: linear-gradient(180deg, #ffffff 0%, #f5f8fd 100%);
  overflow: hidden;
}

.sd-hero__inner {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.85fr);
  align-items: center;
  gap: 48px;
}

.sd-kicker {
  margin: 0 0 12px;
  color: $color-primary;
  font-size: 14px;
  font-weight: 600;
}

.sd-title {
  margin: 0;
  color: #0f172a;
  font-size: clamp(28px, 2.8vw, 38px);
  font-weight: 700;
  line-height: 1.28;
}

.sd-desc {
  max-width: 620px;
  margin: 16px 0 0;
  color: #4b5565;
  font-size: 15px;
  line-height: 1.9;
}

.sd-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 28px;
}

.sd-actions--center {
  justify-content: center;
}

.sd-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 128px;
  height: 42px;
  padding: 0 22px;
  border: 1px solid #d5dbe8;
  border-radius: 4px;
  background: #fff;
  color: #1f2937;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition:
    border-color 0.2s ease,
    background 0.2s ease,
    color 0.2s ease,
    transform 0.2s ease;
}

.sd-action:hover {
  border-color: rgba($color-primary, 0.48);
  color: $color-primary;
  transform: translateY(-1px);
}

.sd-action--primary {
  border-color: $color-primary;
  background: $color-primary;
  color: #fff;
}

.sd-action--primary:hover {
  border-color: $color-primary-hover;
  background: $color-primary-hover;
  color: #fff;
}

.sd-hero__media {
  display: flex;
  justify-content: center;
}

.sd-hero__image {
  width: 100%;
  max-width: 420px;
  height: auto;
  animation: sd-float 9s ease-in-out infinite alternate;
}

/* 配图缓慢上下浮动：静态大图读起来像截图 */
@keyframes sd-float {
  from {
    transform: translateY(-6px);
  }

  to {
    transform: translateY(6px);
  }
}

.sd-section {
  padding: 56px 0;
}

.sd-section--alt {
  background: #f7f9fd;
}

.sd-section__title {
  margin: 0 0 24px;
  color: #0f172a;
  font-size: 24px;
  font-weight: 700;
}

.sd-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.sd-grid__item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 20px 22px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  color: #1f2937;
  font-size: 14px;
  line-height: 1.7;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
}

.sd-grid__mark {
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  flex-shrink: 0;
  border-radius: 50%;
  background: rgba($color-primary, 0.12);
  color: $color-primary;
  font-size: 12px;
}

.sd-scenarios {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.sd-scenario {
  position: relative;
  padding: 22px 24px 22px 48px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
  color: #1f2937;
  font-size: 14px;
  line-height: 1.7;

  &::before {
    content: "";
    position: absolute;
    left: 22px;
    top: 30px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: $color-primary;
    box-shadow: 0 0 0 4px rgba($color-primary, 0.12);
  }
}

.sd-flow {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.sd-flow__item {
  padding: 24px;
  border: 1px solid #e5eaf3;
  border-radius: 6px;
  background: #fff;
}

.sd-flow__no {
  display: block;
  color: rgba($color-primary, 0.5);
  font-size: 26px;
  font-weight: 700;
  line-height: 1;
}

.sd-flow__title {
  margin: 12px 0 0;
  color: #0f172a;
  font-size: 15px;
  font-weight: 600;
}

.sd-flow__desc {
  margin: 8px 0 0;
  color: #64748b;
  font-size: 13px;
  line-height: 1.8;
}

.sd-cta {
  padding: 64px 0;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
  text-align: center;
}

.sd-cta__title {
  margin: 0;
  color: #fff;
  font-size: 26px;
  font-weight: 700;
}

.sd-cta__desc {
  max-width: 620px;
  margin: 14px auto 0;
  color: rgba(255, 255, 255, 0.78);
  font-size: 14px;
  line-height: 1.9;
}

.sd-cta .sd-action {
  border-color: rgba(255, 255, 255, 0.32);
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}

.sd-cta .sd-action--primary {
  border-color: #fff;
  background: #fff;
  color: #0f172a;
}

.sd-others {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.sd-other {
  padding: 10px 20px;
  border: 1px solid #d5dbe8;
  border-radius: 999px;
  background: #fff;
  color: #1f2937;
  font-size: 14px;
  text-decoration: none;
  transition:
    border-color 0.2s ease,
    color 0.2s ease;
}

.sd-other:hover {
  border-color: rgba($color-primary, 0.48);
  color: $color-primary;
}

.sd-back {
  display: inline-block;
  margin-top: 28px;
  color: $color-primary;
  font-size: 14px;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

@media (max-width: 960px) {
  .sd-hero__inner {
    grid-template-columns: 1fr;
    gap: 32px;
  }

  .sd-hero__media {
    order: -1;
    justify-content: flex-start;
  }

  .sd-hero__image {
    max-width: 240px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .sd-hero__image {
    animation: none;
  }

  .sd-action:hover {
    transform: none;
  }
}
</style>