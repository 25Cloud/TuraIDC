<script setup lang="ts">
import { SECURITY_HAVE, SECURITY_NONE } from "../data/content";
import { ICONS } from "../icons";
</script>

<template>
  <section id="security" class="section">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">安全与资金</span>
        <h2>先说我们没有哪些坑</h2>
        <p>
          同类商业系统被骂得最多的几个问题，这里逐条对着代码说清楚。
          每条都能在仓库里找到对应实现，不用信口头承诺。
        </p>
      </header>

      <div class="compare">
        <div class="panel panel-none" data-reveal>
          <div class="panel-head">
            <span class="panel-badge panel-badge-none">我们没有</span>
            <span class="panel-count">{{ SECURITY_NONE.length }} 条常见坑</span>
          </div>
          <ul>
            <li v-for="item in SECURITY_NONE" :key="item.title">
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
            <span class="panel-badge panel-badge-have">我们有</span>
            <span class="panel-count">{{ SECURITY_HAVE.length }} 条兜底</span>
          </div>
          <ul>
            <li v-for="item in SECURITY_HAVE" :key="item.title">
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
  align-items: center;
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
  color: #b42318;
}

.panel-badge-have {
  background: var(--success-soft);
  color: #0b7a45;
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
