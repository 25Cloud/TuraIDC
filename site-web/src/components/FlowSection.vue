<script setup lang="ts">
import { FLOW_ICONS } from "../data/site";
import { useSite } from "../i18n/context";
import { ICONS } from "../icons";

const { messages } = useSite();
const section = messages.flow;
</script>

<template>
  <section id="flow" class="section section-alt">
    <div class="container">
      <header class="section-head" data-reveal>
        <span class="eyebrow">{{ section.kicker }}</span>
        <h2>{{ section.title }}</h2>
        <p>{{ section.lead }}</p>
      </header>

      <ol class="flow" data-reveal>
        <li
          v-for="(step, index) in section.steps"
          :key="step.title"
          class="step"
          data-reveal
          :style="{ '--d': `${index * 60}ms` }"
        >
          <span class="step-icon">
            <component
              :is="ICONS[FLOW_ICONS[index]]"
              size="18px"
              aria-hidden="true"
            />
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

/* 连线：进场时从左往右拉开 */
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
  transform: scaleX(0);
  transform-origin: left center;
  transition: transform 1.1s cubic-bezier(0.2, 0.7, 0.3, 1) 0.1s;
}

.flow.is-revealed::before {
  transform: scaleX(1);
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

@media (prefers-reduced-motion: reduce) {
  .flow::before,
  .flow.is-revealed::before {
    transform: none;
    transition: none;
  }
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
