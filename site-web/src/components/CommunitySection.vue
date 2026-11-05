<script setup lang="ts">
import { ArrowRightIcon } from "tdesign-icons-vue-next";

import github from "../data/github.json";
import {
  COMMUNITY,
  CONTRIBUTORS,
  LANGUAGE_COLORS,
  LANGUAGE_FALLBACK_COLOR,
  STAR_HISTORY,
} from "../data/site";
import { useSite } from "../i18n/context";

/**
 * 社区：项目热度、贡献者、语言构成、开发交流群。
 *
 * 「实时」的四块里，三块是服务端渲染的图片地址（star-history、contrib.rocks，
 * 加上项目动态区的 shields 徽章）：图片由对方按访问现出图，本站不加载任何第三方 JS。
 * 只有语言构成是构建期快照——百分比要按字节自己算，shields 给不了。
 */
const { messages } = useSite();
const community = messages.community;

const languages = github.languages ?? [];

/** 语言色卡取不到就用中性灰，不让新语言把占比条染成透明 */
const sliceColor = (name: string) =>
  name === "Other"
    ? LANGUAGE_FALLBACK_COLOR
    : (LANGUAGE_COLORS[name] ?? LANGUAGE_FALLBACK_COLOR);

/** 合并出来的那一段在每种语言里都叫「Other」，显示时按当前语言翻译 */
const sliceName = (name: string) => (name === "Other" ? community.other : name);

const percentText = (percent: number) => `${percent}%`;
</script>

<template>
  <section id="community" class="section section-alt community">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">{{ community.kicker }}</span>
        <h2>{{ community.title }}</h2>
        <p>{{ community.lead }}</p>
      </header>

      <div class="grid">
        <article class="card heat" data-reveal>
          <h3>{{ community.heatTitle }}</h3>
          <a
            class="chart"
            :href="STAR_HISTORY.page"
            target="_blank"
            rel="noopener noreferrer"
          >
            <picture>
              <source
                media="(prefers-color-scheme: dark)"
                :srcset="STAR_HISTORY.chartDark"
                :width="STAR_HISTORY.width"
                :height="STAR_HISTORY.height"
              />
              <img
                :src="STAR_HISTORY.chart"
                :alt="`25Cloud/TuraIDC · ${community.heatTitle}`"
                :width="STAR_HISTORY.width"
                :height="STAR_HISTORY.height"
                loading="lazy"
                decoding="async"
                referrerpolicy="no-referrer"
              />
            </picture>
          </a>
          <p class="caption">{{ community.heatCaption }}</p>
        </article>

        <article class="card contributors" data-reveal>
          <h3>{{ community.contributorsTitle }}</h3>
          <a
            class="wall"
            :href="CONTRIBUTORS.page"
            target="_blank"
            rel="noopener noreferrer"
          >
            <img
              :src="CONTRIBUTORS.image"
              :alt="`25Cloud/TuraIDC · ${community.contributorsTitle}`"
              :width="CONTRIBUTORS.width"
              :height="CONTRIBUTORS.height"
              loading="lazy"
              decoding="async"
              referrerpolicy="no-referrer"
            />
          </a>
          <p class="caption">{{ community.contributorsCaption }}</p>
        </article>

        <article class="card languages" data-reveal>
          <h3>{{ community.languagesTitle }}</h3>
          <div class="bar" aria-hidden="true">
            <span
              v-for="item in languages"
              :key="item.name"
              class="slice"
              :style="{
                width: percentText(item.percent),
                background: sliceColor(item.name),
              }"
            ></span>
          </div>
          <ul class="lang-list">
            <li v-for="item in languages" :key="item.name">
              <span
                class="dot"
                :style="{ background: sliceColor(item.name) }"
                aria-hidden="true"
              ></span>
              <span class="lang-name">{{ sliceName(item.name) }}</span>
              <span class="lang-percent">{{ percentText(item.percent) }}</span>
            </li>
          </ul>
          <p class="caption">{{ community.languagesCaption }}</p>
        </article>

        <article class="card group" data-reveal>
          <h3>{{ community.groupTitle }}</h3>
          <p class="group-lead">{{ community.groupLead }}</p>
          <div class="group-body">
            <div class="group-copy">
              <p class="group-number">
                <span class="group-label">{{ community.groupNumber }}</span>
                <strong>{{ COMMUNITY.qqGroup }}</strong>
              </p>
              <a
                class="btn btn-primary btn-sm"
                :href="COMMUNITY.qqJoin"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ community.groupJoin }}
                <ArrowRightIcon size="16px" aria-hidden="true" />
              </a>
            </div>
            <figure class="qr">
              <img
                :src="COMMUNITY.qqQrcode"
                :alt="community.groupScan"
                :width="COMMUNITY.qqQrcodeWidth"
                :height="COMMUNITY.qqQrcodeHeight"
                loading="lazy"
                decoding="async"
                referrerpolicy="no-referrer"
              />
              <figcaption>{{ community.groupScan }}</figcaption>
            </figure>
          </div>
        </article>
      </div>
    </div>
  </section>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  align-items: start;
}

/* 热度图先占满一整行：它是这一块的主图，和另外三张竖排卡片同级会被压扁 */
.heat {
  grid-column: 1 / -1;
}

.card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
  padding: 22px 24px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
  box-shadow: var(--shadow-sm);
}

h3 {
  font-size: 16.5px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.caption {
  color: var(--text-3);
  font-size: 12.5px;
  line-height: 1.7;
}

/* 项目热度：star-history 出的 SVG，固定宽高比；限宽避免占满后整块过高 */
.chart {
  display: block;
  max-width: 100%;
  width: 960px;
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.chart img {
  width: 100%;
  height: auto;
  background: var(--bg-2);
}

.chart:hover {
  box-shadow: var(--shadow-md);
}

/* 贡献者：contrib.rocks 按贡献者数量横向排开，最多铺满卡片宽度 */
.wall {
  display: block;
  max-width: 100%;
  padding: 4px 0;
}

.wall img {
  width: 100%;
  max-width: 100%;
  height: auto;
}

/* 语言占比：一条横向堆叠条 + Top 5 列表 */
.bar {
  display: flex;
  gap: 2px;
  width: 100%;
  height: 10px;
  border-radius: 999px;
  overflow: hidden;
  background: var(--line);
}

.slice {
  flex: 0 1 auto;
  min-width: 2px;
}

.lang-list {
  display: grid;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.lang-list li {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 13px;
}

.dot {
  flex: none;
  align-self: center;
  width: 9px;
  height: 9px;
  border-radius: 50%;
}

.lang-name {
  color: var(--text);
  font-weight: 600;
}

.lang-percent {
  margin-left: auto;
  color: var(--text-2);
  font-variant-numeric: tabular-nums;
}

/* 开发交流：群号是可选中文本，不为「复制」加一行 JS */
.group-lead {
  color: var(--text-2);
  font-size: 13.5px;
  line-height: 1.7;
}

.group-body {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 18px;
  margin-top: auto;
}

.group-copy {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.group-number {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.group-label {
  color: var(--text-3);
  font-size: 12px;
}

.group-number strong {
  color: var(--text);
  font-size: 22px;
  font-weight: 800;
  letter-spacing: 0.02em;
  font-variant-numeric: tabular-nums;
  user-select: all;
}

.group-copy .btn {
  align-self: flex-start;
}

.qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  margin: 0;
  margin-left: auto;
}

.qr img {
  width: 132px;
  height: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: #fff;
}

.qr figcaption {
  max-width: 132px;
  color: var(--text-3);
  font-size: 11.5px;
  line-height: 1.5;
  text-align: center;
}

@media (max-width: 1023px) {
  .grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .heat {
    grid-column: auto;
  }
}

@media (max-width: 640px) {
  .card {
    padding: 20px;
  }

  .qr {
    margin-left: 0;
  }
}

@media (max-width: 420px) {
  .qr img {
    width: 112px;
  }

  .qr figcaption {
    max-width: 112px;
  }
}
</style>
