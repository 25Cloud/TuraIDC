<script setup lang="ts">
import { ICONS } from "../icons";
import { LINKS } from "../data/site";
import github from "../data/github.json";
import { useSite } from "../i18n/context";

/**
 * 项目动态：版本号与仓库数据。
 *
 * 数据来自 `src/data/github.json`，由 `scripts/fetch-github.mjs` 在构建前抓取，
 * 抓不到就沿用仓库里的快照。页面本身仍然零客户端 JS，不在访客浏览器里请求 GitHub。
 */
const { messages } = useSite();
const live = messages.live;

const [latest, ...history] = github.releases;

/** GitHub 给的是 ISO 时间，页面上只要日期；四语言统一 YYYY-MM-DD，不引入本地化歧义 */
const day = (iso: string) => iso.slice(0, 10);

const BADGES = [
  { key: "stars", icon: ICONS.star, value: String(github.repo.stars) },
  { key: "forks", icon: ICONS.fork, value: String(github.repo.forks) },
  { key: "issues", icon: ICONS.issue, value: String(github.repo.openIssues) },
  { key: "license", icon: ICONS.license, value: github.repo.license },
  { key: "pushed", icon: ICONS.time, value: day(github.repo.pushedAt) },
] as const;
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

        <ul class="badges">
          <li v-for="badge in BADGES" :key="badge.key" class="badge">
            <strong class="value">{{ badge.value }}</strong>
            <span class="label">
              <component :is="badge.icon" size="14px" aria-hidden="true" />
              {{ live.stats[badge.key] }}
            </span>
          </li>
        </ul>
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

.badges {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 12px;
  margin: 0;
  padding: 22px 0 0;
  border-top: 1px solid var(--line);
  list-style: none;
}

.badge {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.badge .value {
  color: var(--text);
  font-size: 19px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
}

.badge .label {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--text-3);
  font-size: 12px;
  line-height: 1.4;
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

/* 手机窄屏：5 项排成 3 + 2 两行，比 2 列的三行更紧凑 */
@media (max-width: 720px) {
  .badges {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px 12px;
  }

  .badge .value {
    font-size: 16px;
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
