<template>
  <div class="record-detail-page">
    <div class="record-detail-toolbar">
      <t-button variant="text" theme="default" @click="emit('back')">
        <template #icon><chevron-left-icon /></template>
        {{ backText }}
      </t-button>
      <div class="record-detail-toolbar__actions">
        <slot name="toolbar-actions" />
        <t-button variant="outline" :loading="loading" @click="emit('refresh')">
          <template #icon><refresh-icon /></template>
          刷新
        </t-button>
      </div>
    </div>

    <t-loading :loading="loading" size="small">
      <div v-if="ready" class="record-detail-shell">
        <section class="record-detail-summary">
          <div class="record-detail-summary__identity">
            <span>{{ eyebrow }}</span>
            <strong>{{ title || '-' }}</strong>
            <p v-if="description">{{ description }}</p>
          </div>
          <div v-if="statusLabel" class="record-detail-summary__status">
            <span>状态</span>
            <t-tag :theme="statusTheme" variant="light">{{ statusLabel }}</t-tag>
          </div>
          <div v-for="metric in visibleMetrics" :key="metric.label" class="record-detail-summary__metric">
            <span>{{ metric.label }}</span>
            <strong :class="{ 'is-primary': metric.primary }">{{ displayValue(metric.value) }}</strong>
          </div>
        </section>

        <div v-if="$slots.relations" class="record-detail-relations">
          <slot name="relations" />
        </div>

        <section class="record-detail-body">
          <t-tabs
            v-if="visibleTabs.length > 1"
            :value="activeTab"
            @change="(value: string | number) => emit('update:activeTab', String(value))"
          >
            <t-tab-panel v-for="tab in visibleTabs" :key="tab.value" :value="tab.value" :label="tab.label" />
          </t-tabs>
          <div class="record-detail-body__content">
            <slot :name="`tab-${currentTab}`" />
          </div>
        </section>
      </div>
      <t-empty v-else-if="!loading" :description="emptyText" />
    </t-loading>
  </div>
</template>
<script setup lang="ts">
import { ChevronLeftIcon, RefreshIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';

export interface RecordDetailMetric {
  label: string;
  value?: string | number | null;
  primary?: boolean;
  show?: boolean;
}

export interface RecordDetailTab {
  value: string;
  label: string;
  show?: boolean;
}

const props = withDefaults(
  defineProps<{
    loading?: boolean;
    ready?: boolean;
    backText?: string;
    eyebrow?: string;
    title?: string;
    description?: string;
    statusLabel?: string;
    statusTheme?: string;
    metrics?: RecordDetailMetric[];
    tabs?: RecordDetailTab[];
    activeTab?: string;
    emptyText?: string;
  }>(),
  {
    loading: false,
    ready: false,
    backText: '返回',
    eyebrow: '详情',
    title: '',
    description: '',
    statusLabel: '',
    statusTheme: 'default',
    metrics: () => [],
    tabs: () => [{ value: 'basic', label: '基本信息' }],
    activeTab: 'basic',
    emptyText: '暂无详情',
  },
);

const emit = defineEmits<{
  (event: 'back'): void;
  (event: 'refresh'): void;
  (event: 'update:activeTab', value: string): void;
}>();

const visibleMetrics = computed(() => props.metrics.filter((item) => item.show !== false));
const visibleTabs = computed(() => props.tabs.filter((item) => item.show !== false));
const currentTab = computed(() => props.activeTab || visibleTabs.value[0]?.value || 'basic');

function displayValue(value: string | number | null | undefined) {
  if (value === null || value === undefined || value === '') return '-';
  return String(value);
}
</script>
<style lang="less" scoped>
.record-detail-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.record-detail-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 40px;
}

.record-detail-toolbar__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.record-detail-shell {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.record-detail-summary {
  display: grid;
  grid-template-columns: minmax(0, 1.8fr) minmax(120px, 0.7fr) repeat(3, minmax(120px, 1fr));
  overflow: hidden;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-large, 8px);
  background: linear-gradient(135deg, rgba(0, 82, 217, 0.07), transparent 42%), var(--td-bg-color-container);
  box-shadow: 0 8px 20px rgba(28, 45, 84, 0.06);
}

.record-detail-summary > div {
  display: flex;
  min-width: 0;
  flex-direction: column;
  justify-content: center;
  gap: 6px;
  border-left: 1px solid var(--td-component-border);
  padding: 15px 16px;
}

.record-detail-summary > div:first-child {
  border-left: 0;
}

.record-detail-summary span {
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 1;
}

.record-detail-summary strong {
  overflow: hidden;
  color: var(--td-text-color-primary);
  font-size: 15px;
  font-weight: 650;
  line-height: 1.45;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.record-detail-summary strong.is-primary {
  color: var(--td-brand-color);
}

.record-detail-summary__identity strong {
  font-size: var(--td-font-size-size-6, 20px);
  letter-spacing: 0;
}

.record-detail-summary__identity p {
  overflow: hidden;
  margin: 0;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-2, 13px);
  line-height: 1.55;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.record-detail-relations {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.record-detail-body {
  overflow: hidden;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-large, 8px);
  background: var(--td-bg-color-container);
}

.record-detail-body__content {
  padding: 16px;
}

@media (max-width: 1024px) {
  .record-detail-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .record-detail-summary__identity {
    grid-column: 1 / -1;
  }
}

@media (max-width: 768px) {
  .record-detail-toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .record-detail-toolbar__actions,
  .record-detail-toolbar__actions :deep(.t-button),
  .record-detail-toolbar > :deep(.t-button) {
    width: 100%;
  }

  .record-detail-summary {
    grid-template-columns: 1fr;
  }

  .record-detail-summary > div {
    border-top: 1px solid var(--td-component-border);
    border-left: 0;
    padding: 13px 14px;
  }

  .record-detail-summary > div:first-child {
    border-top: 0;
  }

  .record-detail-summary strong {
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .record-detail-body__content {
    padding: 12px;
  }
}
</style>
