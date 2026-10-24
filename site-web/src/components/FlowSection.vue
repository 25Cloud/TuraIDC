<script setup lang="ts">
import { FLOW_STEPS } from "../data/content";
import { ICONS } from "../icons";
</script>

<template>
  <section id="flow" class="section section-alt">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">业务闭环</span>
        <h2>从下单到对账，一条线走到底</h2>
        <p>
          六个环节共享同一套订单、账户和账务流水，任何一步失败都能回到上一步重来，
          不会出现「系统里已开通、账上没收到钱」这类对不上的状态。
        </p>
      </header>

      <ol class="flow">
        <li
          v-for="(step, index) in FLOW_STEPS"
          :key="step.title"
          class="step"
          data-reveal
          :style="{ '--d': `${index * 60}ms` }"
        >
          <span class="step-icon">
            <component :is="ICONS[step.icon]" size="18px" aria-hidden="true" />
          </span>
          <span class="step-index">0{{ index + 1 }}</span>
          <h3>{{ step.title }}</h3>
          <p>{{ step.desc }}</p>
        </li>
      </ol>
    </div>
  </section>
</template>

<style scoped>
.flow {
  position: relative;
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}

.flow::before {
  content: "";
  position: absolute;
  top: 21px;
  right: 8%;
  left: 8%;
  height: 1px;
  background: linear-gradient(
    90deg,
    rgba(229, 234, 243, 0) 0%,
    var(--border) 12%,
    var(--border) 88%,
    rgba(229, 234, 243, 0) 100%
  );
}

.step {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 0 14px;
  text-align: center;
}

.step-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  margin: 0 auto 8px;
  border: 1px solid var(--border);
  border-radius: 50%;
  background: var(--card);
  color: var(--brand);
  box-shadow: var(--shadow-sm);
  transition:
    transform 0.22s ease,
    border-color 0.22s ease;
}

.step:hover .step-icon {
  transform: translateY(-3px);
  border-color: var(--brand);
}

.step-index {
  color: var(--text-3);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.14em;
}

.step h3 {
  font-size: 15px;
  font-weight: 700;
}

.step p {
  color: var(--text-2);
  font-size: 12.5px;
  line-height: 1.6;
}

@media (max-width: 900px) {
  .flow {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 28px 0;
  }

  .flow::before {
    display: none;
  }
}

@media (max-width: 480px) {
  .flow {
    grid-template-columns: minmax(0, 1fr);
  }

  .step {
    flex-direction: row;
    align-items: center;
    gap: 12px;
    text-align: left;
  }

  .step-icon {
    margin: 0;
    flex: none;
  }

  .step-index {
    display: none;
  }

  .step h3 {
    flex: none;
  }

  .step p {
    flex: 1;
  }
}
</style>
