<template>
  <div class="product-description-editor">
    <!-- 描述来源不同，用户需要的引导也不同：自动生成给预览，人工填写给恢复路径 -->
    <t-alert
      v-if="isManual"
      class="product-description-editor-alert"
      theme="success"
      :message="t('products.description.manualHint')"
    />
    <t-alert
      v-else
      class="product-description-editor-alert"
      theme="info"
      :message="t('products.description.autoHint')"
    />

    <div v-if="!isManual && autoHtml" class="product-description-preview">
      <strong class="product-description-preview-title">{{ t('products.description.previewTitle') }}</strong>
      <!-- eslint-disable-next-line vue/no-v-html -- autoHtml 已由后端富文本白名单消毒，这里只做只读渲染 -->
      <div class="product-description-preview-body" v-html="autoHtml" />
    </div>

    <div v-if="!isManual && autoItems.length" class="product-description-auto-items">
      <t-tag v-for="item in autoItems" :key="item.key" size="small" variant="light">
        {{ item.label }}: {{ item.value }}
      </t-tag>
    </div>

    <t-radio-group
      :value="activeMode"
      class="product-description-mode"
      variant="default-filled"
      @change="handleModeChange"
    >
      <t-radio-button value="structured">{{ t('products.description.modeStructured') }}</t-radio-button>
      <t-radio-button value="advanced">{{ t('products.description.modeAdvanced') }}</t-radio-button>
    </t-radio-group>

    <template v-if="activeMode === 'structured'">
      <div v-if="!rows.length" class="product-description-empty">{{ t('products.description.emptyRows') }}</div>
      <div v-for="(row, index) in rows" :key="row.uid" class="product-description-row">
        <t-input
          v-model="row.key"
          class="product-description-row-key"
          :placeholder="t('products.description.keyPlaceholder')"
        />
        <t-input
          v-model="row.value"
          class="product-description-row-value"
          :placeholder="t('products.description.valuePlaceholder')"
        />
        <t-space class="product-description-row-actions" size="small">
          <t-button
            variant="text"
            :disabled="index === 0"
            :title="t('products.description.moveUp')"
            @click="moveRow(index, -1)"
          >
            <template #icon><arrow-up-icon /></template>
          </t-button>
          <t-button
            variant="text"
            :disabled="index === rows.length - 1"
            :title="t('products.description.moveDown')"
            @click="moveRow(index, 1)"
          >
            <template #icon><arrow-down-icon /></template>
          </t-button>
          <t-button
            variant="text"
            theme="danger"
            :title="t('products.description.removeRow')"
            @click="removeRow(index)"
          >
            <template #icon><delete-icon /></template>
          </t-button>
        </t-space>
      </div>
      <t-button variant="outline" size="small" class="product-description-add" @click="addRow">
        <template #icon><add-icon /></template>
        {{ t('products.description.addRow') }}
      </t-button>
    </template>

    <template v-else>
      <div class="product-description-html-label">{{ t('products.description.htmlLabel') }}</div>
      <t-textarea v-model="htmlDraft" class="product-description-html" :autosize="{ minRows: 14, maxRows: 22 }" />
    </template>
  </div>
</template>
<script setup lang="ts">
import { AddIcon, ArrowDownIcon, ArrowUpIcon, DeleteIcon } from 'tdesign-icons-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

import type { ProductDescriptionItem } from '@/api/product';
import { t } from '@/locales';
import { buildDescriptionHtml, parseDescriptionRows } from '@/utils/productDescription';

defineOptions({ name: 'AdminProductDescriptionEditor' });

const props = withDefaults(
  defineProps<{
    /** 当前生效的描述 HTML，与商品表单的 description 字段一一对应 */
    modelValue?: string;
    /** 后端按规格维度自动生成的描述 HTML（已消毒），仅用于只读预览 */
    autoHtml?: string;
    /** 自动生成所依据的规格维度 */
    autoItems?: ProductDescriptionItem[];
    /** 描述来源：manual 表示人工填写，auto / empty 表示走自动生成 */
    source?: string;
  }>(),
  {
    modelValue: '',
    autoHtml: '',
    autoItems: () => [],
    source: 'empty',
  },
);

const emit = defineEmits<{
  (event: 'update:modelValue', value: string): void;
  (event: 'update:rows', value: { key: string; value: string }[]): void;
}>();

/** 结构化行；uid 只用于 v-for 的稳定 key，不参与描述内容 */
interface EditorRow {
  uid: number;
  key: string;
  value: string;
}

// --- 内部状态 ---
const activeMode = ref<'structured' | 'advanced'>('structured');
const htmlDraft = ref(String(props.modelValue || ''));

let uidSeed = 0;

function nextUid() {
  uidSeed += 1;
  return uidSeed;
}

function toEditorRows(items: { key: string; value: string }[]): EditorRow[] {
  return items.map((item) => ({ uid: nextUid(), key: item.key, value: item.value }));
}

const rows = ref<EditorRow[]>(toEditorRows(parseDescriptionRows(htmlDraft.value)));

function toPlainRows(): { key: string; value: string }[] {
  return rows.value.map((row) => ({ key: row.key, value: row.value }));
}

// source 是后端按「库里有没有值」判定的，商家刚敲进去还没保存时它仍是 auto。
// 提示要跟着实际内容走，否则会出现「已经填了描述，顶部还在说留空会自动生成」。
const isManual = computed(() => props.source === 'manual' || String(props.modelValue || '').trim() !== '');

// --- 双向同步 ---
/** 最近一次由本组件抛出的 HTML，用来区分「外部回填」与「自身编辑」 */
let lastEmitted = String(props.modelValue || '');
/** 外部回填会直接重建 rows，这一轮不能当成商家编辑抛出去 */
let rehydrating = false;

function emitHtml(html: string) {
  lastEmitted = html;
  emit('update:modelValue', html);
}

/**
 * 用一份 HTML 重建两侧视图，但不当作商家编辑抛事件。
 * nextTick 复位，保证这一轮被排队的 watch 回调跑完之后才恢复对外抛出。
 */
function rehydrateFrom(html: string) {
  rehydrating = true;
  htmlDraft.value = html;
  rows.value = toEditorRows(parseDescriptionRows(html));
  void nextTick(() => {
    rehydrating = false;
  });
}

watch(
  () => props.modelValue,
  (value) => {
    const next = String(value || '');
    if (next === lastEmitted) return;
    lastEmitted = next;
    rehydrateFrom(next);
  },
);

// 结构化模式：任何改动都以当前行重新生成 HTML 为准
watch(
  rows,
  () => {
    if (rehydrating || activeMode.value !== 'structured') return;
    const plain = toPlainRows();
    emitHtml(buildDescriptionHtml(plain));
    emit('update:rows', plain);
  },
  { deep: true },
);

// 高级模式：直接以 textarea 内容为准
watch(htmlDraft, (value) => {
  if (rehydrating || activeMode.value !== 'advanced') return;
  emitHtml(value);
});

/**
 * 模式切换时把另一侧的视图对齐到当前内容，两种模式编辑的始终是同一份 HTML：
 * 结构化 -> 高级用 buildDescriptionHtml 生成源码，高级 -> 结构化用 parseDescriptionRows 拆行。
 */
function handleModeChange(value: string | number | boolean) {
  const next = value === 'advanced' ? 'advanced' : 'structured';
  if (next === activeMode.value) return;
  if (next === 'advanced') {
    htmlDraft.value = buildDescriptionHtml(toPlainRows());
  } else {
    // 解析不出键值的整行会被保留为「key 留空 + 整段文本放进 value」的一行，切回来不丢内容；
    // 这里只重建视图，商家原本的高级模式 HTML 保持不变，直到真的改动某一行
    rehydrateFrom(htmlDraft.value);
  }
  activeMode.value = next;
}

// --- 行操作 ---
function addRow() {
  rows.value = [...rows.value, { uid: nextUid(), key: '', value: '' }];
}

function removeRow(index: number) {
  rows.value = rows.value.filter((_row, position) => position !== index);
}

function moveRow(index: number, offset: number) {
  const target = index + offset;
  if (target < 0 || target >= rows.value.length) return;
  const next = [...rows.value];
  const [moved] = next.splice(index, 1);
  next.splice(target, 0, moved);
  rows.value = next;
}
</script>
<style lang="less" scoped>
.product-description-editor {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-l, 16px);
}

.product-description-preview {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs, 4px);
  border: 1px solid var(--td-border-level-1-color);
  border-radius: var(--td-radius-medium, 6px);
  padding: var(--td-comp-margin-l, 16px);
  background: var(--td-bg-color-secondarycontainer);
}

.product-description-preview-title {
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
}

.product-description-preview-body {
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-2, 14px);
  word-break: break-word;

  :deep(ul) {
    margin: 0;
    padding-left: var(--td-comp-margin-l, 16px);
  }
}

.product-description-auto-items {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs, 4px);
}

.product-description-empty {
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-size-2, 14px);
}

.product-description-row {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s, 8px);
}

.product-description-row-key {
  width: 200px;
}

.product-description-row-value {
  flex: 1;
}

.product-description-row-actions {
  flex-shrink: 0;
}

.product-description-add {
  align-self: flex-start;
}

.product-description-html-label {
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
}

.product-description-html {
  font-family: Consolas, Monaco, 'Courier New', monospace;
  font-size: var(--td-font-size-size-1, 12px);
}
</style>
