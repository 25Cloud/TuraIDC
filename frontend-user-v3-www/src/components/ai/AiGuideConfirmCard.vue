<template>
  <div class="ai-confirm" :class="`ai-confirm--${action.risk || 'write'}`">
    <div class="ai-confirm__head">
      <strong class="ai-confirm__title">{{ action.title }}</strong>
      <span class="ai-confirm__risk">{{ riskLabel }}</span>
    </div>

    <p v-if="action.description" class="ai-confirm__desc">{{ action.description }}</p>

    <code v-if="action.arguments_summary" class="ai-confirm__args">{{ action.arguments_summary }}</code>

    <div v-if="amountText || action.impact || remainText" class="ai-confirm__rows">
      <span v-if="amountText" class="ai-confirm__row ai-confirm__row--amount">
        <el-icon class="ai-confirm__row-icon"><Wallet /></el-icon>
        {{ $t("ai.confirm.amount", { amount: amountText }) }}
      </span>
      <span v-if="action.impact" class="ai-confirm__row">
        <el-icon class="ai-confirm__row-icon"><Warning /></el-icon>
        {{ $t("ai.confirm.impact", { impact: action.impact }) }}
      </span>
      <span v-if="remainText" class="ai-confirm__row" :class="{ 'ai-confirm__row--expired': expired }">
        <el-icon class="ai-confirm__row-icon"><Clock /></el-icon>
        {{ remainText }}
      </span>
    </div>

    <div class="ai-confirm__actions">
      <el-button size="small" :disabled="busy" @click="handleReject">
        {{ $t("ai.confirm.reject") }}
      </el-button>
      <el-button size="small" type="primary" :loading="busy" @click="handleConfirm">
        {{ $t("ai.confirm.confirm") }}
      </el-button>
    </div>
  </div>
</template>

<script setup>
import { Clock, Wallet, Warning } from "@element-plus/icons-vue";
import { computed, onBeforeUnmount, ref } from "vue";

import { t } from "@/locales";

const props = defineProps({
  action: {
    type: Object,
    required: true,
  },
  /** 由面板传入 chat.confirmAction：返回 Promise<boolean>，卡片只管忙碌态 */
  confirmHandler: {
    type: Function,
    required: true,
  },
  /** 由面板传入 chat.rejectAction */
  rejectHandler: {
    type: Function,
    required: true,
  },
});

// 确认/拒绝都等后端返回，期间锁住两个按钮，避免连点造成重复确认
const busy = ref(false);

const RISK_KEYS = {
  read: "ai.risk.read",
  write: "ai.risk.write",
  dangerous: "ai.risk.dangerous",
};

const riskLabel = computed(() => t(RISK_KEYS[props.action?.risk] || "ai.risk.write"));

// 金额单位是分，后端只在涉及花钱的动作上给 amount_cents
const amountText = computed(() => {
  const cents = Number(props.action?.amount_cents);
  if (!Number.isFinite(cents)) {
    return "";
  }

  return (cents / 100).toFixed(2);
});

// 确认令牌有效期很短（默认 5 分钟）：面板可能一直开着，所以每 30 秒重算一次剩余
const now = ref(Date.now());
const timer = window.setInterval(() => {
  now.value = Date.now();
}, 30000);

onBeforeUnmount(() => {
  window.clearInterval(timer);
});

const remainMs = computed(() => {
  const expiresAt = props.action?.expires_at;
  if (!expiresAt) {
    return null;
  }

  const value = new Date(expiresAt).getTime() - now.value;

  return Number.isFinite(value) ? value : null;
});

const expired = computed(() => remainMs.value !== null && remainMs.value <= 0);

const remainText = computed(() => {
  const value = remainMs.value;
  if (value === null) {
    return "";
  }

  if (value <= 0) {
    return t("ai.confirm.expired");
  }

  return t("ai.confirm.expiresIn", {
    minutes: Math.max(1, Math.round(value / 60000)),
  });
});

async function handleConfirm() {
  if (busy.value) {
    return;
  }

  busy.value = true;
  try {
    // 真正的令牌/归属/幂等校验都在后端，前端只把动作回传，不自己执行业务
    await props.confirmHandler(props.action);
  } finally {
    busy.value = false;
  }
}

async function handleReject() {
  if (busy.value) {
    return;
  }

  busy.value = true;
  try {
    await props.rejectHandler(props.action);
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped lang="scss">
.ai-confirm {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 16px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color);
  border-radius: 8px;
}

.ai-confirm__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
}

.ai-confirm__title {
  font-size: 13px;
  font-weight: 600;
  line-height: 1.5;
  color: var(--el-text-color-primary);
  overflow-wrap: anywhere;
}

/* 风险等级用 Element 语义色，读一眼就知道这条要不要点 */
.ai-confirm__risk {
  flex: none;
  padding: 0 8px;
  font-size: 11px;
  line-height: 18px;
  color: var(--el-color-warning);
  background: var(--el-color-warning-light-9);
  border: 1px solid var(--el-color-warning-light-8);
  border-radius: 8px;
}

.ai-confirm--read .ai-confirm__risk {
  color: var(--el-color-info);
  background: var(--el-color-info-light-9);
  border-color: var(--el-color-info-light-8);
}

.ai-confirm--dangerous .ai-confirm__risk {
  color: var(--el-color-danger);
  background: var(--el-color-danger-light-9);
  border-color: var(--el-color-danger-light-8);
}

.ai-confirm__desc {
  margin: 0;
  font-size: 12px;
  line-height: 1.7;
  color: var(--el-text-color-regular);
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.ai-confirm__args {
  display: block;
  padding: 8px 12px;
  overflow-x: auto;
  font-family: ui-monospace, sfmono-regular, menlo, consolas, monospace;
  font-size: 11px;
  line-height: 1.6;
  color: var(--el-text-color-primary);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  background: var(--el-fill-color-light);
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
}

.ai-confirm__rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.ai-confirm__row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 12px;
  line-height: 1.7;
  color: var(--el-text-color-regular);
  overflow-wrap: anywhere;
}

.ai-confirm__row-icon {
  flex: none;
  margin-top: 3px;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
}

.ai-confirm__row--amount {
  font-weight: 600;
  color: var(--el-color-danger);
  font-variant-numeric: tabular-nums;
}

.ai-confirm__row--amount .ai-confirm__row-icon {
  color: var(--el-color-danger);
}

.ai-confirm__row--expired {
  color: var(--el-color-danger);
}

.ai-confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 4px;
}
</style>
