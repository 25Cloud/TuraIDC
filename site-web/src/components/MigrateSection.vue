<script setup lang="ts">
import { ArrowRightIcon } from "tdesign-icons-vue-next";

import { MIGRATE } from "../data/content";
import { ICONS } from "../icons";
</script>

<template>
  <section id="migrate" class="section">
    <div class="container">
      <div class="band" data-reveal>
        <div class="band-head">
          <span class="band-icon">
            <component :is="ICONS.swap" size="20px" aria-hidden="true" />
          </span>
          <h2>{{ MIGRATE.title }}</h2>
          <p>{{ MIGRATE.desc }}</p>
          <a class="btn btn-primary" :href="MIGRATE.href">
            {{ MIGRATE.linkText }}
            <ArrowRightIcon size="16px" aria-hidden="true" />
          </a>
        </div>

        <ul class="points">
          <li v-for="(point, index) in MIGRATE.points" :key="point">
            <span class="num">0{{ index + 1 }}</span>
            <span>{{ point }}</span>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>

<style scoped>
.band {
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
  gap: 48px;
  align-items: center;
  padding: 44px 44px;
  border: 1px solid #d6e3ff;
  border-radius: var(--radius-lg);
  background:
    radial-gradient(
      120% 140% at 100% 0%,
      rgba(22, 93, 255, 0.1) 0%,
      rgba(22, 93, 255, 0) 58%
    ),
    var(--card);
}

.band-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  margin-bottom: 16px;
  border-radius: 12px;
  background: var(--brand-soft);
  color: var(--brand);
}

h2 {
  font-size: clamp(24px, 2.6vw, 32px);
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.25;
}

.band-head p {
  max-width: 30em;
  margin-top: 12px;
  color: var(--text-2);
  font-size: 14.5px;
  line-height: 1.75;
}

.band-head .btn {
  margin-top: 24px;
}

.points {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.points li {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  background: rgba(255, 255, 255, 0.7);
  color: var(--text);
  font-size: 13.5px;
  line-height: 1.65;
}

.num {
  color: var(--brand);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.1em;
}

@media (max-width: 900px) {
  .band {
    grid-template-columns: minmax(0, 1fr);
    gap: 28px;
    padding: 28px 22px;
  }
}
</style>
