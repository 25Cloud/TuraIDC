<template>
  <article class="mobile-record-card" :class="{ 'is-selectable': selectable, 'is-selected': selected }">
    <label v-if="selectable" class="mobile-record-card__select">
      <t-checkbox :checked="selected" @change="(value: boolean) => emit('select', value)" />
    </label>
    <div class="mobile-record-card__head">
      <div class="mobile-record-card__title">
        <span v-if="eyebrow" class="mobile-record-card__eyebrow">{{ eyebrow }}</span>
        <strong>{{ title || '-' }}</strong>
        <t-tag v-if="subtitle" variant="light">{{ subtitle }}</t-tag>
      </div>
      <div class="mobile-record-card__tools">
        <status-tag v-if="statusMap" :status-map="statusMap" :status="status ?? ''" />
        <t-tag v-else-if="statusLabel" :theme="statusTheme" variant="light">{{ statusLabel }}</t-tag>
        <t-dropdown v-if="actionOptions.length" :options="actionOptions" @click="handleAction">
          <t-button class="mobile-record-card__more" variant="text" shape="square">...</t-button>
        </t-dropdown>
      </div>
    </div>

    <p v-if="description" class="mobile-record-card__description">{{ description }}</p>

    <div v-if="highlightLabel || highlightValue" class="mobile-record-card__highlight">
      <span>{{ highlightLabel }}</span>
      <strong>{{ displayValue(highlightValue) }}</strong>
    </div>

    <dl v-if="visibleRows.length" class="mobile-record-card__rows">
      <div v-for="row in visibleRows" :key="row.label">
        <dt>{{ row.label }}</dt>
        <dd :class="{ 'is-strong': row.strong }">{{ displayValue(row.value) }}</dd>
      </div>
    </dl>
  </article>
</template>
<script setup lang="ts">
import type { DropdownOption, TagProps } from 'tdesign-vue-next';
import { computed } from 'vue';

import StatusTag from '@/components/status-tag/index.vue';

type ThemeType = NonNullable<TagProps['theme']>;

export interface MobileRecordCardRow {
  label: string;
  value?: string | number | null;
  show?: boolean;
  strong?: boolean;
}

const props = withDefaults(
  defineProps<{
    title: string;
    subtitle?: string;
    eyebrow?: string;
    description?: string;
    highlightLabel?: string;
    highlightValue?: string | number | null;
    statusLabel?: string;
    statusTheme?: ThemeType;
    statusMap?: Record<string, any>;
    status?: number | string;
    rows?: MobileRecordCardRow[];
    actionOptions?: DropdownOption[];
    selectable?: boolean;
    selected?: boolean;
  }>(),
  {
    subtitle: '',
    eyebrow: '',
    description: '',
    highlightLabel: '',
    highlightValue: '',
    statusLabel: '',
    statusTheme: 'default',
    statusMap: undefined,
    status: undefined,
    rows: () => [],
    actionOptions: () => [],
    selectable: false,
    selected: false,
  },
);

const emit = defineEmits<{
  (event: 'action', value: unknown): void;
  (event: 'select', value: boolean): void;
}>();

const visibleRows = computed(() => props.rows.filter((row) => row.show !== false));

function displayValue(value: string | number | null | undefined) {
  if (value === null || value === undefined || value === '') return '-';
  return String(value);
}

function handleAction(option: DropdownOption) {
  emit('action', option.value);
}
</script>
<style lang="less" scoped>
.mobile-record-card {
  position: relative;
  overflow: hidden;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-large, 8px);
  background: var(--td-bg-color-container);
  box-shadow: 0 4px 14px rgb(28 45 84 / 6%);
}

.mobile-record-card.is-selected {
  border-color: var(--td-component-border);
  background: var(--td-bg-color-page);
  box-shadow: 0 4px 14px rgb(28 45 84 / 8%);
}

.mobile-record-card__select {
  position: absolute;
  z-index: 1;
  top: 8px;
  left: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
}

.mobile-record-card.is-selectable .mobile-record-card__head {
  padding-left: 44px;
}

.mobile-record-card__head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
  min-height: 54px;
  border-bottom: 1px solid var(--td-component-border);
  padding: 10px 12px;
}

.mobile-record-card__title {
  display: flex;
  min-width: 0;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
}

.mobile-record-card__eyebrow {
  color: var(--td-text-color-placeholder);
  font-size: 11px;
  font-weight: 600;
  line-height: 1;
}

.mobile-record-card__title strong {
  max-width: 100%;
  overflow: hidden;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-3, 14px);
  font-weight: 600;
  line-height: 20px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mobile-record-card__tools {
  display: flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
}

.mobile-record-card__more {
  width: 44px;
  min-width: 44px;
  height: 44px;
  min-height: 44px;
  padding: 0;
  color: var(--td-text-color-placeholder);
  font-weight: 700;
  letter-spacing: 1px;
}

.mobile-record-card__description {
  display: -webkit-box;
  overflow: hidden;
  margin: 0;
  border-bottom: 1px solid var(--td-component-border);
  padding: 8px 12px;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 20px;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.mobile-record-card__highlight {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
  border-bottom: 1px solid var(--td-component-border);
  padding: 10px 12px 8px;
}

.mobile-record-card__highlight span {
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-size-1, 12px);
}

.mobile-record-card__highlight strong {
  color: var(--td-brand-color);
  font-size: var(--td-font-size-size-5, 18px);
  font-weight: 700;
  line-height: 1.2;
}

.mobile-record-card__rows {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0;
  margin: 0;
  padding: 8px 12px 9px;
}

.mobile-record-card__rows div {
  display: grid;
  grid-template-columns: 62px minmax(0, 1fr);
  align-items: center;
  min-width: 0;
  padding: 3px 0;
}

.mobile-record-card__rows dt {
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 20px;
}

.mobile-record-card__rows dd {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  min-width: 0;
  margin: 0;
  overflow: hidden;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-2, 13px);
  font-weight: 500;
  line-height: 20px;
  text-align: right;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mobile-record-card__rows dd.is-strong {
  font-weight: 600;
}

.mobile-record-card :deep(.t-tag) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-width: 44px;
  padding-right: 8px;
  padding-left: 8px;
}
</style>
