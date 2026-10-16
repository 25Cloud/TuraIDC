<!--
  控制台数据面板的「加载中 / 加载失败 / 真的为空 / 有数据」四态容器。

  为什么不用 shared/user-v3 的 DataState：
  1. DataState 只有 loading / empty 两态，把错误和空态混成同一个 t-empty，
     用户分不清「拉取失败」和「本来就没有」，这是控制台最容易被投诉的体验；
  2. DataState 的 loading 只是一行居中文字，没有骨架占位，列表页高度会塌成一条；
  3. 控制台走 TDesign，需要遮罩 + 骨架的组合表现，这里直接用 TDesign 原语实现，
     不引入第二套 UI 框架（AGENTS.md 明确禁止混用）。

  注意：tdesign-vue-next 1.20 没有 Result 组件，错误态是图标 + 文案 + 按钮手写的，
  目的是让「加载失败」在颜色和操作上都能和「真的为空」一眼分开。
-->
<template>
  <div class="panel-state" :class="{ 'is-refreshing': showRefreshDim }">
    <!-- 首次加载：整块遮罩 + 骨架占位。
         空白且无提示最容易被当成「没有数据」，必须占住版面并说明正在取数。 -->
    <div v-if="isFirstLoading" class="panel-state__mask" role="status" aria-live="polite">
      <t-skeleton class="panel-state__skeleton" :loading="true" :row-col="skeletonRows" animation="gradient" />
      <div class="panel-state__mask-overlay">
        <span class="panel-state__spinner" aria-hidden="true"></span>
        <p class="panel-state__mask-text">{{ $t('serviceConsole.panelState.loading') }}</p>
        <p class="panel-state__mask-hint">{{ $t('serviceConsole.panelState.loadingHint') }}</p>
      </div>
    </div>

    <!-- 加载失败：红色错误块 + 重试按钮，与空态在底色和操作上彻底分开 -->
    <div v-else-if="errorText" class="panel-state__error" role="alert">
      <error-circle-filled-icon class="panel-state__error-icon" />
      <p class="panel-state__error-title">{{ $t('serviceConsole.panelState.errorTitle') }}</p>
      <p class="panel-state__error-message">{{ errorText || $t('serviceConsole.panelState.errorDefault') }}</p>
      <p class="panel-state__error-hint">{{ $t('serviceConsole.panelState.errorHint') }}</p>
      <t-button theme="primary" variant="outline" @click="emit('retry')">
        {{ $t('serviceConsole.panelState.retry') }}
      </t-button>
    </div>

    <!-- 上游明确声明不支持该能力：说清原因，避免被误读成加载坏了 -->
    <t-empty v-else-if="unsupported" :description="unsupportedText || $t('serviceConsole.panelState.emptyDefault')" />

    <!-- 真的为空：给下一步引导，而不是一片空白 -->
    <t-empty v-else-if="empty" :description="emptyText || $t('serviceConsole.panelState.emptyDefault')" />

    <slot v-else />
  </div>
</template>
<script setup lang="ts">
import { ErrorCircleFilledIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';

/** 骨架行数：列表型面板给 4 行，卡片型面板给 2-3 行，避免占位高度与真实内容差太多 */
const props = withDefaults(
  defineProps<{
    /** 请求进行中 */
    loading?: boolean;
    /** 是否已经拿到过一份有效数据。决定是「整块遮罩」还是「保留内容 + 刷新按钮转圈」 */
    loaded?: boolean;
    /** 错误文案，空串表示没有错误 */
    error?: string;
    /** 已加载成功但没有任何条目 */
    empty?: boolean;
    /** 上游明确不支持该能力 */
    unsupported?: boolean;
    emptyText?: string;
    unsupportedText?: string;
    /** 骨架行数 */
    skeleton?: number;
  }>(),
  {
    loading: false,
    loaded: false,
    error: '',
    empty: false,
    unsupported: false,
    emptyText: '',
    unsupportedText: '',
    skeleton: 4,
  },
);

const emit = defineEmits<{ retry: [] }>();

/**
 * 只在「还没拿到过数据」时盖整块遮罩。
 * 已经加载过一次后的再次请求属于后台刷新，内容留着 + 刷新按钮转圈即可，
 * 整块遮罩会把用户正在看的内容闪掉。
 */
const isFirstLoading = computed(() => props.loading && !props.loaded);

/** 后台刷新：轻微降透明度，明确「正在更新」，但不遮挡操作 */
const showRefreshDim = computed(() => props.loading && props.loaded);

const errorText = computed(() => String(props.error || '').trim());

const skeletonRows = computed(() => {
  const rows: number[] = [];
  for (let i = 0; i < Math.max(1, props.skeleton); i += 1) rows.push(1);
  return rows;
});
</script>
