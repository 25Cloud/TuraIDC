<template>
  <div class="top-panel">
    <t-card v-for="card in cards" :key="card.key" :bordered="false" class="stat-card">
      <div class="stat-card__body">
        <div class="stat-card__info">
          <span class="stat-card__label">{{ card.label }}</span>
          <span class="stat-card__value">{{ card.value }}</span>
        </div>
        <div class="stat-card__icon" :style="{ background: card.iconBg }">
          <t-icon :name="card.icon" :style="{ color: card.iconColor }" />
        </div>
      </div>
    </t-card>
  </div>
</template>
<script setup lang="ts">
import { computed } from 'vue';

import type { DashboardStats } from '@/api/admin';

defineOptions({ name: 'DashboardTopPanel' });

const props = defineProps<{
  stats: DashboardStats;
}>();

function formatCurrency(value: unknown): string {
  const num = Number(value ?? 0);
  if (num >= 10000) {
    return `¥${(num / 10000).toFixed(2)}万`;
  }
  return `¥${num.toFixed(2)}`;
}

const cards = computed(() => [
  {
    key: 'today_income',
    label: '今日营业额',
    value: formatCurrency(props.stats?.today?.income),
    icon: 'money',
    iconBg: 'var(--td-brand-color-light)',
    iconColor: 'var(--td-brand-color)',
  },
  {
    key: 'month_income',
    label: '本月营业额',
    value: formatCurrency(props.stats?.month?.income),
    icon: 'chart',
    iconBg: 'var(--td-success-color-light, #EAFBF3)',
    iconColor: 'var(--td-success-color, #12B76A)',
  },
  {
    key: 'open_tickets',
    label: '未处理工单',
    value: String(props.stats?.counts?.open_tickets ?? 0),
    icon: 'chat',
    iconBg: 'var(--td-warning-color-light, #FFF6E5)',
    iconColor: 'var(--td-warning-color, #F59E0B)',
  },
  {
    key: 'today_new_users',
    label: '今日新增用户',
    value: String(props.stats?.today?.new_users ?? 0),
    icon: 'user',
    iconBg: 'var(--td-brand-color-light, #ECF2FE)',
    iconColor: 'var(--td-brand-color, #165DFF)',
  },
]);
</script>
<style lang="less" scoped>
.top-panel {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--td-comp-margin-l);
}

.stat-card {
  :deep(.t-card__body) {
    padding: var(--td-comp-paddingTB-xl) var(--td-comp-paddingLR-xl);
  }
}

.stat-card__body {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.stat-card__info {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
}

.stat-card__label {
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-secondary);
  line-height: 1.4;
}

.stat-card__value {
  font-size: 26px;
  font-weight: 600;
  color: var(--td-text-color-primary);
  line-height: 1.2;
  letter-spacing: -0.02em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.stat-card__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: var(--td-radius-extraLarge, 12px);
  flex-shrink: 0;
  margin-left: 16px;

  .t-icon {
    font-size: 24px;
  }
}

@media (width <= 1200px) {
  .top-panel {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (width <= 640px) {
  .top-panel {
    grid-template-columns: 1fr;
  }

  .stat-card__value {
    font-size: var(--td-font-size-size-7, 22px);
  }

  .stat-card__icon {
    width: 42px;
    height: 42px;

    .t-icon {
      font-size: var(--td-font-size-size-6, 20px);
    }
  }
}
</style>
