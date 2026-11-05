<script setup lang="ts">
import { LINKS, LIVE_BADGES } from "../data/site";
import github from "../data/github.json";
import { useSite } from "../i18n/context";

/**
 * 项目动态：最新版本那一行 + 实时徽章墙 + 历史版本。
 *
 * 版本号与发布日期来自 `src/data/github.json`（构建前由 `scripts/fetch-github.mjs`
 * 抓一次，抓不到就沿用仓库里的快照）；Star / Fork / Issue / 最近提交这些会变的数字
 * 改成 shields.io 的徽章图片——访客打开页面即由对方现算，本站依然零客户端 JS。
 */
const { messages } = useSite();
const live = messages.live;

const [latest, ...history] = github.releases;

/** GitHub 给的是 ISO 时间，页面上只要日期；四语言统一 YYYY-MM-DD，不引入本地化歧义 */
const day = (iso: string) => iso.slice(0, 10);

/** 徽章分两行：仓库状态一行、技术栈一行，避免末尾挂一枚孤零零的徽章 */
const BADGE_GROUPS = ["repo", "stack"] as const;
const badgesOf = (group: (typeof BADGE_GROUPS)[number]) =>
  LIVE_BADGES.filter((badge) => badge.group === group);
</script>

<template>
  <section id="live" class="section live">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">{{ live.kicker }}</span>
        <h2>{{ live.title }}</h2>
        <p>{{ live.lead }}</p>
      </header>

      <div class="panel" data-reveal>
        <div class="release">
          <span class="chip">{{ live.latest }}</span>
          <a
            class="version"
            :href="latest.url"
            target="_blank"
            rel="noopener noreferrer"
            >{{ latest.tag }}</a
          >
          <span class="date"
            >{{ live.releasedOn }} {{ day(latest.publishedAt) }}</span
          >
          <a
            class="btn btn-outline btn-sm"
            :href="LINKS.releases"
            target="_blank"
            rel="noopener noreferrer"
            >{{ live.allReleases }}</a
          >
        </div>

        <div class="badge-block">
          <span class="badge-title">{{ live.badgesTitle }}</span>
          <ul v-for="group in BADGE_GROUPS" :key="group" class="badge-wall">
            <li v-for="badge in badgesOf(group)" :key="badge.key">
              <a
                :href="badge.href"
                target="_blank"
                rel="noopener noreferrer"
                :aria-label="badge.label"
              >
                <img
                  :src="badge.src"
                  :alt="badge.label"
                  :width="badge.width"
                  :height="badge.height"
                  loading="lazy"
                  decoding="async"
                  referrerpolicy="no-referrer"
                />
              </a>
            </li>
          </ul>
          <p class="badge-note">{{ live.badgesNote }}</p>
        </div>
      </div>

      <ol class="history" data-reveal>
        <li v-for="release in history" :key="release.tag">
          <a :href="release.url" target="_blank" rel="noopener noreferrer">
            <span class="tag">{{ release.tag }}</span>
            <span class="day">{{ day(release.publishedAt) }}</span>
          </a>
        </li>
      </ol>

      <p class="source">
        {{ live.source }}
        <a :href="github.repo.url" target="_blank" rel="noopener noreferrer"
          >25Cloud/TuraIDC</a
        >
      </p>
    </div>
  </section>
</template>

<style scoped>
.panel {
  display: grid;
  gap: 24px;
  padding: 28px 30px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
  box-shadow: var(--shadow-sm);
}

.release {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 16px;
}

.chip {
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--brand-soft);
  color: var(--brand-ink);
  font-size: 12px;
  font-weight: 600;
}

.version {
  color: var(--text);
  font-size: clamp(26px, 3vw, 34px);
  font-weight: 800;
  letter-spacing: -0.02em;
  font-variant-numeric: tabular-nums;
}

.version:hover {
  color: var(--brand);
}

.date {
  color: var(--text-2);
  font-size: 14px;
  font-variant-numeric: tabular-nums;
}

.release .btn {
  margin-left: auto;
}

.badge-block {
  padding-top: 20px;
  border-top: 1px solid var(--line);
}

.badge-title {
  display: block;
  margin-bottom: 12px;
  color: var(--text-3);
  font-size: 11.5px;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

/*
 * 徽章墙：flex 换行而不是固定列数——徽章宽度由 shields 的数值长度决定，
 * 写死列数迟早会在某一屏挤爆。窄屏自然落到每行两三个，容器不会被撑破。
 */
.badge-wall {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}

/* 第二行（技术栈）与第一行之间留出半格的呼吸 */
.badge-wall + .badge-wall {
  margin-top: 10px;
}

.badge-wall li {
  display: flex;
  min-width: 0;
  max-width: 100%;
}

.badge-wall a {
  display: inline-flex;
  max-width: 100%;
  border-radius: 4px;
}

.badge-wall a:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: 3px;
}

/* 高度锁 20px、宽度自适应：数字变长时徽章等比变宽，不会被属性里的占位宽度拉扯变形 */
.badge-wall img {
  height: 20px;
  width: auto;
  max-width: 100%;
}

.badge-note {
  margin-top: 14px;
  color: var(--text-3);
  font-size: 12.5px;
  line-height: 1.7;
}

.history {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 10px;
  margin-top: 18px;
}

.history a {
  display: inline-flex;
  align-items: baseline;
  gap: 8px;
  padding: 6px 12px;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-2);
  font-size: 13px;
  transition:
    border-color 0.18s ease,
    color 0.18s ease;
}

.history a:hover {
  border-color: var(--brand);
  color: var(--brand);
}

.history .tag {
  color: var(--text);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.history .day {
  color: var(--text-3);
  font-variant-numeric: tabular-nums;
}

.source {
  margin-top: 16px;
  color: var(--text-3);
  font-size: 12.5px;
}

.source a {
  color: var(--text-2);
  text-decoration: underline;
  text-underline-offset: 3px;
}

.source a:hover {
  color: var(--brand);
}

@media (max-width: 900px) {
  .release .btn {
    margin-left: 0;
  }
}

@media (max-width: 640px) {
  .panel {
    padding: 22px 20px;
  }

  .history {
    gap: 8px;
  }
}
</style>
