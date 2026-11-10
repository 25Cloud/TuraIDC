<script setup lang="ts">
import { ArrowRightIcon, LogoGithubIcon } from "tdesign-icons-vue-next";

import { DEMO_ENTRIES, DEMO_SITE_URL, LINKS } from "../data/site";
import { useSite } from "../i18n/context";
import SystemShot from "./SystemShot.vue";

const { messages } = useSite();
const hero = messages.hero;

/** 演示站三端入口：路径来自 data/site.ts，文案走 i18n 的 actions.demoUser/Console/Admin */
const DEMO_LABEL_KEYS = { user: "demoUser", console: "demoConsole", admin: "demoAdmin" } as const;
const demoHref = (path: string) => `${DEMO_SITE_URL.replace(/\/+$/, "")}${path}`;
const demoLabel = (key: keyof typeof DEMO_LABEL_KEYS) => messages.actions[DEMO_LABEL_KEYS[key]];

const TITLE_PARTS = [
  { text: hero.titleLead, accent: false },
  { text: " ", accent: false },
  { text: hero.titleAccent, accent: true },
  { text: ` ${hero.titleTail}`, accent: false },
];
</script>

<template>
  <section id="top" class="hero">
    <div class="hero-glow" aria-hidden="true"></div>
    <div class="container hero-grid">
      <div class="hero-copy">
        <span class="kicker anim">{{ hero.kicker }}</span>

        <h1 class="anim" style="--d: 60ms">
          <span
            v-for="(part, index) in TITLE_PARTS"
            :key="index"
            :class="{ accent: part.accent }"
            >{{ part.text }}</span
          >
        </h1>

        <p class="lead anim" style="--d: 120ms">{{ hero.lead }}</p>
        <p class="note anim" style="--d: 170ms">{{ hero.note }}</p>

        <div class="cta anim" style="--d: 230ms">
          <a class="btn btn-primary" :href="LINKS.docs">
            {{ messages.actions.docs }}
            <ArrowRightIcon size="16px" aria-hidden="true" />
          </a>
          <a
            class="btn btn-outline"
            :href="LINKS.repo"
            target="_blank"
            rel="noopener noreferrer"
          >
            <LogoGithubIcon size="16px" aria-hidden="true" />
            {{ messages.actions.github }}
          </a>
          <a
            class="btn btn-outline"
            :href="LINKS.demoSite"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ messages.actions.demoSite }}
          </a>
        </div>

        <div class="hero-links anim" style="--d: 270ms">
          <a
            class="ghost-link"
            :href="LINKS.demo"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ messages.actions.demo }} →
          </a>
          <a class="ghost-link" :href="LINKS.migrate">
            {{ messages.actions.migrate }} →
          </a>
        </div>

        <!-- 演示站：三端各给一条直链。访客最常问的就是「管理端在哪」，这里一次说清。 -->
        <div
          class="demo-entries anim"
          style="
            --d: 300ms;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            font-size: 13px;
          "
        >
          <span style="opacity: 0.72">{{ messages.actions.demoSite }}：</span>
          <a
            v-for="entry in DEMO_ENTRIES"
            :key="entry.key"
            :href="demoHref(entry.path)"
            target="_blank"
            rel="noopener noreferrer"
            style="
              padding: 4px 10px;
              border-radius: 999px;
              border: 1px solid rgba(148, 163, 184, 0.4);
              text-decoration: none;
              color: inherit;
            "
            >{{ demoLabel(entry.key) }}</a
          >
        </div>

        <dl class="stats anim" style="--d: 320ms">
          <div v-for="stat in hero.stats" :key="stat.label" class="stat">
            <dt>
              <strong>{{ stat.value }}</strong>
              <span>{{ stat.unit }}</span>
            </dt>
            <dd>{{ stat.label }}</dd>
          </div>
        </dl>
      </div>

      <SystemShot class="hero-preview" />
    </div>
  </section>
</template>

<style scoped>
.hero {
  position: relative;
  padding: 76px 0 72px;
  overflow: hidden;
  background: linear-gradient(
    180deg,
    var(--hero-top) 0%,
    var(--bg) 78%,
    var(--bg) 100%
  );
}

.hero-glow {
  position: absolute;
  top: -180px;
  right: -140px;
  width: 680px;
  height: 680px;
  border-radius: 50%;
  background: radial-gradient(
    circle,
    var(--brand-glow) 0%,
    rgba(22, 93, 255, 0) 68%
  );
  pointer-events: none;
  animation: drift 16s ease-in-out infinite alternate;
}

@keyframes drift {
  from {
    transform: translate3d(0, 0, 0) scale(1);
  }

  to {
    transform: translate3d(-40px, 30px, 0) scale(1.08);
  }
}

.hero-grid {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr);
  gap: 56px;
  align-items: center;
}

.kicker {
  display: inline-block;
  color: var(--text-3);
  font-size: 12.5px;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

/* 首屏用 CSS 关键帧而不是滚动观察：无论脚本是否就绪，首屏都会显示到位 */
.anim {
  animation: rise 0.7s cubic-bezier(0.2, 0.7, 0.3, 1) both;
  animation-delay: var(--d, 0ms);
}

@keyframes rise {
  from {
    opacity: 0;
    transform: translateY(16px);
  }

  to {
    opacity: 1;
    transform: none;
  }
}

h1 {
  margin-top: 20px;
  font-size: clamp(36px, 4.4vw, 60px);
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1.1;
  /* keep-all：中文只在空格处断行，避免「业务与财务系 / 统」这种拆法 */
  word-break: keep-all;
  overflow-wrap: break-word;
  text-wrap: balance;
}

.accent {
  position: relative;
  color: var(--brand);
}

.accent::after {
  content: "";
  position: absolute;
  right: 0;
  bottom: 0.01em;
  left: 0;
  height: 0.075em;
  border-radius: 999px;
  background: var(--brand-glow);
}

.lead {
  max-width: 34em;
  margin-top: 24px;
  color: var(--text);
  font-size: clamp(16px, 1.35vw, 19px);
  line-height: 1.65;
}

.note {
  max-width: 36em;
  margin-top: 14px;
  color: var(--text-2);
  font-size: 14.5px;
  line-height: 1.7;
}

.cta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 30px;
}

.hero-links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
  margin-top: 16px;
}

.ghost-link {
  display: inline-block;
  color: var(--brand);
  font-size: 14px;
  font-weight: 600;
}

.ghost-link:hover {
  text-decoration: underline;
  text-underline-offset: 3px;
}

.stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 18px;
  margin-top: 44px;
  padding-top: 26px;
  border-top: 1px solid var(--border);
}

.stat dt {
  display: flex;
  align-items: baseline;
  gap: 3px;
}

.stat strong {
  font-size: 28px;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.stat span {
  color: var(--text-3);
  font-size: 13px;
}

.stat dd {
  margin-top: 6px;
  color: var(--text-2);
  font-size: 12.5px;
  line-height: 1.55;
}

.hero-preview {
  min-width: 0;
}

@media (max-width: 1080px) {
  .hero-grid {
    grid-template-columns: minmax(0, 1fr);
    gap: 44px;
  }

  .hero-preview {
    max-width: 680px;
    margin: 0 auto;
  }
}

@media (max-width: 680px) {
  .hero {
    padding: 44px 0 48px;
  }

  .stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px 16px;
    margin-top: 32px;
  }

  .stat strong {
    font-size: 24px;
  }
}
</style>
