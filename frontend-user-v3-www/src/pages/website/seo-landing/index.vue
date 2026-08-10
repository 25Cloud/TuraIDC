<template>
  <article class="seo-landing-page">
    <section class="seo-hero">
      <div class="seo-hero__ambient" aria-hidden="true">
        <span class="seo-hero__line seo-hero__line--one"></span>
        <span class="seo-hero__line seo-hero__line--two"></span>
        <span class="seo-hero__line seo-hero__line--three"></span>
        <img class="seo-hero__asset" :src="page.visual.src" :alt="page.visual.alt" />
      </div>

      <div class="seo-container seo-hero__inner">
        <nav class="seo-hero__nav" aria-label="核心卖点">
          <a
            v-for="(item, index) in heroNavItems"
            :key="item"
            :class="{ 'is-active': index === 0 }"
            href="#seo-products"
          >
            {{ item }}
          </a>
        </nav>

        <div class="seo-hero__content">
          <p class="seo-hero__kicker">
            {{ page.hero.summary }}
            <span>{{ page.hero.eyebrow }}</span>
          </p>
          <h1>{{ page.hero.title }}</h1>
          <p class="seo-hero__summary">{{ page.description }}</p>

          <div class="seo-actions">
            <router-link class="seo-action seo-action--primary" :to="page.cta.primaryTo">
              <span>{{ page.cta.primaryText }}</span>
              <el-icon><ArrowRight /></el-icon>
            </router-link>
            <a class="seo-action seo-action--plain" :href="secondaryCtaHref">
              <span>{{ page.cta.secondaryText }}</span>
            </a>
          </div>
        </div>
      </div>
    </section>

    <section class="seo-feature-strip" aria-label="服务能力摘要">
      <div class="seo-container seo-feature-strip__inner">
        <article
          v-for="(item, index) in featureStripItems"
          :key="item.title"
          class="seo-strip-card"
          :class="{ 'seo-strip-card--cta': index === featureStripItems.length - 1 }"
        >
          <span class="seo-strip-card__icon" aria-hidden="true"></span>
          <div>
            <h2>{{ item.title }}</h2>
            <p>{{ item.description }}</p>
          </div>
          <router-link
            v-if="item.to"
            class="seo-strip-card__link"
            :to="item.to"
          >
            {{ item.linkText }}
          </router-link>
        </article>
      </div>
    </section>

    <section id="seo-products" class="seo-section seo-section--products">
      <div class="seo-container">
        <div class="seo-section__heading seo-section__heading--center">
          <h2>安全、稳定、可信赖的{{ page.keyword }}服务</h2>
          <p>围绕真实业务选型整理产品能力、适用场景和开通入口，让搜索进入的用户能直接完成判断。</p>
          <router-link to="/products">查看全部产品 &gt;</router-link>
        </div>

        <div class="seo-category-tabs" aria-label="产品分类">
          <button
            v-for="tab in productTabs"
            :key="tab"
            :class="{ 'is-active': tab === '推荐' }"
            type="button"
          >
            <span class="seo-category-tabs__icon" aria-hidden="true"></span>
            {{ tab }}
          </button>
        </div>

        <div class="seo-product-grid">
          <article
            v-for="(feature, index) in page.features"
            :key="feature.title"
            class="seo-product-card"
            :class="{ 'seo-product-card--featured': index === 0 }"
          >
            <div class="seo-product-card__head">
              <h3>{{ index === 0 ? page.keyword : feature.title }}</h3>
              <span>{{ index === 0 ? '推荐' : '能力' }}</span>
            </div>
            <p>{{ feature.description }}</p>
            <div class="seo-product-card__tags">
              <span v-for="tag in productTags(index)" :key="`${feature.title}-${tag}`">
                {{ tag }}
              </span>
            </div>
            <div class="seo-product-card__actions">
              <router-link class="is-primary" :to="page.cta.primaryTo">立即选购</router-link>
              <router-link to="/help">产品详情</router-link>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section id="seo-solutions" class="seo-section seo-section--solutions">
      <div class="seo-container">
        <div class="seo-section__heading seo-section__heading--center">
          <h2>为不同业务场景提供{{ page.keyword }}方案</h2>
          <p>从访问区域、系统环境、带宽需求和售后协同出发，先明确场景，再选择资源。</p>
        </div>

        <div class="seo-solution-panel">
          <nav class="seo-solution-tabs" aria-label="场景列表">
            <button
              v-for="(scenario, index) in page.scenarios"
              :key="scenario.title"
              :class="{ 'is-active': index === activeScenarioIndex }"
              type="button"
              @click="activeScenarioIndex = index"
            >
              {{ scenario.title }}
            </button>
          </nav>

          <div class="seo-solution-content">
            <p class="seo-eyebrow">解决方案</p>
            <h3>{{ activeScenario.title }}</h3>
            <p>{{ activeScenario.description }}</p>
            <ul>
              <li v-for="feature in page.features" :key="feature.title">
                <el-icon><Check /></el-icon>
                <span>{{ feature.title }}</span>
              </li>
            </ul>
            <div class="seo-solution-content__related">
              <strong>相关产品：</strong>
              <router-link v-for="link in page.relatedLinks" :key="link.to" :to="link.to">
                {{ link.label }}
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section id="seo-process" class="seo-section seo-section--process">
      <div class="seo-container">
        <div class="seo-section__heading seo-section__heading--center">
          <h2>从搜索了解，到开通{{ page.keyword }}</h2>
          <p>把官网内容、产品列表、注册入口和工单咨询连起来，减少用户在多个页面之间反复寻找。</p>
        </div>

        <div class="seo-process">
          <article v-for="step in processSteps" :key="step.title" class="seo-process__item">
            <span>{{ step.index }}</span>
            <h3>{{ step.title }}</h3>
            <p>{{ step.description }}</p>
          </article>
        </div>
      </div>
    </section>

    <section id="seo-faq" class="seo-section seo-section--faq">
      <div class="seo-container">
        <div class="seo-section__heading seo-section__heading--center">
          <h2>选择{{ page.keyword }}前的常见问题</h2>
          <p>FAQ 使用真实搜索问题组织，避免堆砌关键词，让页面正文更容易被理解和收录。</p>
        </div>

        <div class="seo-faq-list">
          <article v-for="faq in page.faqs" :key="faq.question" class="seo-faq">
            <h3>
              <el-icon><QuestionFilled /></el-icon>
              {{ faq.question }}
            </h3>
            <p>{{ faq.answer }}</p>
          </article>
        </div>
      </div>
    </section>

    <section class="seo-register">
      <div class="seo-container seo-register__inner">
        <div>
          <p>{{ page.keyword }}咨询与开通</p>
          <h2>{{ page.cta.title }}</h2>
          <span class="seo-register__description">{{ page.cta.description }}</span>
        </div>
        <div class="seo-register__actions">
          <router-link class="seo-action seo-action--white" :to="page.cta.primaryTo">
            {{ page.cta.primaryText }}
          </router-link>
          <a class="seo-action seo-action--outline" :href="secondaryCtaHref">
            <span>{{ page.cta.secondaryText }}</span>
            <el-icon><ArrowRight /></el-icon>
          </a>
        </div>
      </div>
    </section>
  </article>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowRight, Check, QuestionFilled } from '@element-plus/icons-vue'
import { buildConsoleUrl } from '@/utils/consoleUrl'
import { getSeoLandingPageByPath, seoLandingPages } from '@/data/seoLandingPages'

const route = useRoute()
const activeScenarioIndex = ref(0)

const page = computed(() => (
  getSeoLandingPageByPath(route.meta?.seoLandingPath || route.path) || seoLandingPages[0]
))

const heroNavItems = computed(() => [
  `${page.value.hero.eyebrow} · ${page.value.hero.points[0]}`,
  ...page.value.hero.points.slice(1).map((point) => `${point} · ${page.value.keyword}`),
  page.value.hero.stats[0]?.label || '稳定服务',
].slice(0, 4))

const featureStripItems = computed(() => [
  ...page.value.features.slice(0, 3).map((feature) => ({
    title: feature.title,
    description: feature.description,
  })),
  {
    title: page.value.cta.title,
    description: page.value.cta.description,
    linkText: page.value.cta.primaryText,
    to: page.value.cta.primaryTo,
  },
])

const activeScenario = computed(() => (
  page.value.scenarios[activeScenarioIndex.value] || page.value.scenarios[0]
))

const secondaryCtaHref = computed(() => buildConsoleUrl(page.value.cta.secondaryConsolePath))

const productTabs = ['推荐', '计算', '安全', '网络', '运维']
const defaultTags = [
  ['弹性配置', '稳定可靠', '快速交付'],
  ['标准部署', '可扩展', '工单支持'],
  ['集中管理', '持续维护', '按需选择'],
]
const processSteps = [
  { index: '01', title: '明确场景', description: '确认业务访问区域、系统环境、带宽需求和预算范围。' },
  { index: '02', title: '查看配置', description: '在产品列表中筛选合适的规格、节点和服务周期。' },
  { index: '03', title: '注册开通', description: '进入控制台完成账号注册、下单、支付和服务管理。' },
  { index: '04', title: '持续维护', description: '通过公告、帮助中心和工单体系跟踪使用与售后问题。' },
]

function productTags(index) {
  return defaultTags[index] || defaultTags[0]
}

watch(() => route.path, () => {
  activeScenarioIndex.value = 0
})
</script>

<style scoped lang="scss">
.seo-landing-page {
  min-height: 100%;
  overflow: hidden;
  background: #f5f7fa;
  color: $text-color-primary;
}

.seo-container {
  width: min(1200px, calc(100% - 48px));
  margin: 0 auto;
}

.seo-hero {
  position: relative;
  min-height: 520px;
  overflow: hidden;
  background:
    radial-gradient(circle at 82% 10%, rgba(255, 255, 255, 0.86), transparent 24%),
    linear-gradient(110deg, rgba(247, 251, 255, 0.98) 0%, rgba(230, 239, 255, 0.92) 44%, rgba(196, 218, 255, 0.88) 100%);
}

.seo-hero::before {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    linear-gradient(90deg, rgba(22, 93, 255, 0.08) 1px, transparent 1px),
    linear-gradient(180deg, rgba(22, 93, 255, 0.06) 1px, transparent 1px);
  background-size: 64px 64px;
  mask-image: linear-gradient(90deg, transparent 0%, rgba(0, 0, 0, 0.6) 50%, rgba(0, 0, 0, 0.84) 100%);
  content: '';
}

.seo-hero__ambient {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
}

.seo-hero__ambient::before {
  position: absolute;
  right: -8%;
  bottom: -20%;
  width: 72%;
  height: 56%;
  border-radius: 50% 50% 0 0;
  background:
    repeating-linear-gradient(92deg, rgba(255, 255, 255, 0.78) 0 2px, transparent 2px 26px),
    linear-gradient(180deg, rgba(94, 147, 255, 0.2), rgba(55, 115, 255, 0.06));
  transform: rotate(-8deg);
  content: '';
}

.seo-hero__line {
  position: absolute;
  border: 2px solid rgba(255, 255, 255, 0.72);
  border-radius: 999px;
  transform: rotate(-18deg);
}

.seo-hero__line--one {
  right: 24%;
  bottom: 110px;
  width: 420px;
  height: 96px;
}

.seo-hero__line--two {
  right: 12%;
  bottom: 154px;
  width: 250px;
  height: 58px;
  opacity: 0.64;
}

.seo-hero__line--three {
  right: 38%;
  bottom: 72px;
  width: 240px;
  height: 48px;
  opacity: 0.44;
}

.seo-hero__asset {
  position: absolute;
  right: 7%;
  bottom: 68px;
  width: min(220px, 22vw);
  max-height: 180px;
  object-fit: contain;
  opacity: 0.22;
  filter: saturate(1.1);
}

.seo-hero__inner {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: 250px minmax(0, 1fr);
  gap: 72px;
  align-items: center;
  min-height: 520px;
  padding: 56px 0 72px;
}

.seo-hero__nav {
  position: relative;
  display: grid;
  gap: 30px;
  padding-left: 12px;
}

.seo-hero__nav::after {
  position: absolute;
  top: 0;
  right: -34px;
  bottom: 0;
  width: 1px;
  background: linear-gradient(180deg, transparent, rgba(22, 93, 255, 0.22), transparent);
  content: '';
}

.seo-hero__nav a {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 28px;
  color: #273449;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.5;
  text-decoration: none;
}

.seo-hero__nav a.is-active {
  color: #165dff;
}

.seo-hero__nav a.is-active::after {
  position: absolute;
  right: -36px;
  width: 3px;
  height: 42px;
  border-radius: 999px;
  background: #165dff;
  content: '';
}

.seo-hero__content {
  min-width: 0;
  max-width: 720px;
}

.seo-hero__kicker {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  max-width: 700px;
  margin: 0;
  color: #25324a;
  font-size: 14px;
  line-height: 1.7;
}

.seo-hero__kicker span {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 0 7px;
  border-radius: 3px;
  background: #ff3d3d;
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
}

.seo-hero h1 {
  margin: 18px 0 0;
  color: #101828;
  font-size: 48px;
  font-weight: 800;
  line-height: 1.14;
  letter-spacing: 0;
}

.seo-hero__summary {
  max-width: 640px;
  margin: 20px 0 0;
  color: #56657a;
  font-size: 16px;
  line-height: 1.85;
}

.seo-actions,
.seo-register__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 32px;
}

.seo-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-width: 132px;
  min-height: 42px;
  padding: 0 18px;
  border-radius: 2px;
  font-size: 14px;
  font-weight: 700;
  line-height: 1.3;
  text-decoration: none;
  transition:
    background-color $motion-fast ease,
    border-color $motion-fast ease,
    color $motion-fast ease,
    transform $motion-fast ease;
}

.seo-action:hover {
  transform: translateY(-1px);
}

.seo-action--primary {
  border: 1px solid #165dff;
  background: #165dff;
  color: #fff;
  box-shadow: 0 12px 24px rgba(22, 93, 255, 0.2);
}

.seo-action--primary:hover {
  border-color: #0e4fcc;
  background: #0e4fcc;
  color: #fff;
}

.seo-action--plain {
  border: 1px solid rgba(22, 93, 255, 0.18);
  background: rgba(255, 255, 255, 0.64);
  color: #25324a;
}

.seo-action--plain:hover {
  border-color: #165dff;
  color: #165dff;
}

.seo-feature-strip {
  position: relative;
  z-index: 2;
  margin-top: -1px;
  background: #fff;
  border-bottom: 1px solid #e5eaf3;
}

.seo-feature-strip__inner {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  min-height: 146px;
}

.seo-strip-card {
  position: relative;
  display: grid;
  grid-template-columns: 28px minmax(0, 1fr);
  gap: 16px;
  min-width: 0;
  padding: 34px 28px;
  border-left: 1px solid #edf2f7;
  background: #fff;
}

.seo-strip-card:first-child {
  border-left: 0;
}

.seo-strip-card__icon,
.seo-category-tabs__icon {
  display: inline-block;
  width: 22px;
  height: 22px;
  margin-top: 2px;
  border-radius: 50%;
  background:
    radial-gradient(circle at 70% 24%, #ffffff 0 12%, transparent 13%),
    linear-gradient(135deg, #80aaff 0%, #165dff 100%);
  box-shadow: 0 8px 18px rgba(22, 93, 255, 0.24);
}

.seo-strip-card h2 {
  margin: 0;
  color: #1d2738;
  font-size: 18px;
  line-height: 1.45;
  letter-spacing: 0;
}

.seo-strip-card p {
  display: -webkit-box;
  margin: 10px 0 0;
  overflow: hidden;
  color: #66758a;
  font-size: 13px;
  line-height: 1.75;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.seo-strip-card--cta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  background: linear-gradient(135deg, #3477ff 0%, #165dff 100%);
  color: #fff;
}

.seo-strip-card--cta .seo-strip-card__icon {
  display: none;
}

.seo-strip-card--cta h2,
.seo-strip-card--cta p {
  color: #fff;
}

.seo-strip-card--cta p {
  opacity: 0.88;
}

.seo-strip-card__link {
  flex: 0 0 auto;
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
}

.seo-section {
  scroll-margin-top: 84px;
  padding: 68px 0;
  background: #fff;
}

.seo-section--products,
.seo-section--process {
  background: #f5f7fa;
}

.seo-section__heading {
  max-width: 780px;
}

.seo-section__heading--center {
  margin: 0 auto;
  text-align: center;
}

.seo-section__heading h2 {
  margin: 0;
  color: #101828;
  font-size: 32px;
  line-height: 1.32;
  letter-spacing: 0;
}

.seo-section__heading p {
  margin: 14px 0 0;
  color: #66758a;
  font-size: 14px;
  line-height: 1.8;
}

.seo-section__heading a {
  display: inline-flex;
  margin-top: 10px;
  color: #165dff;
  font-size: 14px;
  font-weight: 700;
  text-decoration: none;
}

.seo-category-tabs {
  display: flex;
  justify-content: center;
  gap: 42px;
  margin-top: 34px;
}

.seo-category-tabs button {
  display: grid;
  justify-items: center;
  gap: 10px;
  min-width: 76px;
  min-height: 72px;
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: #4f5f75;
  font-size: 14px;
  cursor: pointer;
}

.seo-category-tabs button.is-active {
  border-bottom-color: #165dff;
  color: #165dff;
  font-weight: 700;
}

.seo-category-tabs__icon {
  width: 24px;
  height: 24px;
  margin: 0;
}

.seo-product-grid {
  display: grid;
  grid-template-columns: 1.05fr 1fr 1fr;
  gap: 28px;
  margin-top: 34px;
}

.seo-product-card {
  min-width: 0;
  min-height: 210px;
  padding: 26px 28px;
  border: 1px solid transparent;
  border-radius: 4px;
  background: transparent;
  transition:
    background-color $motion-fast ease,
    border-color $motion-fast ease,
    box-shadow $motion-fast ease,
    transform $motion-fast ease;
}

.seo-product-card:hover,
.seo-product-card--featured {
  border-color: #e2e8f3;
  background: #fff;
  box-shadow: 0 14px 30px rgba(20, 38, 74, 0.07);
  transform: translateY(-2px);
}

.seo-product-card__head {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.seo-product-card h3 {
  margin: 0;
  color: #162033;
  font-size: 22px;
  line-height: 1.4;
  letter-spacing: 0;
}

.seo-product-card__head span {
  display: inline-flex;
  align-items: center;
  min-height: 22px;
  padding: 0 7px;
  border: 1px solid #b9ceff;
  color: #165dff;
  font-size: 12px;
  line-height: 1;
}

.seo-product-card p {
  min-height: 58px;
  margin: 14px 0 0;
  color: #66758a;
  font-size: 14px;
  line-height: 1.75;
}

.seo-product-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 18px;
}

.seo-product-card__tags span {
  display: inline-flex;
  align-items: center;
  min-height: 26px;
  padding: 0 9px;
  border: 1px solid #d9e1ec;
  color: #516176;
  font-size: 12px;
  line-height: 1;
}

.seo-product-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 22px;
}

.seo-product-card__actions a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 38px;
  min-width: 96px;
  padding: 0 14px;
  border: 1px solid #ccd6e4;
  color: #334155;
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
}

.seo-product-card__actions a.is-primary {
  border-color: #165dff;
  background: #165dff;
  color: #fff;
}

.seo-section--solutions {
  background:
    linear-gradient(90deg, rgba(50, 78, 111, 0.9), rgba(212, 224, 236, 0.92)),
    #d4dee8;
}

.seo-section--solutions .seo-section__heading h2 {
  color: #fff;
}

.seo-section--solutions .seo-section__heading p {
  color: rgba(255, 255, 255, 0.78);
}

.seo-solution-panel {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  min-height: 360px;
  margin-top: 34px;
  background: rgba(255, 255, 255, 0.12);
}

.seo-solution-tabs {
  display: grid;
  align-content: start;
  padding: 18px 0;
  background: rgba(17, 31, 50, 0.28);
}

.seo-solution-tabs button {
  min-height: 56px;
  padding: 0 24px;
  border: 0;
  background: transparent;
  color: rgba(255, 255, 255, 0.76);
  font-size: 15px;
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}

.seo-solution-tabs button.is-active {
  background: #165dff;
  color: #fff;
}

.seo-solution-content {
  min-width: 0;
  padding: 42px 48px;
  background: rgba(255, 255, 255, 0.9);
}

.seo-eyebrow {
  margin: 0;
  color: #165dff;
  font-size: 13px;
  font-weight: 800;
}

.seo-solution-content h3 {
  margin: 12px 0 0;
  color: #111827;
  font-size: 28px;
  line-height: 1.35;
  letter-spacing: 0;
}

.seo-solution-content p:not(.seo-eyebrow) {
  margin: 14px 0 0;
  color: #526174;
  font-size: 14px;
  line-height: 1.86;
}

.seo-solution-content ul {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin: 24px 0 0;
  padding: 0;
  list-style: none;
}

.seo-solution-content li {
  display: flex;
  gap: 8px;
  min-width: 0;
  color: #334155;
  font-size: 13px;
  font-weight: 700;
  line-height: 1.55;
}

.seo-solution-content li .el-icon {
  flex: 0 0 auto;
  margin-top: 2px;
  color: #165dff;
}

.seo-solution-content__related {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 14px;
  margin-top: 28px;
  color: #526174;
  font-size: 13px;
}

.seo-solution-content__related a {
  color: #165dff;
  font-weight: 700;
  text-decoration: none;
}

.seo-process {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
  margin-top: 34px;
}

.seo-process__item,
.seo-faq {
  min-width: 0;
  border: 1px solid #e2e8f3;
  border-radius: 4px;
  background: #fff;
}

.seo-process__item {
  padding: 24px;
}

.seo-process__item span {
  color: #165dff;
  font-size: 24px;
  font-weight: 800;
}

.seo-process__item h3 {
  margin: 14px 0 0;
  color: #162033;
  font-size: 17px;
  line-height: 1.4;
  letter-spacing: 0;
}

.seo-process__item p {
  margin: 10px 0 0;
  color: #66758a;
  font-size: 13px;
  line-height: 1.78;
}

.seo-faq-list {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin-top: 32px;
}

.seo-faq {
  padding: 24px;
}

.seo-faq h3 {
  display: flex;
  gap: 8px;
  margin: 0;
  color: #162033;
  font-size: 16px;
  line-height: 1.55;
  letter-spacing: 0;
}

.seo-faq h3 .el-icon {
  flex: 0 0 auto;
  margin-top: 3px;
  color: #165dff;
}

.seo-faq p {
  margin: 12px 0 0;
  color: #5f6f83;
  font-size: 13px;
  line-height: 1.82;
}

.seo-register {
  padding: 44px 0;
  background: #165dff;
  color: #fff;
}

.seo-register__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 32px;
}

.seo-register p {
  margin: 0;
  color: rgba(255, 255, 255, 0.82);
  font-size: 13px;
  font-weight: 700;
}

.seo-register h2 {
  margin: 12px 0 0;
  font-size: 30px;
  line-height: 1.32;
  letter-spacing: 0;
}

.seo-register__description {
  display: block;
  margin-top: 10px;
  color: rgba(255, 255, 255, 0.86);
  font-size: 14px;
  line-height: 1.78;
}

.seo-register__actions {
  flex: 0 0 auto;
  margin-top: 0;
}

.seo-action--white {
  border: 1px solid #fff;
  background: #fff;
  color: #165dff;
}

.seo-action--outline {
  border: 1px solid rgba(255, 255, 255, 0.62);
  color: #fff;
}

.seo-action--outline:hover {
  border-color: #fff;
  color: #fff;
}

@media (max-width: 1080px) {
  .seo-hero__inner {
    grid-template-columns: 1fr;
    gap: 32px;
  }

  .seo-hero__nav {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    padding: 0;
  }

  .seo-hero__nav::after,
  .seo-hero__nav a.is-active::after {
    display: none;
  }

  .seo-hero__nav a {
    flex: 0 0 auto;
  }

  .seo-feature-strip__inner,
  .seo-process {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .seo-product-grid,
  .seo-solution-panel {
    grid-template-columns: 1fr;
  }

  .seo-solution-tabs {
    display: flex;
    overflow-x: auto;
    padding: 0;
  }

  .seo-solution-tabs button {
    flex: 0 0 180px;
    text-align: center;
  }
}

@media (max-width: 760px) {
  .seo-container {
    width: calc(100% - 24px);
  }

  .seo-hero,
  .seo-hero__inner {
    min-height: auto;
  }

  .seo-hero__inner {
    padding: 42px 0 54px;
  }

  .seo-hero h1 {
    font-size: 32px;
  }

  .seo-hero__summary,
  .seo-hero__kicker {
    font-size: 14px;
  }

  .seo-actions,
  .seo-register__actions {
    flex-direction: column;
    width: 100%;
  }

  .seo-action {
    width: 100%;
  }

  .seo-feature-strip__inner,
  .seo-process,
  .seo-faq-list {
    grid-template-columns: 1fr;
  }

  .seo-strip-card {
    border-left: 0;
    border-top: 1px solid #edf2f7;
    padding: 24px 18px;
  }

  .seo-strip-card:first-child {
    border-top: 0;
  }

  .seo-strip-card--cta {
    align-items: flex-start;
    flex-direction: column;
  }

  .seo-section {
    padding: 48px 0;
  }

  .seo-section__heading h2,
  .seo-register h2 {
    font-size: 24px;
  }

  .seo-category-tabs {
    justify-content: flex-start;
    gap: 18px;
    overflow-x: auto;
    padding-bottom: 2px;
  }

  .seo-category-tabs button {
    flex: 0 0 72px;
  }

  .seo-product-card {
    min-height: auto;
    padding: 22px 18px;
  }

  .seo-product-card p {
    min-height: auto;
  }

  .seo-solution-content {
    padding: 28px 20px;
  }

  .seo-solution-content h3 {
    font-size: 22px;
  }

  .seo-solution-content ul {
    grid-template-columns: 1fr;
  }

  .seo-register__inner {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
