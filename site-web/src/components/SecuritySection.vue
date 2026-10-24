<script setup lang="ts">
import { useSite } from "../i18n/context";
import { ICONS } from "../icons";

const { messages } = useSite();
const section = messages.security;
</script>

<template>
  <section id="security" class="section">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">{{ section.kicker }}</span>
        <h2>{{ section.title }}</h2>
        <p>{{ section.lead }}</p>
      </header>

      <div class="compare">
        <div class="panel panel-none" data-reveal>
          <div class="panel-head">
            <span class="panel-badge panel-badge-none">
              {{ section.noneTitle }}
            </span>
            <span class="panel-count">
              {{ section.none.length }} {{ section.noneCount }}
            </span>
          </div>
          <ul>
            <li v-for="item in section.none" :key="item.title">
              <span class="mark mark-none">
                <component :is="ICONS.close" size="15px" aria-hidden="true" />
              </span>
              <div>
                <h3>{{ item.title }}</h3>
                <p>{{ item.desc }}</p>
              </div>
            </li>
          </ul>
        </div>

        <div class="panel panel-have" data-reveal style="--d: 120ms">
          <div class="panel-head">
            <span class="panel-badge panel-badge-have">
              {{ section.haveTitle }}
            </span>
            <span class="panel-count">
              {{ section.have.length }} {{ section.haveCount }}
            </span>
          </div>
          <ul>
            <li v-for="item in section.have" :key="item.title">
              <span class="mark mark-have">
                <component :is="ICONS.check" size="15px" aria-hidden="true" />
              </span>
              <div>
                <h3>{{ item.title }}</h3>
                <p>{{ item.desc }}</p>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.compare {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
  align-items: start;
}

.panel {
  padding: 26px 24px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
  box-shadow: var(--shadow-sm);
}

.panel-none {
  border-top: 3px solid var(--danger);
}

.panel-have {
  border-top: 3px solid var(--success);
}

.panel-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 18px;
}

.panel-badge {
  padding: 5px 12px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 700;
}

.panel-badge-none {
  background: var(--danger-soft);
  color: var(--danger-ink);
}

.panel-badge-have {
  background: var(--success-soft);
  color: var(--ok-ink);
}

.panel-count {
  color: var(--text-3);
  font-size: 12px;
}

ul {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

li {
  display: flex;
  gap: 12px;
}

.mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  flex: none;
  border-radius: 50%;
}

.mark-none {
  background: var(--danger-soft);
  color: var(--danger);
}

.mark-have {
  background: var(--success-soft);
  color: var(--success);
}

h3 {
  font-size: 14.5px;
  font-weight: 700;
  line-height: 1.5;
}

p {
  margin-top: 4px;
  color: var(--text-2);
  font-size: 13px;
  line-height: 1.65;
}

@media (max-width: 900px) {
  .compare {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
