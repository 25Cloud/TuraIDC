<template>
  <div class="ai-confirm">
    <div class="ai-confirm__head">
      <strong class="ai-confirm__title">{{ action.title }}</strong>
      <span class="ai-confirm__risk">{{ riskLabel }}</span>
    </div>

    <p v-if="action.description" class="ai-confirm__desc">
      {{ action.description }}
    </p>
    <p v-if="action.arguments_summary" class="ai-confirm__args">
      {{ action.arguments_summary }}
    </p>
    <p v-if="amountText" class="ai-confirm__amount">
      {{ $t("ai.confirm.amount", { amount: amountText }) }}
    </p>
    <p v-if="action.impact" class="ai-confirm__impact">
      {{ $t("ai.confirm.impact", { impact: action.impact }) }}
    </p>
    <p v-if="remainText" class="ai-confirm__expires">
      {{ remainText }}
    </p>

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
import { computed, ref } from "vue";

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

// 确认令牌有效期很短（默认 5 分钟），这里只提示还剩几分钟，不做倒计时刷新
const remainText = computed(() => {
  const expiresAt = props.action?.expires_at;
  if (!expiresAt) {
    return "";
  }

  const remainMs = new Date(expiresAt).getTime() - Date.now();
  if (!Number.isFinite(remainMs)) {
    return "";
  }

  if (remainMs <= 0) {
    return t("ai.confirm.expired");
  }

  return t("ai.confirm.expiresIn", {
    minutes: Math.max(1, Math.round(remainMs / 60000)),
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
  margin-top: 10px;
  padding: 12px;
  border: 1px solid var(--el-color-primary-light-7, #c9dbff);
  border-radius: 10px;
  background: var(--el-color-primary-light-9, #f2f7ff);
}

.ai-confirm__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.ai-confirm__title {
  font-size: 13px;
  font-weight: 600;
  color: $text-color-primary;
}

.ai-confirm__risk {
  flex: none;
  padding: 1px 8px;
  border-radius: 10px;
  font-size: 11px;
  line-height: 18px;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9, #f2f7ff);
  border: 1px solid var(--el-color-primary-light-7, #c9dbff);
}

.ai-confirm__desc,
.ai-confirm__args,
.ai-confirm__amount,
.ai-confirm__impact,
.ai-confirm__expires {
  margin: 8px 0 0;
  font-size: 12px;
  line-height: 1.6;
  color: $text-color-secondary;
  word-break: break-word;
}

.ai-confirm__args {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: $text-color-primary;
}

.ai-confirm__amount {
  color: var(--el-color-danger, #f04438);
  font-weight: 600;
}

.ai-confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
}
</style>
