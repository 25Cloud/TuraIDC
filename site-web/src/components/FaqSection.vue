<script setup lang="ts">
import { ChevronDownIcon } from "tdesign-icons-vue-next";

import { FAQ_ITEMS } from "../data/content";
</script>

<template>
  <section id="faq" class="section">
    <div class="container faq-grid">
      <header class="section-head faq-head" data-reveal>
        <span class="eyebrow">常见问题</span>
        <h2>动手之前，先问清楚</h2>
        <p>
          没找到答案就去翻文档，基本都在里面；文档里也没有的，直接开 issue。
        </p>
      </header>

      <div class="faq-list">
        <details
          v-for="(item, index) in FAQ_ITEMS"
          :key="item.q"
          class="faq-item"
          data-reveal
          :style="{ '--d': `${index * 45}ms` }"
          :open="index === 0"
        >
          <summary>
            <span>{{ item.q }}</span>
            <ChevronDownIcon class="chev" size="18px" aria-hidden="true" />
          </summary>
          <p>{{ item.a }}</p>
        </details>
      </div>
    </div>
  </section>
</template>

<style scoped>
.faq-grid {
  display: grid;
  grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
  gap: 56px;
  align-items: start;
}

.faq-head {
  position: sticky;
  top: calc(var(--header-h) + 24px);
  margin-bottom: 0;
}

.faq-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.faq-item {
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
  box-shadow: var(--shadow-sm);
  transition: border-color 0.2s ease;
}

.faq-item[open] {
  border-color: #cfe0ff;
}

.faq-item summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 20px;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  list-style: none;
}

.faq-item summary::-webkit-details-marker {
  display: none;
}

.faq-item summary:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: 2px;
  border-radius: var(--radius);
}

.chev {
  flex: none;
  color: var(--text-3);
  transition: transform 0.22s ease;
}

.faq-item[open] .chev {
  transform: rotate(180deg);
  color: var(--brand);
}

.faq-item p {
  padding: 0 20px 20px;
  color: var(--text-2);
  font-size: 14px;
  line-height: 1.75;
}

@media (max-width: 900px) {
  .faq-grid {
    grid-template-columns: minmax(0, 1fr);
    gap: 28px;
  }

  .faq-head {
    position: static;
  }
}
</style>
