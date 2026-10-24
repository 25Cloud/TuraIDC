<script setup lang="ts">
import { ArrowRightIcon } from "tdesign-icons-vue-next";

import { DEPLOY_PATHS } from "../data/content";
import { ICONS } from "../icons";
</script>

<template>
  <section id="deploy" class="section section-alt">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">部署</span>
        <h2>三条路，挑你手上那套机器</h2>
        <p>
          同一个仓库、同一份配置，Docker、宝塔、裸机都能装。部署文档里连伪静态和
          定时任务都写好了，照着抄就行。
        </p>
      </header>

      <ul class="paths">
        <li
          v-for="(path, index) in DEPLOY_PATHS"
          :key="path.title"
          class="path"
          data-reveal
          :style="{ '--d': `${index * 70}ms` }"
        >
          <div class="path-top">
            <span class="path-icon">
              <component
                :is="ICONS[path.icon]"
                size="18px"
                aria-hidden="true"
              />
            </span>
            <span class="path-tag">{{ path.tag }}</span>
          </div>
          <h3>{{ path.title }}</h3>
          <p>{{ path.desc }}</p>
          <a class="path-link" :href="path.href">
            {{ path.linkText }}
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
  color: var(--brand-hover);
  text-decoration: underline;
  text-underline-offset: 3px;
}

@media (max-width: 900px) {
  .paths {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
