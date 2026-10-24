<script setup lang="ts">
import { FEATURES } from "../data/content";
import { ICONS } from "../icons";
</script>

<template>
  <section id="features" class="section">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">能力矩阵</span>
        <h2>一套系统，覆盖 IDC 的全部日常</h2>
        <p>
          从第一次上架商品，到月底对完账、给客户开票，中间的每一步都在同一个后台里，
          不靠 Excel 拼接，也不靠人手对单。
        </p>
      </header>

      <ul class="grid">
        <li
          v-for="(item, index) in FEATURES"
          :key="item.title"
          class="card"
          data-reveal
          :style="{ '--d': `${index * 45}ms` }"
        >
          <span class="icon">
            <component :is="ICONS[item.icon]" size="20px" aria-hidden="true" />
          </span>
          <h3>{{ item.title }}</h3>
          <p>{{ item.desc }}</p>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 18px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 24px 22px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
  box-shadow: var(--shadow-sm);
  transition:
    transform 0.22s ease,
    box-shadow 0.22s ease,
    border-color 0.22s ease;
}

.card:hover {
  transform: translateY(-4px);
  border-color: #cfe0ff;
  box-shadow: var(--shadow-md);
}

.icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 11px;
  background: var(--brand-soft);
  color: var(--brand);
}

h3 {
  font-size: 16.5px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

p {
  color: var(--text-2);
  font-size: 13.5px;
  line-height: 1.7;
}

@media (max-width: 1080px) {
  .grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 560px) {
  .grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
