<template>
  <div ref="listEl" class="ai-messages" @scroll="handleScroll">
    <div v-if="loading" class="ai-messages__loading">
      <el-skeleton :rows="3" animated />
    </div>

    <p v-else-if="!messages.length" class="ai-messages__empty">
      {{ $t("ai.panel.empty") }}
    </p>

    <div
      v-for="message in messages"
      :key="message.id"
      class="ai-msg"
      :class="`ai-msg--${message.role}`"
    >
      <span class="ai-msg__role">{{ roleLabel(message.role) }}</span>

      <div class="ai-msg__bubble" :class="{ 'ai-msg__bubble--error': message.error }">
        <p v-if="message.content" class="ai-msg__text">{{ message.content }}</p>
        <span v-else-if="message.streaming" class="ai-msg__typing">
          {{ $t("ai.panel.thinking") }}
        </span>

        <div v-if="message.tool_runs?.length" class="ai-msg__tools">
          <span
            v-for="(run, index) in message.tool_runs"
            :key="`${message.id}-${run.name}-${index}`"
            class="ai-msg__tool"
          >
            <span class="ai-msg__tool-name">{{ run.name }}</span>
            <span v-if="run.summary" class="ai-msg__tool-summary">{{ run.summary }}</span>
          </span>
        </div>

        <AiGuideConfirmCard
          v-for="action in message.pending_actions"
          :key="action.id"
          :action="action"
          :confirm-handler="confirmHandler"
          :reject-handler="rejectHandler"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from "vue";

import { t } from "@/locales";

import AiGuideConfirmCard from "./AiGuideConfirmCard.vue";

const props = defineProps({
  messages: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  /** chat.confirmAction / chat.rejectAction 原样下传，确认卡自己管忙碌态 */
  confirmHandler: {
    type: Function,
    required: true,
  },
  rejectHandler: {
    type: Function,
    required: true,
  },
});

const listEl = ref(null);
// 用户往上翻历史时不要把他拽回底部；只有贴着底部才跟随最新内容
const stickToBottom = ref(true);

const ROLE_KEYS = {
  user: "ai.message.you",
  assistant: "ai.message.assistant",
  system: "ai.message.system",
};

function roleLabel(role) {
  return t(ROLE_KEYS[role] || "ai.message.system");
}

// 流式增量只改 content，用「长度 + 流式标记」做签名，比深监听整个数组便宜
const signature = computed(() =>
  props.messages
    .map((message) => `${message.id}:${(message.content || "").length}:${message.streaming ? 1 : 0}`)
    .join("|"),
);

function handleScroll() {
  const el = listEl.value;
  if (!el) {
    return;
  }

  stickToBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}

watch(signature, async () => {
  if (!stickToBottom.value) {
    return;
  }

  await nextTick();
  const el = listEl.value;
  if (el) {
    el.scrollTop = el.scrollHeight;
  }
});
</script>

<style scoped lang="scss">
.ai-messages {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 14px 16px;
  background: $bg-color-soft;
  overscroll-behavior: contain;
}

.ai-messages__loading {
  padding: 8px 4px;
}

.ai-messages__empty {
  margin: 24px 0;
  text-align: center;
  font-size: 13px;
  line-height: 1.7;
  color: $text-color-placeholder;
}

.ai-msg {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 14px;

  &--user {
    align-items: flex-end;
  }
}

.ai-msg__role {
  font-size: 11px;
  color: $text-color-placeholder;
}

.ai-msg__bubble {
  max-width: 88%;
  padding: 9px 12px;
  border-radius: 10px;
  background: $bg-color-card;
  border: 1px solid $divider-color;
  font-size: 13px;
  line-height: 1.7;
  color: $text-color-primary;

  &--error {
    border-color: var(--el-color-danger-light-5, #fbb4ae);
  }
}

.ai-msg--user .ai-msg__bubble {
  background: var(--el-color-primary-light-9, #f2f7ff);
  border-color: var(--el-color-primary-light-7, #c9dbff);
}

.ai-msg__text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.ai-msg__typing {
  font-size: 12px;
  color: $text-color-placeholder;
}

.ai-msg__tools {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.ai-msg__tool {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 100%;
  padding: 2px 8px;
  border-radius: 8px;
  background: $bg-color-soft;
  border: 1px solid $divider-color;
  font-size: 11px;
  color: $text-color-secondary;
}

.ai-msg__tool-name {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: var(--el-color-primary);
}

.ai-msg__tool-summary {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
