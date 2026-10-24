<script setup lang="ts">
import {
  DashboardIcon,
  MoneyIcon,
  ServerIcon,
  ShopIcon,
  TaskIcon,
  TicketIcon,
} from "tdesign-icons-vue-next";

import { useSite } from "../i18n/context";

/**
 * 管理端界面示意。
 *
 * 结构照着真实管理端的骨架（侧栏 + 指标 + 图表 + 列表），数值一律是占位，
 * 窗口下方写明「非真实数据」，避免被当成真实营收截图。
 */
const { messages } = useSite();
const ui = messages.console;

const MENU_ICONS = [
  DashboardIcon,
  ShopIcon,
  TaskIcon,
  ServerIcon,
  MoneyIcon,
  TicketIcon,
];

const BARS = [38, 52, 44, 66, 58, 78, 71];
const TONES = ["ok", "warn", "idle"];
</script>

<template>
  <figure class="preview">
    <div class="window">
      <div class="bar">
        <span class="dot dot-r"></span>
        <span class="dot dot-y"></span>
        <span class="dot dot-g"></span>
        <span class="bar-title">{{ ui.title }}</span>
      </div>

      <div class="body">
        <aside class="side">
          <div
            v-for="(item, index) in ui.menu"
            :key="item"
            class="side-item"
            :class="{ active: index === 0 }"
          >
            <component :is="MENU_ICONS[index]" size="15px" aria-hidden="true" />
            <span>{{ item }}</span>
          </div>
        </aside>

        <div class="main">
          <div class="stat-row">
            <div v-for="stat in ui.stats" :key="stat.label" class="card">
              <span class="card-label">{{ stat.label }}</span>
              <strong>{{ stat.value }}</strong>
              <span class="card-delta">{{ stat.delta }}</span>
            </div>
          </div>

          <div class="chart">
            <div class="chart-head">
              <span>{{ ui.chart }}</span>
              <span class="chip">{{ ui.chip }}</span>
            </div>
            <div class="bars" aria-hidden="true">
              <i
                v-for="(height, index) in BARS"
                :key="index"
                :style="{ height: `${height}%`, '--bar-d': `${index * 70}ms` }"
              />
            </div>
          </div>

          <ul class="rows">
            <li v-for="(row, index) in ui.rows" :key="row.id" class="row">
              <span class="row-id">{{ row.id }}</span>
              <span class="row-name">{{ row.name }}</span>
              <span class="tag" :data-tone="TONES[index]">{{
                row.status
              }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
    <figcaption class="caption">{{ ui.caption }}</figcaption>
  </figure>
</template>

<style scoped>
.preview {
  margin: 0;
  animation: float 9s ease-in-out infinite;
}

@keyframes float {
  0%,
  100% {
    transform: translateY(0);
  }

  50% {
    transform: translateY(-10px);
  }
}

.window {
  overflow: hidden;
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--card);
  box-shadow: var(--shadow-lg);
}

.bar {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 38px;
  padding: 0 14px;
  border-bottom: 1px solid var(--line);
  background: var(--bg-2);
}

.dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}

.dot-r {
  background: #fdb3ae;
}

.dot-y {
  background: #f8d48a;
}

.dot-g {
  background: #a9e3bf;
}

.bar-title {
  margin-left: 10px;
  color: var(--text-3);
  font-size: 11.5px;
}

.body {
  display: grid;
  grid-template-columns: 132px minmax(0, 1fr);
  min-height: 330px;
}

.side {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 12px 8px;
  border-right: 1px solid var(--line);
  background: var(--bg-2);
}

.side-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 9px;
  border-radius: var(--radius-sm);
  color: var(--text-2);
  font-size: 12px;
}

.side-item.active {
  background: var(--brand-soft);
  color: var(--brand);
  font-weight: 600;
}

.main {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
}

.stat-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.card {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 10px 11px;
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  background: var(--card);
}

.card-label {
  color: var(--text-3);
  font-size: 10.5px;
}

.card strong {
  font-size: 15px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.card-delta {
  color: var(--text-3);
  font-size: 10.5px;
}

.chart {
  padding: 10px 12px 12px;
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
}

.chart-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--text-2);
  font-size: 11px;
}

.chip {
  padding: 2px 7px;
  border-radius: 999px;
  background: var(--brand-soft);
  color: var(--brand);
  font-size: 10px;
}

.bars {
  display: flex;
  align-items: flex-end;
  gap: 6px;
  height: 64px;
  margin-top: 12px;
}

.bars i {
  flex: 1;
  min-height: 6px;
  border-radius: 4px 4px 2px 2px;
  background: linear-gradient(180deg, var(--brand-hover) 0%, var(--brand) 100%);
  opacity: 0.92;
  transform-origin: bottom;
  animation: grow 0.8s cubic-bezier(0.2, 0.7, 0.3, 1) both;
  animation-delay: var(--bar-d, 0ms);
}

@keyframes grow {
  from {
    transform: scaleY(0.2);
    opacity: 0;
  }

  to {
    transform: scaleY(1);
    opacity: 0.92;
  }
}

.rows {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--line);
  border-radius: var(--radius-sm);
  font-size: 11.5px;
}

.row-id {
  color: var(--text-3);
  font-variant-numeric: tabular-nums;
}

.row-name {
  flex: 1;
  overflow: hidden;
  color: var(--text-2);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tag {
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 10.5px;
}

.tag[data-tone="ok"] {
  background: var(--success-soft);
  color: var(--ok-ink);
}

.tag[data-tone="warn"] {
  background: var(--warning-soft);
  color: var(--warn-ink);
}

.tag[data-tone="idle"] {
  background: var(--brand-soft);
  color: var(--brand-ink);
}

.caption {
  margin-top: 12px;
  color: var(--text-3);
  font-size: 11.5px;
  text-align: right;
}

@media (prefers-reduced-motion: reduce) {
  .preview {
    animation: none;
  }

  .bars i {
    animation: none;
  }
}

@media (max-width: 560px) {
  .body {
    grid-template-columns: minmax(0, 1fr);
    min-height: auto;
  }

  .side {
    display: none;
  }

  .stat-row {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
  }

  .card {
    padding: 8px 9px;
  }

  .card strong {
    font-size: 12.5px;
    line-height: 1.35;
  }

  .bars {
    height: 52px;
  }
}
</style>
