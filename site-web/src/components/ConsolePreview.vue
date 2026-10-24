<script setup lang="ts">
import {
  DashboardIcon,
  MoneyIcon,
  ServerIcon,
  ShopIcon,
  TaskIcon,
  TicketIcon,
} from "tdesign-icons-vue-next";

/**
 * 管理端界面示意。
 *
 * 结构照着真实管理端的骨架（侧栏 + 指标 + 图表 + 列表），数值一律是占位，
 * 页脚与下方 caption 都写明「非真实数据」，避免被当成真实营收截图。
 */
const MENU = [
  { icon: DashboardIcon, label: "仪表盘", active: true },
  { icon: ShopIcon, label: "商品" },
  { icon: TaskIcon, label: "订单" },
  { icon: ServerIcon, label: "实例" },
  { icon: MoneyIcon, label: "财务" },
  { icon: TicketIcon, label: "工单" },
];

const STATS = [
  { label: "本月营收", value: "¥ 86,420.00", delta: "+12.4%" },
  { label: "待处理订单", value: "12", delta: "待开通 3" },
  { label: "在线实例", value: "318", delta: "本月 +26" },
];

const BARS = [38, 52, 44, 66, 58, 78, 71];

const ROWS = [
  { id: "#S-10231", name: "商品 A · 月付", status: "已开通", tone: "ok" },
  { id: "#S-10232", name: "商品 B · 年付", status: "开通中", tone: "warn" },
  { id: "#S-10233", name: "商品 A · 季付", status: "待续费", tone: "idle" },
];
</script>

<template>
  <figure class="preview">
    <div class="window">
      <div class="bar">
        <span class="dot dot-r"></span>
        <span class="dot dot-y"></span>
        <span class="dot dot-g"></span>
        <span class="bar-title">TuraIDC · 管理端</span>
      </div>

      <div class="body">
        <aside class="side">
          <div
            v-for="item in MENU"
            :key="item.label"
            class="side-item"
            :class="{ active: item.active }"
          >
            <component :is="item.icon" size="15px" aria-hidden="true" />
            <span>{{ item.label }}</span>
          </div>
        </aside>

        <div class="main">
          <div class="stat-row">
            <div v-for="stat in STATS" :key="stat.label" class="card">
              <span class="card-label">{{ stat.label }}</span>
              <strong>{{ stat.value }}</strong>
              <span class="card-delta">{{ stat.delta }}</span>
            </div>
          </div>

          <div class="chart">
            <div class="chart-head">
              <span>近 7 日收入</span>
              <span class="chip">示意</span>
            </div>
            <div class="bars" aria-hidden="true">
              <i
                v-for="(height, index) in BARS"
                :key="index"
                :style="{ height: `${height}%` }"
              />
            </div>
          </div>

          <ul class="rows">
            <li v-for="row in ROWS" :key="row.id" class="row">
              <span class="row-id">{{ row.id }}</span>
              <span class="row-name">{{ row.name }}</span>
              <span class="tag" :data-tone="row.tone">{{ row.status }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
    <figcaption class="caption">
      界面示意，数值为占位，不是真实数据截图。
    </figcaption>
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
  background: linear-gradient(180deg, #6d9bff 0%, #165dff 100%);
  opacity: 0.9;
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
  color: #0b7a45;
}

.tag[data-tone="warn"] {
  background: var(--warning-soft);
  color: #9a6100;
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
