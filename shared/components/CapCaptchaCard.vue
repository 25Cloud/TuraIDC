<script setup lang="ts">
import { computed, shallowRef } from 'vue';

import { solveCapCaptcha } from '../captcha';

/** 状态文案；由适配器注入 i18n 文案，缺省回退中文 */
export interface CapCaptchaLabels {
  idle?: string;
  verifying?: string;
  solved?: string;
  error?: string;
}

const props = withDefaults(
  defineProps<{
    apiEndpoint: string;
    /** 挂载在 body 等容器外时作为悬浮卡片展示（管理端插件测试用） */
    floating?: boolean;
    labels?: CapCaptchaLabels;
  }>(),
  {
    floating: false,
    labels: () => ({}),
  },
);

const DEFAULT_LABELS: Required<CapCaptchaLabels> = {
  idle: '点击验证',
  verifying: '验证中',
  solved: '验证通过',
  error: '验证失败',
};

const labels = computed<Required<CapCaptchaLabels>>(() => ({ ...DEFAULT_LABELS, ...props.labels }));

const emit = defineEmits<{
  solve: [token: string];
  error: [message: string];
}>();

type State = 'idle' | 'verifying' | 'solved' | 'error';
const state = shallowRef<State>('idle');
const progress = shallowRef(0);
const errorMsg = shallowRef('');

/** aria-live 播报文本：仅播报开始、完成、失败状态，避免与视觉文案重复朗读 */
const liveStatusText = computed(() => {
  if (state.value === 'verifying') {
    return `${labels.value.verifying} ${progress.value}%`;
  }
  if (state.value === 'solved') {
    return labels.value.solved;
  }
  if (state.value === 'error') {
    return errorMsg.value || labels.value.error;
  }
  return '';
});

async function handleVerify() {
  if (state.value === 'verifying') {
    return;
  }

  state.value = 'verifying';
  progress.value = 0;
  errorMsg.value = '';

  try {
    const token = await solveCapCaptcha(props.apiEndpoint, (pct) => {
      progress.value = pct;
    });
    state.value = 'solved';
    emit('solve', token);
  } catch (err) {
    state.value = 'error';
    errorMsg.value = err instanceof Error ? err.message : labels.value.error;
    emit('error', errorMsg.value);
  }
}

function handleReset() {
  state.value = 'idle';
  progress.value = 0;
  errorMsg.value = '';
}

function handleBodyClick() {
  if (state.value === 'idle') {
    handleVerify();
  } else if (state.value === 'error') {
    handleReset();
  }
}

function handleBodyKeydown(event: KeyboardEvent) {
  if (event.key !== 'Enter' && event.key !== ' ') {
    return;
  }
  event.preventDefault();
  handleBodyClick();
}
</script>

<template>
  <div class="cap-card" :class="[`cap-${state}`, { 'cap-floating': floating }]">
    <span class="cap-sr-only" role="status" aria-live="polite">{{ liveStatusText }}</span>
    <div
      class="cap-body"
      :class="{ 'cap-clickable': state === 'idle' || state === 'error' }"
      role="button"
      :tabindex="state === 'idle' || state === 'error' ? 0 : -1"
      :aria-disabled="state === 'verifying' || state === 'solved'"
      @click="handleBodyClick"
      @keydown="handleBodyKeydown"
    >
      <div class="cap-content">
        <div class="cap-state" :class="{ 'cap-active': state === 'idle' }" :aria-hidden="state !== 'idle'">
          <div class="cap-checkbox" />
          <span class="cap-label">{{ labels.idle }}</span>
        </div>
        <div class="cap-state" :class="{ 'cap-active': state === 'verifying' }" :aria-hidden="state !== 'verifying'">
          <div class="cap-spinner">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="12" cy="12" r="10" stroke-dasharray="50" stroke-dashoffset="15" />
            </svg>
          </div>
          <span class="cap-label">{{ labels.verifying }} <span class="cap-pct">{{ progress }}%</span></span>
        </div>
        <div class="cap-state" :class="{ 'cap-active': state === 'solved' }" :aria-hidden="state !== 'solved'">
          <div class="cap-icon cap-ok">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="4 12 9 17 20 6" />
            </svg>
          </div>
          <span class="cap-label">{{ labels.solved }}</span>
        </div>
        <div class="cap-state" :class="{ 'cap-active': state === 'error' }" :aria-hidden="state !== 'error'">
          <div class="cap-icon cap-fail">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10" />
              <line x1="15" y1="9" x2="9" y2="15" />
              <line x1="9" y1="9" x2="15" y2="15" />
            </svg>
          </div>
          <span class="cap-label cap-error-text">{{ errorMsg || labels.error }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cap-card {
  width: 100%;
  border: 1px solid var(--td-border-level-1-color, rgba(0, 0, 0, 0.12));
  border-radius: var(--td-radius-default, 0);
  background: var(--td-bg-color-container, rgba(0, 0, 0, 0.02));
  overflow: hidden;
}

/* aria-live 播报节点：视觉隐藏但保留给辅助技术朗读 */
.cap-sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.cap-card.cap-floating {
  position: fixed;
  top: 24px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 3000;
  width: 320px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
}

.cap-body {
  display: flex;
  align-items: center;
  height: 44px;
  padding: 0 14px;
  box-sizing: border-box;
}

.cap-clickable {
  cursor: pointer;
}

.cap-content {
  position: relative;
  width: 100%;
  height: 20px;
}

.cap-state {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  opacity: 0;
  transform: scale(0.92);
  transition: opacity 0.22s ease, transform 0.22s ease;
  pointer-events: none;
  will-change: transform, opacity;
}

.cap-state.cap-active {
  opacity: 1;
  transform: scale(1);
  pointer-events: auto;
}

.cap-checkbox {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  border-radius: var(--td-radius-small, 2px);
  border: 2px solid var(--td-text-color-primary, #333);
  background: transparent;
  transition: border-color 0.2s;
}

.cap-clickable:hover .cap-checkbox {
  border-color: var(--td-brand-color, #1f5eff);
}

.cap-spinner {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  color: var(--td-brand-color, #1f5eff);
  animation: cap-rotate 0.8s linear infinite;
}

@keyframes cap-rotate {
  to {
    transform: rotate(360deg);
  }
}

.cap-icon {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
}

.cap-ok {
  color: var(--td-success-color, #12b76a);
  background: var(--td-success-color-light, rgba(18, 183, 106, 0.1));
  border: 1px solid var(--td-success-color, #12b76a);
}

.cap-fail {
  color: var(--td-error-color, #e5484d);
  background: var(--td-error-color-light, rgba(229, 72, 77, 0.1));
  border: 1px solid var(--td-error-color, #e5484d);
}

.cap-label {
  flex: 1;
  font-size: 13px;
  color: var(--td-text-color-primary, #333);
  line-height: 1.3;
}

.cap-pct {
  color: var(--td-brand-color, #1f5eff);
  font-variant-numeric: tabular-nums;
}

.cap-error-text {
  color: var(--td-error-color, #e5484d);
}
</style>
