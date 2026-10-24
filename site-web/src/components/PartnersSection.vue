<script setup lang="ts">
import { PARTNER_LOGOS } from "../data/site";
import { useSite } from "../i18n/context";

const { messages } = useSite();
const section = messages.partners;
</script>

<template>
  <section class="partners" aria-labelledby="partners-title">
    <div class="container">
      <div class="partners-head" data-reveal>
        <h2 id="partners-title" class="partners-title">
          <span class="eyebrow">{{ section.kicker }}</span>
          {{ section.title }}
        </h2>
        <p class="partners-note">{{ section.note }}</p>
      </div>

      <ul class="wall">
        <li
          v-for="(item, index) in section.items"
          :key="item.name"
          data-reveal
          :style="{ '--d': `${index * 70}ms` }"
        >
          <a
            class="tile"
            :href="item.href"
            target="_blank"
            rel="noopener noreferrer"
          >
            <img
              :src="PARTNER_LOGOS[index].src"
              :alt="item.name"
              :width="PARTNER_LOGOS[index].width"
              :height="PARTNER_LOGOS[index].height"
              loading="lazy"
              decoding="async"
            />
            <span class="tile-name">{{ item.name }}</span>
          </a>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.partners {
  padding: 64px 0 0;
}

.partners-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 28px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--border);
}

.partners-title {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 12px;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.partners-title .eyebrow {
  margin: 0;
}

.partners-note {
  color: var(--text-3);
  font-size: 12px;
  white-space: nowrap;
}

.wall {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  height: 128px;
  padding: 18px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--tile);
  transition:
    transform 0.24s cubic-bezier(0.2, 0.7, 0.3, 1),
    box-shadow 0.24s ease,
    border-color 0.24s ease;
}

.tile img {
  max-height: 42px;
  width: auto;
  height: auto;
  opacity: 0.86;
  transition:
    opacity 0.24s ease,
    transform 0.24s ease;
}

.tile-name {
  color: #5b6b82;
  font-size: 12px;
  letter-spacing: 0.02em;
}

.tile:hover {
  transform: translateY(-3px);
  border-color: var(--brand-border);
  box-shadow: var(--shadow-md);
}

.tile:hover img {
  opacity: 1;
  transform: scale(1.04);
}

@media (max-width: 900px) {
  .wall {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .partners-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
}

@media (max-width: 480px) {
  .tile {
    height: 108px;
    padding: 14px;
  }
}
</style>
