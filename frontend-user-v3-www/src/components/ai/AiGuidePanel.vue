<template>
  <transition :name="isMobile ? 'ai-panel-up' : 'ai-panel-fade'">
    <section
      v-if="modelValue"
      ref="panelEl"
      class="ai-panel"
      role="dialog"
      :aria-modal="isMobile ? 'true' : 'false'"
      :aria-label="$t('ai.panel.title')"
    >
      <header class="ai-panel__head">
        <div class="ai-panel__identity">
          <span class="ai-panel__avatar" aria-hidden="true">
            <el-icon><Service /></el-icon>
          </span>
          <div class="ai-panel__titles">
            <strong class="ai-panel__title">{{ $t("ai.panel.title") }}</strong>
            <span v-if="modelLabel" class="ai-panel__model">{{ modelLabel }}</span>
          </div>
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

      <div v-if="subjectLabel || modeOptions.length > 1" class="ai-panel__meta">
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
    </section>
  </transition>
</template>

<script setup>
import { Service } from "@element-plus/icons-vue";
import { describeSubject } from "@turaidc/shared/runtime";
import { computed, onBeforeUnmount, ref, watch } from "vue";

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
const panelEl = ref(null);

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

/**
 * 断点只在这里判一次：
 * - 桌面：右下角锚在浮球上方的浮动卡片（400px 宽，最大 640px 高），不遮页面、不抢滚动
 * - 移动：铺满整个视口，避免在 400px 的卡片里塞输入框和小键盘
 */
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

// 打开时锁住页面滚动（移动端全屏面板尤其需要），关闭/卸载时恢复原值
let lockedBodyOverflow = "";

function lockScroll() {
  if (typeof document === "undefined") {
    return;
  }

  lockedBodyOverflow = document.body.style.overflow;
  document.body.style.overflow = "hidden";
}

function unlockScroll() {
  if (typeof document === "undefined") {
    return;
  }

  document.body.style.overflow = lockedBodyOverflow;
  lockedBodyOverflow = "";
}

function handleKeydown(event) {
  if (event.key === "Escape") {
    close();
  }
}

function handleOutsidePointer(event) {
  const target = event.target;
  if (!(target instanceof Node)) {
    return;
  }

  if (panelEl.value?.contains(target)) {
    return;
  }

  // 浮球自己负责开合，这里放行，否则「点浮球关闭」会被一个立即重开的抖动吃掉
  if (target instanceof Element && target.closest("[data-ai-launcher]")) {
    return;
  }

  // 下拉框 / 弹层都 teleport 到 body，视觉上仍属于面板，不能被当成「点了外面」
  const inFloatingLayer =
    target instanceof Element &&
    target.closest(".el-popper, .el-overlay, .el-message, .el-message-box");
  if (inFloatingLayer) {
    return;
  }

  close();
}

function detachListeners() {
  if (typeof window === "undefined") {
    return;
  }

  window.removeEventListener("keydown", handleKeydown);
  document.removeEventListener("pointerdown", handleOutsidePointer);
}

function attachListeners() {
  detachListeners();
  window.addEventListener("keydown", handleKeydown);

  if (isMobile.value) {
    lockScroll();
    return;
  }

  unlockScroll();
  document.addEventListener("pointerdown", handleOutsidePointer);
}

watch(
  [() => props.modelValue, isMobile],
  ([open]) => {
    if (open) {
      attachListeners();
      return;
    }

    detachListeners();
    unlockScroll();
  },
  { flush: "post" },
);

onBeforeUnmount(() => {
  // matchMedia 的监听活到页面结束，组件卸载时不摘会在热更新后重复触发
  mediaQuery?.removeEventListener?.("change", handleMediaChange);
  detachListeners();
  unlockScroll();
});

function handleVisibleChange(visible) {
  emit("update:modelValue", visible);
}

function close() {
  handleVisibleChange(false);
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
  position: fixed;
  right: 24px;
  /* 浮球 48px + 28px 底距 + 12px 间隙 */
  bottom: calc(28px + 48px + 12px);
  z-index: 3400;
  display: flex;
  flex-direction: column;
  width: 400px;
  max-height: min(640px, calc(100vh - 96px));
  overflow: hidden;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
  box-shadow: var(--el-box-shadow);
}

.ai-panel__head {
  display: flex;
  flex: none;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--el-border-color-light);
}

.ai-panel__identity {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.ai-panel__avatar {
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  color: var(--el-color-primary);
  font-size: 16px;
  background: var(--el-color-primary-light-9);
  border-radius: 50%;
}

.ai-panel__titles {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.ai-panel__title {
  font-size: 14px;
  font-weight: 600;
  line-height: 1.4;
  color: var(--el-text-color-primary);
}

.ai-panel__model {
  overflow: hidden;
  font-size: 11px;
  line-height: 1.4;
  color: var(--el-text-color-placeholder);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel__head-actions {
  display: flex;
  flex: none;
  align-items: center;
  gap: 8px;
}

.ai-panel__meta {
  display: flex;
  flex: none;
  align-items: center;
  gap: 8px;
  padding: 8px 16px 0;
}

.ai-panel__subject {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  font-size: 12px;
  color: var(--el-text-color-regular);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel__mode {
  flex: none;
  width: 132px;
}

.ai-panel__alert {
  flex: none;
  margin: 12px 16px 0;
}

.ai-panel__composer {
  flex: none;
  padding: 12px 16px 16px;
  background: var(--el-bg-color);
  border-top: 1px solid var(--el-border-color-light);
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
  overflow: hidden;
  font-size: 11px;
  color: var(--el-text-color-placeholder);
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 桌面：从浮球上方淡入，不锁页面滚动 */
.ai-panel-fade-enter-active,
.ai-panel-fade-leave-active {
  transition:
    opacity 0.16s ease,
    transform 0.16s ease;
}

.ai-panel-fade-enter-from,
.ai-panel-fade-leave-to {
  opacity: 0;
  transform: translateY(8px) scale(0.98);
}

/* 移动：自底部整屏推入，沿用原抽屉的观感 */
.ai-panel-up-enter-active,
.ai-panel-up-leave-active {
  transition: transform 0.22s ease;
}

.ai-panel-up-enter-from,
.ai-panel-up-leave-to {
  transform: translateY(100%);
}

@media (max-width: 767px) {
  .ai-panel {
    inset: 0;
    width: auto;
    height: 100%;
    max-height: none;
    border: none;
    border-radius: 0;
  }

  /* 全屏面板要自己避让刘海与手势条 */
  .ai-panel__head {
    padding-top: calc(12px + env(safe-area-inset-top, 0px));
  }

  .ai-panel__composer {
    padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
  }

  .ai-panel__head-actions {
    gap: 4px;
  }
}
</style>
