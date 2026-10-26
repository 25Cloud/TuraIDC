<template>
  <div v-if="runs.length" class="ai-tool-runs">
    <button type="button" class="ai-tool-runs__toggle" :aria-expanded="expanded" @click="expanded = !expanded">
      <chevron-down-icon v-if="expanded" class="ai-tool-runs__arrow" />
      <chevron-right-icon v-else class="ai-tool-runs__arrow" />
      <span class="ai-tool-runs__label">{{ $t('ai.tool.title') }}</span>
      <span class="ai-tool-runs__count">{{ $t('ai.tool.count', { count: runs.length }) }}</span>
      <t-tag v-if="hasDangerous" size="small" theme="danger" variant="light">
        {{ $t('ai.tool.risk.dangerous') }}
      </t-tag>
    </button>

    <ul v-show="expanded" class="ai-tool-runs__list">
      <li v-for="(run, index) in runs" :key="`${run.name}-${index}`" class="ai-tool-run">
        <div class="ai-tool-run__head">
          <code class="ai-tool-run__name">{{ run.name }}</code>
          <t-tag size="small" :theme="riskTheme(run.risk)" variant="light">
            {{ $t(`ai.tool.risk.${run.risk}`) }}
          </t-tag>
          <t-tag size="small" :theme="statusTheme(run.status)" variant="light">
            {{ $t(`ai.tool.status.${run.status}`) }}
          </t-tag>
        </div>
        <p v-if="run.summary" class="ai-tool-run__summary">{{ run.summary }}</p>
      </li>
    </ul>
  </div>
</template>
<script setup lang="ts">
import type { AiToolRun } from '@turaidc/shared/runtime';
import { ChevronDownIcon, ChevronRightIcon } from 'tdesign-icons-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ runs: AiToolRun[] }>();

// 默认收起：对话区优先显示正文，工具过程按需展开
const expanded = ref(false);

const hasDangerous = computed(() => props.runs.some((run) => run.risk === 'dangerous'));

type TagTheme = 'default' | 'primary' | 'success' | 'warning' | 'danger';

const RISK_THEMES: Record<string, TagTheme> = {
  read: 'primary',
  write: 'warning',
  dangerous: 'danger',
};

const STATUS_THEMES: Record<string, TagTheme> = {
  done: 'success',
  pending: 'warning',
  rejected: 'default',
  failed: 'danger',
};

const riskTheme = (risk: string): TagTheme => RISK_THEMES[risk] ?? 'default';
const statusTheme = (status: string): TagTheme => STATUS_THEMES[status] ?? 'default';
</script>
<style scoped lang="less">
.ai-tool-runs {
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
  background: var(--td-bg-color-container-hover);
  border: thin solid var(--td-component-stroke);
  border-radius: var(--td-radius-medium);
}

.ai-tool-runs__toggle {
  display: flex;
  gap: var(--td-comp-margin-xs);
  align-items: center;
  width: 100%;
  padding: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;
}

.ai-tool-runs__arrow {
  flex: none;
  font-size: var(--td-comp-size-xxs);
}

.ai-tool-runs__label {
  color: var(--td-text-color-primary);
}

.ai-tool-runs__count {
  color: var(--td-text-color-placeholder);
}

.ai-tool-runs__list {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: 0;
  margin: var(--td-comp-margin-s) 0 0;
  list-style: none;
}

.ai-tool-run {
  display: grid;
  gap: var(--td-comp-margin-xs);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
  background: var(--td-bg-color-container);
  border-radius: var(--td-radius-small);
}

.ai-tool-run__head {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs);
  align-items: center;
}

.ai-tool-run__name {
  color: var(--td-text-color-primary);
  font-family: ui-monospace, menlo, consolas, monospace;
  font-size: 0.75rem;
  overflow-wrap: anywhere;
}

.ai-tool-run__summary {
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
</style>
