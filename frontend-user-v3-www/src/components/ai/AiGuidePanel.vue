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
          <AiRobotAvatar :size="18" class="ai-panel__logo" />
          <span class="ai-panel__title">{{ $t("ai.panel.title") }}</span>
        </div>
        <div class="ai-panel__head-actions">
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
          <button
            type="button"
            class="ai-panel__icon-btn"
            :aria-label="$t('ai.panel.newConversation')"
            :title="$t('ai.panel.newConversation')"
            :disabled="streaming"
            @click="handleNewConversation"
          >
            <el-icon><Plus /></el-icon>
          </button>
          <button
            type="button"
            class="ai-panel__icon-btn"
            :aria-label="$t('ai.panel.close')"
            :title="$t('ai.panel.close')"
            @click="close"
          >
            <el-icon><Close /></el-icon>
          </button>
        </div>
      </header>

      <!-- 第二行：模型名 / 当前页面上下文；面板窄到这个宽度以下时模型名整行省略，绝不挤压标题 -->
      <p v-if="modelLabel || subjectLabel" class="ai-panel__context">
        <span v-if="modelLabel" class="ai-panel__context-model">{{ modelLabel }}</span>
        <span v-if="modelLabel && subjectLabel" class="ai-panel__context-sep" aria-hidden="true">·</span>
        <span v-if="subjectLabel" class="ai-panel__context-subject">
          {{ $t("ai.panel.subject", { label: subjectLabel }) }}
        </span>
      </p>

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
        :suggest-handler="handleSuggest"
      />

      <footer class="ai-panel__composer">
        <!-- 输入区：textarea 与发送按钮同一行，按钮高 32px、宽 ≥80px -->
        <div class="ai-panel__composer-field">
          <el-input
            v-model="draft"
            class="ai-panel__input"
            type="textarea"
            resize="none"
            :autosize="{ minRows: 1, maxRows: 6 }"
            :disabled="!enabled"
            :placeholder="$t('ai.panel.placeholder')"
            @keydown.enter.exact.prevent="handleSend"
          />
          <el-button
            v-if="streaming"
            class="ai-panel__send"
            @click="handleStop"
          >
            {{ $t("ai.panel.stop") }}
          </el-button>
          <el-button
            v-else
            class="ai-panel__send"
            type="primary"
            :loading="sending"
            :disabled="!canSend || !draft.trim()"
            @click="handleSend"
          >
            {{ $t("ai.panel.send") }}
          </el-button>
        </div>
        <div v-if="quotaText" class="ai-panel__composer-meta">
          <span class="ai-panel__quota">{{ quotaText }}</span>
        </div>
      </footer>
    </section>
  </transition>
</template>

<script setup>
import { Close, Plus } from "@element-plus/icons-vue";
import { describeSubject } from "@turaidc/shared/runtime";
import { computed, onBeforeUnmount, ref, watch } from "vue";

import { t } from "@/locales";

import AiGuideMessages from "./AiGuideMessages.vue";
import AiRobotAvatar from "./AiRobotAvatar.vue";

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

/** 空态快捷问题：填进输入框再走同一条发送路径，不另开一条发送分支 */
async function handleSuggest(text) {
  if (!canSend.value) {
    return;
  }

  draft.value = String(text ?? "");
  await handleSend();
}

function handleStop() {
  props.chat.stop();
}
</script>

<style scoped lang="scss">
/*
 * 面板几何与三端统一：桌面右下角 1.5rem、宽 400px、高 min(620px, 100vh - 96px)。
 * 浮球在打开时隐藏，两者共用同一个角落，因此这里直接锚在 1.5rem 上。
 */
.ai-panel {
  position: fixed;
  right: 24px;
  bottom: calc(24px + env(safe-area-inset-bottom, 0px));
  z-index: 3400;
  display: flex;
  flex-direction: column;
  width: 400px;
  height: min(620px, calc(100vh - 96px));
  overflow: hidden;
  background: var(--td-bg-color-container, var(--el-bg-color));
  border: 1px solid var(--td-component-stroke, var(--el-border-color-light));
  border-radius: var(--td-radius-large, 12px);
  box-shadow: var(--td-shadow-3, var(--el-box-shadow));
}

.ai-panel__head {
  display: flex;
  flex: none;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 12px 10px 16px;
  border-bottom: 1px solid var(--td-component-stroke, var(--el-border-color-lighter));
}

.ai-panel__identity {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.ai-panel__logo {
  color: var(--td-brand-color, var(--el-color-primary));
}

.ai-panel__title {
  overflow: hidden;
  font-size: 14px;
  font-weight: 500;
  line-height: 1.4;
  color: var(--td-text-color-primary, var(--el-text-color-primary));
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel__head-actions {
  display: flex;
  flex: none;
  align-items: center;
  gap: 6px;
}

/* 权限下拉最小 96px：「自动审批（beta）」比原来的定宽更长，用 min-width 而不是固定宽 */
.ai-panel__mode {
  flex: none;
  width: 128px;
  min-width: 96px;
  max-width: 148px;
}

/*
 * 头部图标按钮：视觉 28px，命中区由 ::after 补到 32px，
 * 与三端「所有可点控件 ≥32×32」的口径一致。
 */
.ai-panel__icon-btn {
  position: relative;
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  font-size: 1rem;
  line-height: 1;
  color: var(--td-text-color-secondary, var(--el-text-color-regular));
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: var(--td-radius-default, 4px);
  transition:
    color 0.15s ease,
    background-color 0.15s ease;

  &::after {
    position: absolute;
    inset: -2px;
    content: "";
    border-radius: inherit;
  }

  &:hover {
    color: var(--td-text-color-primary, var(--el-text-color-primary));
    background: var(--td-bg-color-container-hover, var(--el-fill-color-light));
  }

  &:disabled {
    cursor: not-allowed;
    opacity: 0.5;
  }

  &:focus-visible {
    outline: 2px solid var(--td-brand-color-focus, var(--el-color-primary-light-5));
    outline-offset: 1px;
  }
}

/* 第二行上下文：模型名 + 当前对象，窄面板整行省略模型名 */
.ai-panel__context {
  display: flex;
  flex: none;
  align-items: center;
  gap: 4px;
  min-width: 0;
  margin: 0;
  padding: 6px 16px 8px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--td-text-color-placeholder, var(--el-text-color-placeholder));
}

.ai-panel__context-model,
.ai-panel__context-subject {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel__context-model {
  color: var(--td-text-color-secondary, var(--el-text-color-regular));
}

.ai-panel__context-sep {
  flex: none;
}

.ai-panel__alert {
  flex: none;
  margin: 12px 16px 0;
}

.ai-panel__composer {
  flex: none;
  padding: 12px 16px;
  background: var(--td-bg-color-container, var(--el-bg-color));
  border-top: 1px solid var(--td-component-stroke, var(--el-border-color-lighter));
}

/* 输入区与发送按钮同一行：textarea 吃满剩余宽度，按钮固定 32px 高 */
.ai-panel__composer-field {
  display: flex;
  flex-wrap: nowrap;
  align-items: flex-end;
  gap: 8px;
  min-width: 0;
}

.ai-panel__input {
  flex: 1 1 auto;
  min-width: 0;

  :deep(.el-textarea__inner) {
    min-height: 40px;
    max-height: 140px;
    padding: 9px 12px;
    overflow-y: auto;
    font-size: 13px;
    line-height: 1.7;
    border-radius: var(--td-radius-default, 6px);
  }
}

.ai-panel__send {
  flex: 0 0 auto;
  min-width: 80px;
  height: 32px;
  padding: 0 14px;
}

.ai-panel__composer-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 1rem;
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.4;
  color: var(--td-text-color-placeholder, var(--el-text-color-placeholder));
}

.ai-panel__quota {
  overflow: hidden;
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

@media (max-width: 1024px) {
  .ai-panel {
    width: 360px;
  }
}

@media (max-width: 767px) {
  .ai-panel {
    right: 0;
    left: 0;
    bottom: 0;
    width: auto;
    height: 100%;
    border: none;
    border-radius: 0;
  }

  /* 全屏面板要自己避让刘海与手势条 */
  .ai-panel__head {
    padding-top: calc(10px + env(safe-area-inset-top, 0px));
  }

  .ai-panel__composer {
    padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
  }

  .ai-panel__head-actions {
    gap: 4px;
  }
}
</style>
