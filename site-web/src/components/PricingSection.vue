<script setup lang="ts">
import { ArrowRightIcon } from "tdesign-icons-vue-next";

import { PRICING } from "../data/content";
import { ICONS } from "../icons";
</script>

<template>
  <section id="pricing" class="section section-alt">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">定价</span>
        <h2>开源免费，闭源商用再谈钱</h2>
        <p>
          两条路的功能完全一致：不想开源就买商业授权，愿意开源就一分不花。
          没有按实例数、按订单量收费的隐藏条款。
        </p>
      </header>

      <div class="plans">
        <article
          v-for="(plan, index) in PRICING"
          :key="plan.name"
          class="plan"
          :class="{ featured: plan.tone === 'featured' }"
          data-reveal
          :style="{ '--d': `${index * 90}ms` }"
        >
          <header class="plan-head">
            <h3>{{ plan.name }}</h3>
            <span class="plan-license">{{ plan.license }}</span>
          </header>

          <p class="price">
            <span class="currency">¥</span>
            <strong>{{ plan.price }}</strong>
            <span class="unit">{{ plan.unit }}</span>
          </p>

          <ul class="features">
            <li v-for="feature in plan.features" :key="feature">
              <component :is="ICONS.check" size="15px" aria-hidden="true" />
              <span>{{ feature }}</span>
            </li>
          </ul>

          <a
            class="btn"
            :class="plan.tone === 'featured' ? 'btn-primary' : 'btn-outline'"
            :href="plan.cta.href"
          >
            {{ plan.cta.text }}
            <ArrowRightIcon size="15px" aria-hidden="true" />
          </a>
        </article>
      </div>

      <p class="note" data-reveal>
        定价与许可口径来自仓库 README 与
        LICENSE-COMMERCIAL.md，最终以仓库内许可文件为准。
      </p>
    </div>
  </section>
</template>

<style scoped>
.plans {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}

.plan {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 30px 28px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--card);
  box-shadow: var(--shadow-sm);
}

.plan.featured {
  border-color: #b9d2ff;
  box-shadow: 0 24px 60px -40px rgba(22, 93, 255, 0.9);
}

.plan-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.plan-head h3 {
  font-size: 18px;
  font-weight: 700;
}

.plan-license {
  padding: 3px 10px;
  border-radius: 999px;
  background: var(--bg);
  color: var(--text-2);
  font-size: 11.5px;
}

.price {
  display: flex;
  align-items: baseline;
  gap: 4px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--line);
}

.currency {
  color: var(--text-2);
  font-size: 18px;
  font-weight: 600;
}

.price strong {
  font-size: 40px;
  font-weight: 800;
  letter-spacing: -0.03em;
  line-height: 1;
}

.unit {
  color: var(--text-3);
  font-size: 13px;
}

.features {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.features li {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  color: var(--text-2);
  font-size: 13.5px;
  line-height: 1.6;
}

.features svg {
  flex: none;
  margin-top: 2px;
  color: var(--success);
}

.plan .btn {
  align-self: flex-start;
}

.note {
  margin-top: 20px;
  color: var(--text-3);
  font-size: 12.5px;
}

@media (max-width: 820px) {
  .plans {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
