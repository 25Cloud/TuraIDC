<template>
  <div class="admin-record-cards">
    <t-card
      v-for="record in records"
      :key="String(record[rowKey])"
      :bordered="false"
      class="admin-record-card"
    >
      <div class="admin-record-card-head">
        <div class="admin-record-card-title">
          <slot name="title" :record="record">{{ titleOf(record) }}</slot>
        </div>
        <slot name="status" :record="record" />
      </div>

      <div class="admin-record-card-body">
        <div v-for="field in fields" :key="field.key" class="admin-record-field">
          <span class="admin-record-field-label">{{ field.label }}</span>
          <span class="admin-record-field-value">
            <slot name="field" :record="record" :field="field">
              {{ readField(record, field.key) }}
            </slot>
          </span>
        </div>
      </div>

      <div class="admin-record-card-actions">
        <slot name="actions" :record="record" />
      </div>
    </t-card>

    <t-empty v-if="!records.length" class="admin-record-cards-empty" :description="emptyText" />
  </div>
</template>

<script setup lang="ts">
/**
 * 通用的「记录卡片」网格。
 *
 * 后台各列表页的卡片视图此前各写各的（工单、上游是卡片，商品、用户只有表格），
 * 视觉与信息密度都不统一。这里只做数据驱动的骨架：
 * 标题 + 状态槽 + 若干「标签: 值」+ 操作槽，页面只提供字段与插槽，
 * 避免每加一个页面就复制一份卡片样式。
 */
export interface AdminCardField {
  key: string;
  label: string;
}

const props = withDefaults(
  defineProps<{
    records: Array<Record<string, unknown>>;
    /** 行主键字段名，默认 id */
    rowKey?: string;
    /** 卡片标题字段名，默认 name */
    titleKey?: string;
    fields?: AdminCardField[];
    emptyText?: string;
  }>(),
  {
    rowKey: 'id',
    titleKey: 'name',
    fields: () => [],
    emptyText: '暂无数据',
  },
);

function titleOf(record: Record<string, unknown>): string {
  const value = record?.[props.titleKey];

  return value === undefined || value === null || value === '' ? '--' : String(value);
}

function readField(record: Record<string, unknown>, key: string): string {
  const value = key.split('.').reduce<unknown>((source, part) => {
    if (source && typeof source === 'object') {
      return (source as Record<string, unknown>)[part];
    }

    return undefined;
  }, record);

  return value === undefined || value === null || value === '' ? '--' : String(value);
}
</script>

<style lang="less" scoped>
.admin-record-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr));
  gap: 12px;
  padding: 4px 0 8px;
}

.admin-record-card {
  background: var(--td-bg-color-container);
  border: 1px solid var(--td-component-stroke);
  border-radius: var(--td-radius-medium);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;

  &:hover {
    border-color: var(--td-brand-color);
    box-shadow: var(--td-shadow-1);
  }

  :deep(.t-card__body) {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 14px 16px;
  }
}

.admin-record-card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.admin-record-card-title {
  min-width: 0;
  overflow: hidden;
  color: var(--td-text-color-primary);
  font-size: 14px;
  font-weight: 600;
  line-height: 1.5;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-record-card-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.admin-record-field {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  min-width: 0;
  font-size: 13px;
  line-height: 1.6;
}

.admin-record-field-label {
  flex-shrink: 0;
  color: var(--td-text-color-placeholder);
}

.admin-record-field-value {
  min-width: 0;
  overflow: hidden;
  color: var(--td-text-color-primary);
  text-align: right;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-record-card-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
  padding-top: 2px;
}

.admin-record-cards-empty {
  grid-column: 1 / -1;
  padding: 40px 0;
}
</style>
