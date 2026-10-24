<script setup lang="ts">
import { ArrowRightIcon, LogoGithubIcon } from "tdesign-icons-vue-next";

import { LINKS } from "../data/site";
import { useSite } from "../i18n/context";
import ConsolePreview from "./ConsolePreview.vue";

const { messages } = useSite();
const hero = messages.hero;

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
        </div>

        <a class="ghost-link anim" :href="LINKS.migrate" style="--d: 270ms">
          {{ messages.actions.migrate }} →
        </a>

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

      <ConsolePreview class="hero-preview" />
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

.ghost-link {
  display: inline-block;
  margin-top: 16px;
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
    max-width: 560px;
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
