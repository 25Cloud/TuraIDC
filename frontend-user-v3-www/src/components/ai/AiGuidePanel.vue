<template>
  <el-drawer
    :model-value="modelValue"
    :direction="drawerDirection"
    :size="drawerSize"
    :with-header="false"
    :z-index="3400"
    :append-to-body="true"
    class="ai-guide-drawer"
    @update:model-value="handleVisibleChange"
  >
    <div class="ai-panel">
      <header class="ai-panel__head">
        <div class="ai-panel__titles">
          <strong class="ai-panel__title">{{ $t("ai.panel.title") }}</strong>
          <span v-if="modelLabel" class="ai-panel__model">{{ modelLabel }}</span>
        </div>
        <div class="ai-panel__head-actions">
          <el-button text size="small" :disabled="streaming" @click="handleNewConversation">
            {{ $t("ai.panel.newConversation") }}
          </el-button>
          <el-button text size="small" @click="close">
            {{ $t("ai.panel.close") }}
          </el-button>
        </div>
      </header>

      <div class="ai-panel__meta">
        <span v-if="subjectLabel" class="ai-panel__subject">
          {{ $t("ai.panel.subject", { label: subjectLabel }) }}
        </span>
        <el-select
          v-if="modeOptions.length > 1"
          class="ai-panel__mode"
          size="small"
          :model-value="permissionMode"
          @change="handleModeChange"
        >
          <el-option
            v-for="mode in modeOptions"
            :key="mode.value"
            :label="mode.label"
            :value="mode.value"
          />
        </el-select>
      </div>

      <el-alert
        v-if="ready && !enabled"
        class="ai-panel__alert"
        type="info"
        :title="$t('ai.panel.disabled')"
        :closable="false"
        show-icon
      />
      <el-alert
        v-else-if="error && !streaming"
        class="ai-panel__alert"
        type="error"
        :title="error"
        :closable="false"
        show-icon
      />

      <AiGuideMessages
        :messages="messages"
        :loading="loading"
        :confirm-handler="chat.confirmAction"
        :reject-handler="chat.rejectAction"
      />

      <footer class="ai-panel__composer">
        <el-input
          v-model="draft"
          class="ai-panel__input"
          type="textarea"
          resize="none"
          :autosize="{ minRows: 2, maxRows: 5 }"
          :disabled="!enabled"
          :placeholder="$t('ai.panel.placeholder')"
          @keydown.enter.exact.prevent="handleSend"
        />
        <div class="ai-panel__composer-actions">
          <span class="ai-panel__quota">{{ quotaText }}</span>
          <el-button v-if="streaming" size="small" @click="handleStop">
            {{ $t("ai.panel.stop") }}
          </el-button>
          <el-button
            v-else
            size="small"
            type="primary"
            :loading="sending"
            :disabled="!canSend || !draft.trim()"
            @click="handleSend"
          >
            {{ $t("ai.panel.send") }}
          </el-button>
        </div>
      </footer>
    </div>
  </el-drawer>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from "vue";

import { describeSubject } from "@turaidc/shared/runtime";
import { t } from "@/locales";

import AiGuideMessages from "./AiGuideMessages.vue";

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false,
  },
  /** useAiChat 的返回值：面板只读它的状态并调用它的动作，不自己持有会话 */
  chat: {
    type: Object,
    required: true,
  },
});

const emit = defineEmits(["update:modelValue"]);

const draft = ref("");

/**
 * chat 是「普通对象里装着 ref」的组合式返回值，模板里直接写 chat.messages
 * 只会拿到 Ref 对象本身，所以这里统一解成 computed，模板才能正常渲染。
 */
const messages = computed(() => props.chat.messages.value);
const loading = computed(() => props.chat.loading.value);
const streaming = computed(() => props.chat.streaming.value);
const sending = computed(() => props.chat.sending.value);
const error = computed(() => props.chat.error.value);
const ready = computed(() => props.chat.ready.value);
const enabled = computed(() => props.chat.enabled.value);
const canSend = computed(() => props.chat.canSend.value);
const quota = computed(() => props.chat.quota.value);
const permissionMode = computed(() => props.chat.permissionMode.value);
const allowedModes = computed(() => props.chat.allowedModes.value);
const modelLabel = computed(() => props.chat.bootstrap.value?.model_label || "");
const subjectLabel = computed(() => describeSubject(props.chat.subject.value));

const MODE_KEYS = {
  read_only: "ai.mode.readOnly",
  confirm: "ai.mode.confirm",
  auto_approve: "ai.mode.autoApprove",
};

const modeOptions = computed(() =>
  allowedModes.value.map((mode) => ({ value: mode, label: t(MODE_KEYS[mode] || "ai.mode.confirm") })),
);

// 额度按「已用/上限」展示；上限为 0 表示站点不限量
const quotaText = computed(() => {
  const value = quota.value;
  if (!value) {
    return "";
  }

  if (!value.daily_messages) {
    return t("ai.panel.quotaUnlimited", { used: value.used_messages });
  }

  return t("ai.panel.quota", {
    used: value.used_messages,
    limit: value.daily_messages,
  });
});

// 移动端走底部抽屉，桌面端走右侧 400px 面板
const isMobile = ref(false);
const mediaQuery =
  typeof window !== "undefined" ? window.matchMedia("(max-width: 767px)") : null;

function handleMediaChange(event) {
  isMobile.value = Boolean(event.matches);
}

if (mediaQuery) {
  isMobile.value = mediaQuery.matches;
  mediaQuery.addEventListener?.("change", handleMediaChange);
}

onBeforeUnmount(() => {
  // matchMedia 的监听活到页面结束，组件卸载时不摘会在热更新后重复触发
  mediaQuery?.removeEventListener?.("change", handleMediaChange);
});

const drawerDirection = computed(() => (isMobile.value ? "btt" : "rtl"));
const drawerSize = computed(() => (isMobile.value ? "82%" : "400px"));

function handleVisibleChange(visible) {
  emit("update:modelValue", visible);
}

function close() {
  emit("update:modelValue", false);
}

function handleNewConversation() {
  draft.value = "";
  props.chat.startNewConversation();
}

function handleModeChange(mode) {
  void props.chat.setPermissionMode(mode);
}

async function handleSend() {
  const text = draft.value.trim();
  if (!text || !canSend.value) {
    return;
  }

  draft.value = "";
  await props.chat.send(text);
}

function handleStop() {
  props.chat.stop();
}
</script>

<style scoped lang="scss">
.ai-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: $bg-color-card;
}

.ai-panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 12px 14px;
  border-bottom: 1px solid $divider-color;
  flex: none;
}

.ai-panel__titles {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.ai-panel__title {
  font-size: 14px;
  font-weight: 600;
  color: $text-color-primary;
}

.ai-panel__model {
  font-size: 11px;
  color: $text-color-placeholder;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel__head-actions {
  display: flex;
  align-items: center;
  gap: 2px;
  flex: none;
}

.ai-panel__meta {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px 0;
  flex: none;
}

.ai-panel__subject {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  color: $text-color-secondary;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel__mode {
  width: 132px;
  flex: none;
}

.ai-panel__alert {
  margin: 10px 14px 0;
  flex: none;
}

.ai-panel__composer {
  flex: none;
  padding: 10px 14px 14px;
  border-top: 1px solid $divider-color;
  background: $bg-color-card;
}

.ai-panel__input {
  :deep(.el-textarea__inner) {
    font-size: 13px;
    line-height: 1.6;
  }
}

.ai-panel__composer-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

.ai-panel__quota {
  flex: 1;
  min-width: 0;
  font-size: 11px;
  color: $text-color-placeholder;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>

<style lang="scss">
/* 抽屉本体来自 Element Plus，scoped 选不到，只能在这里收敛内边距并撑满高度 */
.ai-guide-drawer .el-drawer__body {
  padding: 0;
  overflow: hidden;
}

@media (max-width: 767px) {
  .ai-guide-drawer .el-drawer {
    border-radius: 16px 16px 0 0;
  }
}
</style>
