<script setup lang="ts">
import { ArrowRightIcon } from "tdesign-icons-vue-next";

import { DEPLOY_HREFS, DEPLOY_ICONS } from "../data/site";
import { useSite } from "../i18n/context";
import { ICONS } from "../icons";

const { messages } = useSite();
const section = messages.deploy;
</script>

<template>
  <section id="deploy" class="section section-alt">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">{{ section.kicker }}</span>
        <h2>{{ section.title }}</h2>
        <p>{{ section.lead }}</p>
      </header>

      <ul class="paths">
        <li
          v-for="(path, index) in section.items"
          :key="path.title"
          class="path"
          data-reveal
          :style="{ '--d': `${index * 70}ms` }"
        >
          <div class="path-top">
            <span class="path-icon">
              <component
                :is="ICONS[DEPLOY_ICONS[index]]"
                size="18px"
                aria-hidden="true"
              />
            </span>
            <span class="path-tag">{{ path.tag }}</span>
          </div>
          <h3>{{ path.title }}</h3>
          <p>{{ path.desc }}</p>
          <a
            class="path-link"
            :href="DEPLOY_HREFS[index]"
            target="_blank"
            rel="noopener noreferrer"
          >
            {{ path.link }}
            <ArrowRightIcon size="14px" aria-hidden="true" />
          </a>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.paths {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.path {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 26px 24px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
  box-shadow: var(--shadow-sm);
  transition:
    transform 0.22s ease,
    box-shadow 0.22s ease;
}

.path:hover {
  transform: translateY(-4px);
  box-shadow: var(--shadow-md);
}

.path-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.path-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: var(--brand-soft);
  color: var(--brand);
}

.path-tag {
  padding: 3px 10px;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--text-2);
  font-size: 11.5px;
}

h3 {
  font-size: 17px;
  font-weight: 700;
}

p {
  flex: 1;
  color: var(--text-2);
  font-size: 13.5px;
  line-height: 1.7;
}

.path-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 4px;
  color: var(--brand);
  font-size: 13.5px;
  font-weight: 600;
}

.path-link:hover {
  text-decoration: underline;
  text-underline-offset: 3px;
}

@media (max-width: 900px) {
  .paths {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
