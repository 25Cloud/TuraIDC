<template>
  <section class="ai-confirm-card" :class="{ 'ai-confirm-card--dangerous': action.risk === 'dangerous' }">
    <header class="ai-confirm-card__head">
      <span class="ai-confirm-card__eyebrow">{{ $t('ai.confirm.title') }}</span>
      <t-tag size="small" variant="light" :theme="riskTagTheme">{{ $t(`ai.tool.risk.${action.risk}`) }}</t-tag>
    </header>

    <p class="ai-confirm-card__title">{{ action.title }}</p>
    <p v-if="action.description" class="ai-confirm-card__desc">{{ action.description }}</p>

    <dl class="ai-confirm-card__meta">
      <div v-if="action.arguments_summary">
        <dt>{{ $t('ai.confirm.arguments') }}</dt>
        <dd>{{ action.arguments_summary }}</dd>
      </div>
      <div v-if="action.impact">
        <dt>{{ $t('ai.confirm.impact') }}</dt>
        <dd>{{ action.impact }}</dd>
      </div>
      <div v-if="amountText">
        <dt>{{ $t('ai.confirm.amount') }}</dt>
        <dd class="ai-confirm-card__amount">{{ $t('ai.confirm.amountValue', { amount: amountText }) }}</dd>
      </div>
      <div>
        <dt>{{ $t('ai.tool.name') }}</dt>
        <dd>
          <code>{{ action.tool }}</code>
        </dd>
      </div>
    </dl>

    <p class="ai-confirm-card__expires">{{ $t('ai.confirm.expiresAt', { time: expiresText }) }}</p>

    <footer class="ai-confirm-card__actions">
      <t-button
        class="ai-confirm-card__btn"
        theme="primary"
        :loading="submitting"
        :disabled="expired"
        @click="handleConfirm"
      >
        {{ $t('ai.confirm.confirm') }}
      </t-button>
      <t-button class="ai-confirm-card__btn" variant="outline" :disabled="submitting" @click="handleReject">
        {{ $t('ai.confirm.cancel') }}
      </t-button>
      <span v-if="expired" class="ai-confirm-card__hint">{{ $t('ai.confirm.expired') }}</span>
    </footer>
  </section>
</template>
<script setup lang="ts">
import type { AiPendingAction } from '@turaidc/shared/runtime';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { useAiStore } from '@/store/modules/ai';
import { formatMoney } from '@/utils/format';

const props = defineProps<{ action: AiPendingAction }>();

// 确认/拒绝必须回到共享状态机的 confirmAction/rejectAction：
// 前端只提交 confirm_token，真正的执行与幂等判重都在后端。
const store = useAiStore();

const submitting = ref(false);
const now = ref(Date.now());

// 过期与否是时间函数，挂个低频计时器让按钮状态自己跟上，不必等用户操作才刷新
let tickTimer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
  tickTimer = setInterval(() => {
    now.value = Date.now();
  }, 30_000);
});
onBeforeUnmount(() => {
  if (tickTimer) {
    clearInterval(tickTimer);
  }
});

/** 后端 expires_at 是 ISO 8601（带时区），这里统一按本地时区展示 */
const parseTime = (value: string): number => Date.parse(String(value || '').replace(' ', 'T'));

const formatTime = (value: string): string => {
  const time = parseTime(value);
  if (!Number.isFinite(time)) {
    return String(value || '--');
  }
  const date = new Date(time);
  const pad = (num: number) => String(num).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(
    date.getMinutes(),
  )}`;
};

const expiresText = computed(() => formatTime(props.action.expires_at));
const expired = computed(() => {
  const time = parseTime(props.action.expires_at);
  return Number.isFinite(time) && time <= now.value;
});

/** 金额按分存储，展示时转元 */
const amountText = computed(() =>
  props.action.amount_cents === null || props.action.amount_cents === undefined
    ? ''
    : formatMoney(props.action.amount_cents / 100),
);

type TagTheme = 'primary' | 'warning' | 'danger';
const riskTagTheme = computed<TagTheme>(() => {
  if (props.action.risk === 'dangerous') {
    return 'danger';
  }
  return props.action.risk === 'write' ? 'warning' : 'primary';
});

const handleConfirm = async (): Promise<void> => {
  if (submitting.value || expired.value) {
    return;
  }
  submitting.value = true;
  try {
    await store.confirmAction(props.action);
  } finally {
    submitting.value = false;
  }
};

const handleReject = async (): Promise<void> => {
  if (submitting.value) {
    return;
  }
  submitting.value = true;
  try {
    await store.rejectAction(props.action);
  } finally {
    submitting.value = false;
  }
};
</script>
<style scoped lang="less">
.ai-confirm-card {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  margin-top: var(--td-comp-margin-s);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-warning-color-3);
  border-radius: var(--td-radius-medium);
}

.ai-confirm-card--dangerous {
  border-color: var(--td-error-color-3);
}

.ai-confirm-card__head {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: space-between;
}

.ai-confirm-card__eyebrow {
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-confirm-card__title {
  margin: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
}

.ai-confirm-card__desc {
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.ai-confirm-card__meta {
  display: grid;
  gap: var(--td-comp-margin-xs);
  margin: 0;
  font: var(--td-font-body-small);

  div {
    display: flex;
    gap: var(--td-comp-margin-s);
    align-items: flex-start;
  }

  dt {
    flex: none;
    min-width: 3.5rem;
    color: var(--td-text-color-placeholder);
  }

  dd {
    margin: 0;
    color: var(--td-text-color-primary);
    overflow-wrap: anywhere;
    white-space: pre-wrap;
  }

  code {
    font-family: ui-monospace, menlo, consolas, monospace;
  }
}

.ai-confirm-card__amount {
  color: var(--td-warning-color);
  font-weight: 600;
}

.ai-confirm-card__expires {
  margin: 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-confirm-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.ai-confirm-card__btn {
  height: 2rem;
  min-width: 5rem;
  padding: 0 14px;
}

.ai-confirm-card__hint {
  color: var(--td-error-color);
  font: var(--td-font-body-small);
}
</style>
