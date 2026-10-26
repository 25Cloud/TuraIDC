<script setup lang="ts">
import { computed } from "vue";
import { useData } from "vitepress";
import { siteChromeFor } from "../data/siteChrome";

// 首页文案按语言分文件存放（siteChrome.*.json），这里只负责按当前语言渲染。
const { lang, localeIndex, site } = useData();
const copy = computed(() => siteChromeFor(lang.value));
const audiences = computed(() => copy.value.audiences);

// 首页由本组件整体接管布局，默认导航栏（含 VitePress 语言菜单）不渲染，
// 所以语言入口要在这里补一份，否则首页本身没法切换语言。
const LOCALE_SHORT_LABEL: Record<string, string> = {
  root: "简体中文",
  en: "English",
  "zh-tw": "繁體（台）",
  "zh-hk": "繁體（港）",
};

const otherLocales = computed(() =>
  Object.entries(site.value.locales)
    .filter(([key]) => key !== localeIndex.value)
    .map(([key, locale]) => ({
      key,
      label: LOCALE_SHORT_LABEL[key] ?? locale.label,
      href: locale.link || (key === "root" ? "/" : `/${key}/`),
    })),
);
</script>

<template>
  <div class="portal">
    <header class="portal-header">
      <a
        class="portal-brand"
        :href="copy.prefix + '/'"
        :aria-label="copy.brandAria"
      >
        <img src="/branding/favicon.png" alt="TuraIDC" />
        <span>{{ copy.brand }}</span>
      </a>
      <nav class="portal-nav" :aria-label="copy.navAria">
        <a
          v-for="item in copy.nav"
          :key="item.href"
          :href="item.href"
          :target="item.href.startsWith('http') ? '_blank' : undefined"
          :rel="item.href.startsWith('http') ? 'noreferrer' : undefined"
          >{{ item.text }}</a
        >
      </nav>
    </header>

    <main>
      <section class="portal-hero">
        <div class="portal-hero-copy">
          <h1>
            <span>{{ copy.hero.title }}</span
            ><br />{{ copy.hero.titleTail }}
          </h1>
          <p>{{ copy.hero.description }}</p>
          <div class="portal-actions">
            <a class="portal-primary-action" :href="copy.hero.primary.href">{{
              copy.hero.primary.text
            }}</a>
            <a
              class="portal-secondary-action"
              :href="copy.hero.secondary.href"
              >{{ copy.hero.secondary.text }}</a
            >
          </div>
        </div>
        <div class="portal-hero-visual">
          <img
            class="portal-hero-logo"
            src="/branding/turaidc-logo.png"
            alt="TuraIDC"
          />
        </div>
      </section>

      <section
        v-for="audience in audiences"
        :key="audience.id"
        class="portal-section"
        :aria-labelledby="`portal-${audience.id}-title`"
      >
        <div class="portal-section-heading">
          <p class="portal-section-label">{{ audience.label }}</p>
          <h2 :id="`portal-${audience.id}-title`">{{ audience.title }}</h2>
          <p class="portal-section-description">
            {{ audience.description }}
          </p>
        </div>
        <div class="portal-guide-grid">
          <section
            v-for="(group, index) in audience.groups"
            :key="group.title"
            class="portal-guide"
            :style="{ animationDelay: `${index * 70}ms` }"
          >
            <h3>{{ group.title }}</h3>
            <p>{{ group.description }}</p>
            <ul>
              <li v-for="[text, link] in group.links" :key="link">
                <a :href="link">{{ text }}<span aria-hidden="true">→</span></a>
              </li>
            </ul>
          </section>
        </div>
      </section>
    </main>

    <footer class="portal-footer">
      <span>{{ copy.footer.copyright }}</span>
      <a
        href="https://github.com/25Cloud/TuraIDC"
        target="_blank"
        rel="noreferrer"
        >{{ copy.footer.repo }}</a
      >
      <nav class="portal-footer-lang" aria-label="Language">
        <a
          v-for="locale in otherLocales"
          :key="locale.key"
          :href="locale.href"
          >{{ locale.label }}</a
        >
      </nav>
    </footer>
  </div>
</template>

<style scoped>
/* 首页配色令牌：跟随 VitePress 深浅色切换（html.dark） */
.portal {
  --portal-bg: #ffffff;
  --portal-bg-alt: #f5f7fb;
  --portal-text-1: #1f2937;
  --portal-text-2: #5b6b82;
  --portal-border: #e5eaf3;
  --portal-brand: #165dff;
  --portal-brand-strong: #0e4fcc;
  --portal-glow-1: rgba(22, 93, 255, 0.11);
  --portal-glow-2: rgba(14, 79, 204, 0.09);
  --portal-primary-action-text: #ffffff;
  --portal-card-shadow: rgba(31, 41, 55, 0.08);
  --portal-ease: cubic-bezier(0.22, 0.61, 0.36, 1);

  min-height: 100vh;
  color: var(--portal-text-1);
  background: var(--portal-bg);
  transition:
    background-color 0.3s ease,
    color 0.3s ease;
}

.dark .portal {
  --portal-bg: #1b1b1f;
  --portal-bg-alt: #24242b;
  --portal-text-1: #e6e8ee;
  --portal-text-2: #9aa4b8;
  --portal-border: #34343f;
  --portal-brand: #6ba3ff;
  --portal-brand-strong: #3d82f6;
  /* 暗色下光斑强度更低，避免深底上过曝刺眼 */
  --portal-glow-1: rgba(96, 150, 235, 0.13);
  --portal-glow-2: rgba(70, 116, 196, 0.1);
  --portal-primary-action-text: #0b1220;
  --portal-card-shadow: rgba(0, 0, 0, 0.4);
}

/* 入场淡入上移与 logo 浮动关键帧 */
@keyframes portal-rise {
  from {
    opacity: 0;
    transform: translateY(16px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes portal-float {
  0%,
  100% {
    transform: translateY(0);
  }

  50% {
    transform: translateY(-8px);
  }
}

.portal-header,
.portal-hero,
.portal-section,
.portal-footer {
  width: min(1160px, calc(100% - 48px));
  margin: 0 auto;
}

.portal-header {
  height: 72px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.portal-brand,
.portal-nav,
.portal-actions {
  display: flex;
  align-items: center;
}

.portal-brand {
  gap: 10px;
  color: var(--portal-text-1);
  font-size: 15px;
  font-weight: 700;
  animation: portal-rise 0.5s var(--portal-ease) both;
}

.portal-brand img {
  width: 38px;
  height: 38px;
  object-fit: contain;
}

.portal-nav {
  gap: 28px;
}

.portal-nav a,
.portal-footer a {
  color: var(--portal-text-2);
  font-size: 14px;
  text-decoration: none;
  transition: color 0.25s ease;
}

.portal-nav a:hover,
.portal-footer a:hover {
  color: var(--portal-brand);
}

.portal-hero {
  position: relative;
  min-height: 500px;
  padding: 72px 74px;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 56px;
  overflow: hidden;
  border-bottom: 1px solid var(--portal-border);
}

/* 主题色流光：保持静态、柔和，四周平滑淡出，不做动画 */
.portal-hero::before,
.portal-hero::after {
  position: absolute;
  content: "";
  pointer-events: none;
  filter: blur(96px);
}

.portal-hero::before {
  top: 116px;
  left: 6%;
  width: 60%;
  height: 142px;
  background: var(--portal-glow-1);
  transform: rotate(-8deg) skewX(-18deg);
}

.portal-hero::after {
  right: 4%;
  bottom: 108px;
  width: 58%;
  height: 146px;
  background: var(--portal-glow-2);
  transform: rotate(-11deg) skewX(16deg);
}

.portal-hero > * {
  position: relative;
  z-index: 1;
}

.portal-hero-copy {
  max-width: 640px;
  animation: portal-rise 0.6s var(--portal-ease) both;
}

.portal-hero h1,
.portal-section h2 {
  margin: 0;
  color: var(--portal-text-1);
  letter-spacing: 0;
}

.portal-hero h1 {
  font-size: 48px;
  line-height: 1.2;
}

.portal-hero h1 span {
  color: var(--portal-brand);
  font-size: 60px;
}

.portal-hero-copy > p {
  max-width: 560px;
  margin: 24px 0 0;
  color: var(--portal-text-2);
  font-size: 17px;
  line-height: 1.8;
}

.portal-hero-visual {
  position: relative;
  width: min(300px, 30vw);
  min-width: 210px;
  min-height: 238px;
  display: grid;
  place-items: center;
  animation: portal-rise 0.6s var(--portal-ease) 0.1s both;
}

.portal-hero-logo {
  width: min(250px, 28vw);
  height: auto;
  object-fit: contain;
  animation: portal-float 7s ease-in-out 0.9s infinite;
}

.portal-actions {
  gap: 12px;
  margin-top: 30px;
}

.portal-primary-action,
.portal-secondary-action {
  display: inline-flex;
  min-height: 40px;
  padding: 0 18px;
  box-sizing: border-box;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--portal-brand);
  border-radius: 6px;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  transition:
    background-color 0.25s ease,
    color 0.25s ease,
    box-shadow 0.25s ease,
    transform 0.2s ease;
}

.portal-primary-action {
  color: var(--portal-primary-action-text);
  background: var(--portal-brand);
}

.portal-primary-action:hover {
  background: var(--portal-brand-strong);
  box-shadow: 0 6px 16px var(--portal-glow-1);
  transform: translateY(-1px);
}

.portal-primary-action:active {
  transform: translateY(0);
}

.portal-secondary-action {
  color: var(--portal-brand);
  background: var(--portal-bg);
}

.portal-secondary-action:hover {
  background: var(--portal-bg-alt);
  transform: translateY(-1px);
}

.portal-secondary-action:active {
  transform: translateY(0);
}

.portal-section {
  padding: 80px 0 88px;
}

/* 使用者与开发者两个分区之间加分隔线，避免连成一片看不出分界 */
.portal-section + .portal-section {
  border-top: 1px solid var(--portal-border);
}

.portal-section-heading {
  margin-bottom: 30px;
}

.portal-section-label {
  margin: 0 0 8px;
  color: var(--portal-brand);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.08em;
}

.portal-section h2 {
  font-size: 28px;
  line-height: 1.35;
}

.portal-section-description {
  max-width: 720px;
  margin: 12px 0 0;
  color: var(--portal-text-2);
  font-size: 15px;
  line-height: 1.75;
}

.portal-guide-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  animation: portal-rise 0.5s var(--portal-ease) 0.15s both;
}

.portal-guide {
  min-height: 228px;
  padding: 24px;
  box-sizing: border-box;
  border: 1px solid var(--portal-border);
  border-radius: 8px;
  background: var(--portal-bg);
  transition:
    border-color 0.25s ease,
    box-shadow 0.25s ease,
    transform 0.25s ease;
}

.portal-guide:hover {
  border-color: var(--portal-brand);
  box-shadow: 0 12px 32px var(--portal-card-shadow);
  transform: translateY(-4px);
}

.portal-guide h3 {
  margin: 0;
  font-size: 18px;
  line-height: 1.4;
}

.portal-guide > p {
  min-height: 48px;
  margin: 10px 0 16px;
  color: var(--portal-text-2);
  font-size: 14px;
  line-height: 1.7;
}

.portal-guide ul {
  margin: 0;
  padding: 0;
  list-style: none;
}

.portal-guide li + li {
  margin-top: 8px;
}

.portal-guide li a {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  color: var(--portal-brand);
  font-size: 14px;
  text-decoration: none;
}

.portal-guide li a span {
  flex: 0 0 auto;
  transition: transform 0.25s ease;
}

.portal-guide li a:hover span {
  transform: translateX(4px);
}

.portal-footer {
  min-height: 80px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--portal-text-2);
  font-size: 13px;
}

/* 首页的语言入口：页脚空间足够，窄屏自动换行也不会挤压首屏 */
.portal-footer-lang {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
}

/* 系统减弱动效时关闭入场、浮动与过渡 */
@media (prefers-reduced-motion: reduce) {
  .portal-brand,
  .portal-hero-copy,
  .portal-hero-visual,
  .portal-hero-logo,
  .portal-guide-grid {
    animation: none;
  }

  .portal,
  .portal *,
  .portal *::before,
  .portal *::after {
    transition: none !important;
  }
}

@media (max-width: 800px) {
  .portal-header,
  .portal-hero,
  .portal-section,
  .portal-footer {
    width: min(100% - 24px, 1160px);
  }

  .portal-header {
    height: 64px;
  }

  .portal-nav {
    display: none;
  }

  .portal-hero {
    min-height: auto;
    padding: 56px 0;
    align-items: flex-start;
    gap: 30px;
  }

  .portal-hero h1 {
    font-size: 34px;
  }

  .portal-hero h1 span {
    font-size: 42px;
  }

  .portal-hero-copy > p {
    font-size: 16px;
  }

  .portal-hero-logo {
    width: 86px;
  }

  .portal-hero-visual {
    width: 96px;
    min-width: 96px;
    min-height: 146px;
  }

  .portal-section {
    padding: 56px 0;
  }

  .portal-guide-grid {
    grid-template-columns: 1fr;
  }

  .portal-guide {
    min-height: 0;
  }

  .portal-guide > p {
    min-height: 0;
  }

  .portal-footer {
    min-height: 72px;
  }
}

@media (max-width: 480px) {
  .portal-hero {
    gap: 14px;
  }

  .portal-hero h1 {
    font-size: 30px;
  }

  .portal-hero h1 span {
    font-size: 36px;
  }

  .portal-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .portal-primary-action,
  .portal-secondary-action {
    width: 100%;
  }
}
</style>
