<template>
  <main class="auth-shell">
    <div class="auth-shell__scene" aria-hidden="true">
      <div class="auth-shell__mesh" />
      <div class="auth-shell__line auth-shell__line--one" />
      <div class="auth-shell__line auth-shell__line--two" />
      <div class="auth-shell__line auth-shell__line--three" />
      <div class="auth-shell__plane auth-shell__plane--hero" />
      <div class="auth-shell__plane auth-shell__plane--ridge" />
      <div class="auth-shell__plane auth-shell__plane--base" />
      <div class="auth-shell__beam" />
      <div class="auth-shell__cluster">
        <span class="auth-shell__cluster-base" />
        <span class="auth-shell__cluster-block auth-shell__cluster-block--one" />
        <span class="auth-shell__cluster-block auth-shell__cluster-block--two" />
        <span class="auth-shell__cluster-block auth-shell__cluster-block--three" />
        <span class="auth-shell__cluster-cube" />
      </div>
    </div>

    <div class="auth-shell__stage">
      <header class="auth-shell__header">
        <a class="auth-brand" :href="authBrandHref">
          <img
            v-if="showLogo"
            :src="authLogoSrc"
            :alt="siteBranding.siteName"
            class="auth-brand__logo"
            @error="handleLogoError"
          />
          <template v-else>
            <span class="auth-brand__mark">{{ siteBranding.brandInitials }}</span>
            <span class="auth-brand__name">{{ siteBranding.siteName }}</span>
          </template>
          <span class="auth-brand__divider" />
          <span class="auth-brand__context">用户控制台</span>
        </a>
        <span class="auth-shell__header-spacer" />
        <button class="auth-shell__back" type="button" @click="handleBack">
          <chevron-left-icon />
          <span>返回</span>
        </button>
      </header>

      <section class="auth-shell__content">
        <div class="auth-card-column">
          <div class="auth-card">
            <div class="auth-card__head">
              <h2 class="auth-card__title">{{ title }}</h2>
              <div v-if="navText || navLinkText" class="auth-card__nav">
                <span>{{ navText }}</span>
                <router-link v-if="navLinkText" :to="navTo">{{ navLinkText }}</router-link>
              </div>
            </div>

            <div class="auth-card__body">
              <slot />
            </div>
          </div>
        </div>

        <div class="auth-guide">
          <h1 class="auth-guide__title">{{ heroTitle }}</h1>
          <p class="auth-guide__description">{{ heroDescription }}</p>
          <router-link v-if="ctaText" class="auth-guide__cta" :to="ctaTo">{{ ctaText }}</router-link>
        </div>
      </section>

      <footer class="auth-shell__footer">Copyright © {{ currentYear }} {{ siteBranding.siteName }}</footer>
    </div>
  </main>
</template>
<script setup lang="ts">
import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { useRouter } from 'vue-router';

import { useSiteBrandingStore } from '@/app/stores/siteBranding';

withDefaults(
  defineProps<{
    title: string;
    navText?: string;
    navLinkText?: string;
    navTo?: RouteLocationRaw;
    ctaText?: string;
    ctaTo?: RouteLocationRaw;
    heroTitle?: string;
    heroDescription?: string;
  }>(),
  {
    navText: '',
    navLinkText: '',
    navTo: '/client/login',
    ctaText: '',
    ctaTo: '/client/login',
    heroTitle: '安全进入图拉云控制台',
    heroDescription: '管理云服务、账单、工单与账户资料，所有操作继续沿用原有用户端接口。',
  },
);

const siteBranding = useSiteBrandingStore();
const router = useRouter();
const currentYear = computed(() => new Date().getFullYear());
const logoLoadFailed = ref(false);
const authLogoSrc = computed(() => siteBranding.clientConsoleIcon || siteBranding.siteLogo);
const showLogo = computed(() => Boolean(authLogoSrc.value) && !logoLoadFailed.value);

function handleLogoError() {
  logoLoadFailed.value = true;
}

const authBrandHref = computed(() => siteBranding.frontendUrl || '/client/login');

function handleBack() {
  if (window.history.length > 1) {
    router.back();
  } else {
    router.push('/');
  }
}

watch(authLogoSrc, () => {
  logoLoadFailed.value = false;
});

onMounted(() => {
  void siteBranding.fetchSiteConfig();
});
</script>
<style scoped lang="less">
.auth-shell {
  --auth-bg-start: #f8fafc;
  --auth-bg-middle: #f8fafc;
  --auth-bg-end: #f8fafc;
  --auth-line-color: rgb(140 156 184 / 14%);
  --auth-grid-color: rgb(162 177 202 / 10%);
  --auth-panel-primary: rgb(255 255 255 / 84%);
  --auth-panel-secondary: rgb(255 255 255 / 84%);
  --auth-panel-edge: rgb(214 223 235 / 60%);
  --auth-glow-color: rgb(234 239 247 / 74%);
  --auth-card-bg: rgb(255 255 255 / 94%);
  --auth-card-border: rgb(211 220 234 / 82%);
  --auth-card-shadow: 0 18px 42px rgb(114 131 161 / 12%);
  --auth-heading-color: #1d2738;
  --auth-title-color: #2563eb;
  --auth-copy-color: #617086;
  --auth-footer-color: #94a0b2;
  --auth-brand-divider: rgb(110 123 149 / 24%);
  --auth-guide-cta-bg: rgb(255 255 255 / 72%);
  --auth-guide-cta-border: rgb(22 93 255 / 26%);
  --auth-guide-cta-shadow: 0 10px 24px rgb(22 93 255 / 6%);
  --auth-field-bg: #fff;
  --auth-field-border: rgb(208 218 233 / 98%);
  --auth-field-hover-border: rgb(183 198 224 / 98%);
  --auth-field-focus-shadow: rgb(22 93 255 / 12%);
  --auth-field-icon: #90a0b7;

  min-height: 100vh;
  position: relative;
  overflow: hidden;
  isolation: isolate;
  background: var(--auth-bg-start);
  color: var(--td-text-color-primary);
}

/* 注意：不能用 :deep(:root[...]) —— scoped 编译会在最前面安插 [data-v-xxx]，
   变成「带 scope 祖先内的 html」，永远不匹配；直接写 :root 前缀即可，
   编译只给最后的 .auth-shell 加 scope 属性 */
:root[theme-mode='dark'] .auth-shell {
  --auth-bg-start: #0b1220;
  --auth-bg-middle: #0b1220;
  --auth-bg-end: #0b1220;
  --auth-line-color: rgb(118 138 170 / 12%);
  --auth-grid-color: rgb(132 150 182 / 6%);
  --auth-panel-primary: rgb(255 255 255 / 5%);
  --auth-panel-secondary: rgb(255 255 255 / 5%);
  --auth-panel-edge: rgb(142 160 189 / 18%);
  --auth-glow-color: rgb(67 118 255 / 14%);
  --auth-card-bg: rgb(14 21 36 / 84%);
  --auth-card-border: rgb(90 108 138 / 28%);
  --auth-card-shadow: 0 22px 52px rgb(0 0 0 / 36%);
  --auth-heading-color: #f6f8fc;
  --auth-title-color: #8fb0ff;
  --auth-copy-color: rgb(226 233 244 / 78%);
  --auth-footer-color: rgb(190 202 220 / 60%);
  --auth-brand-divider: rgb(226 233 244 / 14%);
  --auth-guide-cta-bg: rgb(19 30 50 / 72%);
  --auth-guide-cta-border: rgb(132 172 255 / 32%);
  --auth-guide-cta-shadow: 0 14px 32px rgb(0 0 0 / 24%);
  --auth-field-bg: rgb(12 19 33 / 90%);
  --auth-field-border: rgb(86 105 138 / 36%);
  --auth-field-hover-border: rgb(128 157 214 / 38%);
  --auth-field-focus-shadow: rgb(99 141 255 / 18%);
  --auth-field-icon: rgb(201 213 232 / 58%);
}

.auth-shell__scene {
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
}

.auth-shell__scene::before,
.auth-shell__scene::after {
  content: '';
  position: absolute;
  pointer-events: none;
}

.auth-shell__scene::before {
  top: -14%;
  left: 26%;
  width: min(26rem, 30vw);
  height: min(26rem, 30vw);
  background: var(--auth-glow-color);
  opacity: 0.34;
  filter: blur(6.5rem);
  border-radius: 50%;
}

.auth-shell__scene::after {
  right: 2%;
  bottom: -14%;
  width: min(18rem, 24vw);
  height: min(18rem, 24vw);
  background: rgb(22 93 255 / 8%);
  opacity: 0.26;
  filter: blur(5rem);
  border-radius: 50%;
}

.auth-shell__mesh {
  position: absolute;
  top: -4%;
  right: -2%;
  width: min(26vw, 22rem);
  aspect-ratio: 1;
  border: 1px solid var(--auth-grid-color);
  opacity: 0.46;
  transform: skewY(-12deg);
}

.auth-shell__line {
  position: absolute;
  height: 1px;
  background: var(--auth-line-color);
  transform-origin: center;
}

.auth-shell__line--one {
  top: 13%;
  left: -10%;
  width: 76%;
  transform: rotate(32deg);
}

.auth-shell__line--two {
  top: 15%;
  right: -4%;
  width: 74%;
  transform: rotate(-28deg);
}

.auth-shell__line--three {
  top: 54%;
  right: 18%;
  width: 42%;
  transform: rotate(-26deg);
}

.auth-shell__plane {
  position: absolute;
  border: 1px solid var(--auth-panel-edge);
  background: var(--auth-panel-primary);
}

.auth-shell__plane--hero {
  top: 0;
  right: -6%;
  width: min(46vw, 47.5rem);
  height: 38%;
  clip-path: polygon(25% 0, 100% 0, 100% 100%, 6% 100%);
}

.auth-shell__plane--ridge {
  right: 10%;
  bottom: 15%;
  width: min(34vw, 30rem);
  height: 28%;
  clip-path: polygon(18% 0, 100% 0, 82% 100%, 0 100%);
  opacity: 0.5;
}

.auth-shell__plane--base {
  bottom: 4%;
  right: 14%;
  width: min(21vw, 18rem);
  height: 0.75rem;
  border-radius: 999px;
  background: rgb(255 255 255 / 72%);
  box-shadow: 0 0 1rem rgb(255 255 255 / 32%);
  transform: rotate(-24deg);
}

.auth-shell__beam {
  position: absolute;
  top: 48%;
  right: 18%;
  width: min(16vw, 13rem);
  height: 0.875rem;
  border-radius: 999px;
  background: rgb(255 255 255 / 72%);
  box-shadow: 0 0 1.25rem rgb(255 255 255 / 28%);
  transform: rotate(-28deg);
}

.auth-shell__cluster {
  position: absolute;
  right: 6%;
  bottom: 6%;
  width: min(22vw, 17.5rem);
  height: min(20vw, 14rem);
}

.auth-shell__cluster-base {
  position: absolute;
  left: 12%;
  right: 0;
  bottom: 0;
  height: 1.5rem;
  border-radius: 1rem;
  background: rgb(239 243 248 / 72%);
  box-shadow: 0 1rem 1.5rem rgb(123 138 165 / 10%);
}

.auth-shell__cluster-block {
  position: absolute;
  bottom: 1.125rem;
  border-radius: 0.875rem 0.875rem 0.625rem 0.625rem;
  border: 1px solid rgb(224 231 241 / 72%);
  background: rgb(248 250 253 / 90%);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 76%),
    0 0.875rem 1.5rem rgb(122 138 167 / 10%);
}

.auth-shell__cluster-block--one {
  left: 18%;
  width: 3.25rem;
  height: 7.25rem;
  transform: skewY(-18deg);
}

.auth-shell__cluster-block--two {
  left: 45%;
  width: 4rem;
  height: 9.25rem;
  border-color: rgb(118 148 255 / 42%);
  background: rgb(229 236 252 / 86%);
}

.auth-shell__cluster-block--three {
  left: 74%;
  width: 2.75rem;
  height: 5.625rem;
  transform: skewY(-18deg);
}

.auth-shell__cluster-cube {
  position: absolute;
  left: 60%;
  bottom: 5.75rem;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 0.5rem;
  background: rgb(90 133 255 / 72%);
  box-shadow: 0 0.5rem 0.875rem rgb(65 100 214 / 18%);
}

.auth-shell__stage {
  display: flex;
  flex-direction: column;
  position: relative;
  z-index: 1;
  width: min(85rem, calc(100% - 4.5rem));
  min-height: 100vh;
  margin: 0 auto;
  padding: 1.75rem 0 1.5rem;
}

.auth-shell__header {
  display: flex;
  align-items: center;
  min-height: 2.75rem;
}

.auth-shell__header-spacer {
  flex: 1;
}

.auth-shell__back {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.375rem 0.75rem;
  border: none;
  background: transparent;
  color: var(--td-text-color-secondary);
  font-size: 0.875rem;
  cursor: pointer;
  transition: color 0.2s;
  border-radius: 0.375rem;

  &:hover {
    color: var(--td-text-color-primary);
    background: var(--td-bg-color-secondarycontainer);
  }
}

.auth-brand {
  display: inline-flex;
  align-items: center;
  gap: 0.875rem;
  min-width: 0;
  color: var(--auth-heading-color);
  text-decoration: none;
}

.auth-brand__logo {
  display: block;
  width: auto;
  max-width: 15rem;
  height: 2.5rem;
  object-fit: contain;
}

.auth-brand__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.75rem;
  background: var(--td-brand-color);
  box-shadow: 0 0.875rem 1.75rem rgb(22 93 255 / 20%);
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.auth-brand__name {
  font-size: 1.0625rem;
  font-weight: 600;
  letter-spacing: -0.01em;
}

.auth-brand__divider {
  flex-shrink: 0;
  width: 1px;
  height: 1.25rem;
  background: var(--auth-brand-divider);
}

.auth-brand__context {
  color: var(--auth-copy-color);
  font-size: 0.9375rem;
  font-weight: 500;
  letter-spacing: 0.02em;
}

.auth-shell__content {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(24rem, 26.25rem) minmax(20rem, 35rem);
  align-items: center;
  gap: clamp(3rem, 7vw, 7.5rem);
  padding: clamp(3rem, 8vh, 5.5rem) 0 2.25rem;
}

.auth-card-column,
.auth-guide {
  position: relative;
  z-index: 1;
}

.auth-card {
  position: relative;
  width: 100%;
  padding: 2rem 2rem 1.875rem;
  border: 1px solid var(--auth-card-border);
  border-radius: 1rem;
  background: var(--auth-card-bg);
  box-shadow: var(--auth-card-shadow);
  backdrop-filter: blur(10px);
}

.auth-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.625rem;
}

.auth-card__title {
  margin: 0;
  color: var(--auth-heading-color);
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.02em;
}

.auth-card__nav {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.25rem;
  max-width: 12rem;
  color: var(--auth-footer-color);
  font-size: 0.75rem;
  line-height: 1.6;
  text-align: right;
}

.auth-card__nav a {
  color: var(--td-brand-color);
  font-weight: 600;
  text-decoration: none;
}

.auth-card__nav a:hover {
  text-decoration: underline;
}

.auth-card__body {
  width: 100%;
}

.auth-guide {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  padding-right: clamp(0rem, 4vw, 3rem);
}

.auth-guide__title {
  max-width: 10em;
  margin: 0;
  color: var(--auth-title-color);
  font-size: clamp(2.3rem, 4vw, 3.1rem);
  font-weight: 680;
  line-height: 1.14;
  letter-spacing: -0.03em;
  white-space: pre-line;
  text-wrap: balance;
}

.auth-guide__description {
  max-width: 28rem;
  margin: 1rem 0 0;
  color: var(--auth-copy-color);
  font-size: 0.9688rem;
  line-height: 1.86;
  text-wrap: pretty;
}

.auth-guide__cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 8.75rem;
  height: 2.75rem;
  margin-top: 2rem;
  padding: 0 1.375rem;
  border: 1px solid var(--auth-guide-cta-border);
  border-radius: 0.5rem;
  background: var(--auth-guide-cta-bg);
  box-shadow: var(--auth-guide-cta-shadow);
  color: var(--td-brand-color);
  font-size: 0.875rem;
  font-weight: 600;
  text-decoration: none;
  transition:
    transform @anim-duration-base @anim-time-fn-easing,
    box-shadow @anim-duration-base @anim-time-fn-easing,
    border-color @anim-duration-base @anim-time-fn-easing;
}

.auth-guide__cta:hover {
  transform: translateY(-1px);
  box-shadow: 0 0.875rem 1.5rem rgb(22 93 255 / 10%);
  border-color: rgb(22 93 255 / 42%);
}

.auth-shell__footer {
  width: 100%;
  text-align: center;
  color: var(--auth-footer-color);
  font-size: 0.75rem;
  line-height: 1.6;
}

@media (max-width: @screen-lg-max) {
  .auth-shell__stage {
    width: min(78rem, calc(100% - 3.5rem));
  }

  .auth-shell__content {
    grid-template-columns: minmax(23rem, 25rem) minmax(18rem, 1fr);
    gap: clamp(2.5rem, 6vw, 5rem);
  }

  .auth-shell__line--three {
    right: 10%;
    width: 48%;
  }
}

@media (max-width: @screen-md-max) {
  .auth-shell__mesh,
  .auth-shell__line--three,
  .auth-shell__beam {
    display: none;
  }

  .auth-shell__plane--hero {
    width: min(56vw, 34rem);
    height: 28%;
  }

  .auth-shell__cluster {
    right: 4%;
    width: 12rem;
    height: 10rem;
  }
}

@media (max-width: @screen-sm-max) {
  .auth-shell__stage {
    width: calc(100% - 2rem);
    padding: 1.25rem 0 1.5rem;
  }

  .auth-shell__content {
    grid-template-columns: 1fr;
    gap: 1.5rem;
    padding: 2rem 0 1.5rem;
  }

  .auth-shell__mesh,
  .auth-shell__line,
  .auth-shell__cluster,
  .auth-shell__plane--ridge,
  .auth-shell__beam {
    display: none;
  }

  .auth-shell__plane--hero {
    top: -2%;
    right: -18%;
    width: 22rem;
    height: 16rem;
  }

  .auth-shell__plane--base {
    bottom: 12%;
    right: -8%;
    width: 10rem;
  }

  .auth-brand {
    gap: 0.75rem;
  }

  .auth-brand__divider,
  .auth-brand__context {
    display: none;
  }

  .auth-brand__logo {
    max-width: 12.5rem;
    height: 2rem;
  }

  .auth-brand__mark {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.6875rem;
    font-size: 0.9375rem;
  }

  .auth-brand__name {
    font-size: 0.875rem;
  }

  .auth-card {
    padding: 1.625rem 1.25rem 1.375rem;
    border-radius: 1.125rem;
  }

  .auth-card__head {
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
  }

  .auth-card__title {
    font-size: 1.5rem;
  }

  .auth-card__nav {
    max-width: none;
    justify-content: flex-start;
    text-align: left;
  }

  .auth-guide {
    display: none;
  }

  .auth-shell__footer {
    text-align: left;
    font-size: 0.6875rem;
  }
}

@media (width <= 420px) {
  .auth-shell__stage {
    width: calc(100% - 1.5rem);
  }

  .auth-brand__divider {
    display: none;
  }

  .auth-card {
    padding-inline: 1.125rem;
  }

  .auth-card__title {
    font-size: 1.375rem;
  }

  .auth-guide__title {
    font-size: 1.75rem;
  }
}
</style>
