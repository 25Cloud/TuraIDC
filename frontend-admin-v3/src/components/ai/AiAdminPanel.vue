<template>
  <t-drawer
    v-model:visible="visibleModel"
    :size="drawerSize"
    :z-index="3400"
    :footer="true"
    :close-btn="false"
    drawer-class-name="ai-admin-chat-drawer"
  >
    <!-- 头部与三端统一：机器人头 18px + 标题，右侧权限下拉（最小 96px）+ 新对话 + 关闭 -->
    <template #header>
      <div class="ai-panel-header">
        <div class="ai-panel-header__row">
          <div class="ai-panel-header__identity">
            <ai-robot-avatar :size="18" class="ai-panel-header__logo" />
            <span class="ai-panel-header__title">{{ $t('ai.panel.title') }}</span>
          </div>

          <div class="ai-panel-header__tools">
            <t-select
              v-if="modeOptions.length > 1"
              class="ai-panel-header__mode"
              :model-value="permissionMode"
              @change="handleModeChange"
            >
              <t-option v-for="mode in modeOptions" :key="mode.value" :label="mode.label" :value="mode.value" />
            </t-select>

            <t-button
              class="ai-panel-header__icon-btn"
              shape="square"
              variant="text"
              :style="iconBtnStyle"
              :aria-label="$t('ai.panel.newConversation')"
              :title="$t('ai.panel.newConversation')"
              :disabled="streaming"
              @click="handleNewConversation"
            >
              <template #icon><add-icon /></template>
            </t-button>

            <t-button
              class="ai-panel-header__icon-btn"
              shape="square"
              variant="text"
              :style="iconBtnStyle"
              :aria-label="$t('ai.panel.close')"
              :title="$t('ai.panel.close')"
              @click="handleClose"
            >
              <template #icon><close-icon /></template>
            </t-button>
          </div>
        </div>

        <!-- 第二行：模型名 / 当前对象，窄面板下整行省略模型名 -->
        <p v-if="modelLabel || subjectLabel" class="ai-panel-header__context">
          <span v-if="modelLabel" class="ai-panel-header__model">{{ modelLabel }}</span>
          <span v-if="modelLabel && subjectLabel" class="ai-panel-header__sep" aria-hidden="true">·</span>
          <span v-if="subjectLabel" class="ai-panel-header__subject">
            {{ $t('ai.panel.subject', { label: subjectLabel }) }}
          </span>
        </p>
      </div>
    </template>

    <div class="ai-panel-body">
      <t-alert v-if="ready && !enabled" class="ai-panel-body__alert" theme="info" :message="$t('ai.panel.disabled')" />
      <t-alert v-else-if="error && !streaming" class="ai-panel-body__alert" theme="error" :message="error" />

      <div v-if="loading" class="ai-panel-body__loading">
        <t-loading size="small" />
      </div>

      <!-- 空态：机器人头 56px + 16px 标题 + 13px 灰色副标题，整块垂直居中 -->
      <div v-else-if="!messages.length" class="ai-panel-empty">
        <ai-robot-avatar :size="56" class="ai-panel-empty__icon" />
        <p class="ai-panel-empty__title">{{ $t('ai.empty.title') }}</p>
        <p class="ai-panel-empty__desc">{{ $t('ai.empty.description') }}</p>
      </div>

      <div v-else class="ai-panel-messages">
        <article v-for="message in messages" :key="message.id" class="ai-msg" :class="`ai-msg--${message.role}`">
          <span v-if="message.role === 'assistant'" class="ai-msg__avatar" aria-hidden="true">
            <ai-robot-avatar :size="16" />
          </span>

          <div class="ai-msg__body">
            <span class="ai-msg__role">{{ roleLabel(message.role) }}</span>

            <div class="ai-msg__bubble">
              <p v-if="message.content" class="ai-msg__text">{{ message.content }}</p>
              <span v-else-if="message.streaming" class="ai-msg__typing">
                {{ $t('ai.panel.thinking') }}
              </span>

              <div v-if="message.tool_runs && message.tool_runs.length" class="ai-msg__tools">
                <span
                  v-for="(run, index) in message.tool_runs"
                  :key="`${message.id}-${run.name}-${index}`"
                  class="ai-msg__tool"
                >
                  <span class="ai-msg__tool-name">{{ run.name }}</span>
                  <span v-if="run.summary" class="ai-msg__tool-summary">{{ run.summary }}</span>
                </span>
              </div>

              <div v-for="action in message.pending_actions" :key="action.id" class="ai-msg__confirm">
                <div class="ai-msg__confirm-head">
                  <strong>{{ action.title }}</strong>
                  <span class="ai-msg__confirm-risk">{{ riskLabel(action.risk) }}</span>
                </div>
                <p v-if="action.description" class="ai-msg__confirm-text">{{ action.description }}</p>
                <p v-if="action.arguments_summary" class="ai-msg__confirm-text ai-msg__confirm-args">
                  {{ action.arguments_summary }}
                </p>
                <p v-if="action.impact" class="ai-msg__confirm-text">
                  {{ $t('ai.confirm.impact', { impact: action.impact }) }}
                </p>
                <div class="ai-msg__confirm-actions">
                  <t-button
                    size="small"
                    variant="outline"
                    :disabled="Boolean(actionBusy[action.id])"
                    @click="handleReject(action)"
                  >
                    {{ $t('ai.confirm.reject') }}
                  </t-button>
                  <t-button
                    size="small"
                    theme="primary"
                    :loading="Boolean(actionBusy[action.id])"
                    @click="handleConfirm(action)"
                  >
                    {{ $t('ai.confirm.confirm') }}
                  </t-button>
                </div>
              </div>
            </div>
          </div>
        </article>
      </div>
    </div>

    <!-- 输入区：textarea 与发送按钮同一行，按钮高 32px、宽 ≥80px -->
    <template #footer>
      <div class="ai-composer">
        <div class="ai-composer__field">
          <t-textarea
            v-model="draft"
            class="ai-composer__input"
            :autosize="{ minRows: 1, maxRows: 6 }"
            :disabled="!enabled"
            :placeholder="$t('ai.panel.placeholder')"
            @keydown="handleKeydown"
          />
          <t-button
            v-if="streaming"
            class="ai-composer__btn"
            :style="composerBtnStyle"
            variant="outline"
            :aria-label="$t('ai.panel.stop')"
            @click="handleStop"
          >
            <template #icon><stop-circle-icon /></template>
            <span class="ai-composer__btn-label">{{ $t('ai.panel.stop') }}</span>
          </t-button>
          <t-button
            v-else
            class="ai-composer__btn"
            :style="composerBtnStyle"
            theme="primary"
            :loading="sending"
            :disabled="!canSend || !draft.trim()"
            :aria-label="$t('ai.panel.send')"
            @click="handleSend"
          >
            <template #icon><send-icon /></template>
            <span class="ai-composer__btn-label">{{ $t('ai.panel.send') }}</span>
          </t-button>
        </div>
        <div v-if="quotaText" class="ai-composer__meta">
          <span class="ai-composer__quota">{{ quotaText }}</span>
        </div>
      </div>
    </template>
  </t-drawer>
</template>
<script setup lang="ts">
import type { AiChatStore, AiPendingAction, AiPermissionMode } from '@turaidc/shared/runtime';
import { describeSubject } from '@turaidc/shared/runtime';
import { AddIcon, CloseIcon, SendIcon, StopCircleIcon } from 'tdesign-icons-vue-next';
import { computed, ref } from 'vue';

import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';

import AiRobotAvatar from './AiRobotAvatar.vue';

const props = defineProps<{
  modelValue: boolean;
  /** useAiChat 的返回值：面板只读状态与调用动作，会话本身活在启动器里 */
  chat: AiChatStore;
}>();

const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '400px'));

/** 头部图标按钮：视觉 28px，命中区由 ::after 补到 32px（与三端一致） */
const iconBtnStyle = {
  width: '1.75rem',
  height: '1.75rem',
  minWidth: '1.75rem',
  minHeight: '1.75rem',
  padding: '0',
  fontSize: '1rem',
};

// 发送/停止按钮：移动端的全局样式会把抽屉页脚按钮拉成 100% 宽，
// 这里用行内样式钉死，避免把同一行的输入框挤成 0 宽。
const composerBtnStyle = {
  width: 'auto',
  minWidth: '80px',
  height: '32px',
  minHeight: '32px',
  flex: '0 0 auto',
};

const visibleModel = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
});

const draft = ref('');
// 待确认动作按 id 记忙碌态，避免同一张卡片被连点两次
const actionBusy = ref<Record<number, boolean>>({});

/**
 * chat 是「普通对象里装着 ref」的组合式返回值，模板里直接写 chat.messages
 * 只会拿到 Ref 对象，因此统一解成 computed。
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
const modelLabel = computed(() => props.chat.bootstrap.value?.model_label || '');
const subjectLabel = computed(() => describeSubject(props.chat.subject.value));

const MODE_KEYS: Record<string, string> = {
  read_only: 'ai.mode.readOnly',
  confirm: 'ai.mode.confirm',
  auto_approve: 'ai.mode.autoApprove',
};

const ROLE_KEYS: Record<string, string> = {
  user: 'ai.message.you',
  assistant: 'ai.message.assistant',
  system: 'ai.message.system',
};

const RISK_KEYS: Record<string, string> = {
  read: 'ai.risk.read',
  write: 'ai.risk.write',
  dangerous: 'ai.risk.dangerous',
};

const modeOptions = computed(() =>
  allowedModes.value.map((mode) => ({ value: mode, label: t(MODE_KEYS[mode] || 'ai.mode.confirm') })),
);

const quotaText = computed(() => {
  const value = quota.value;
  if (!value) return '';
  if (!value.daily_messages) {
    return t('ai.panel.quotaUnlimited', { used: value.used_messages });
  }
  return t('ai.panel.quota', { used: value.used_messages, limit: value.daily_messages });
});

function roleLabel(role: string) {
  return t(ROLE_KEYS[role] || 'ai.message.system');
}

function riskLabel(risk: string) {
  return t(RISK_KEYS[risk] || 'ai.risk.write');
}

function handleModeChange(value: unknown) {
  void props.chat.setPermissionMode(String(value) as AiPermissionMode);
}

function handleNewConversation() {
  draft.value = '';
  props.chat.startNewConversation();
}

function handleClose() {
  emit('update:modelValue', false);
}

function handleKeydown(_value: string | number, context: { e: KeyboardEvent }) {
  const event = context.e;
  // Enter 发送、Shift+Enter 换行；输入法候选里的 Enter 不能当成发送
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
    return;
  }
  event.preventDefault();
  void handleSend();
}

async function handleSend() {
  const text = draft.value.trim();
  if (!text || !canSend.value) {
    return;
  }

  draft.value = '';
  await props.chat.send(text);
}

function handleStop() {
  props.chat.stop();
}

async function handleConfirm(action: AiPendingAction) {
  if (actionBusy.value[action.id]) {
    return;
  }

  actionBusy.value = { ...actionBusy.value, [action.id]: true };
  try {
    await props.chat.confirmAction(action);
  } finally {
    actionBusy.value = { ...actionBusy.value, [action.id]: false };
  }
}

async function handleReject(action: AiPendingAction) {
  if (actionBusy.value[action.id]) {
    return;
  }

  actionBusy.value = { ...actionBusy.value, [action.id]: true };
  try {
    await props.chat.rejectAction(action);
  } finally {
    actionBusy.value = { ...actionBusy.value, [action.id]: false };
  }
}
</script>
<style scoped lang="less">
/* ===== 头部 ===== */
.ai-panel-header {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.ai-panel-header__row {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
  min-width: 0;
}

.ai-panel-header__identity {
  display: flex;
  flex: 0 1 auto;
  gap: 8px;
  align-items: center;
  min-width: 0;
}

.ai-panel-header__logo {
  color: var(--td-brand-color);
}

.ai-panel-header__title {
  overflow: hidden;
  font: var(--td-font-title-small);
  color: var(--td-text-color-primary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel-header__tools {
  display: flex;
  flex: 0 0 auto;
  gap: 6px;
  align-items: center;
  margin-left: auto;
}

.ai-panel-header__mode {
  flex: none;
  width: 128px;
  min-width: 96px;
  max-width: 148px;
}

/* 28px 视觉 + 32px 命中区（伪元素算在元素上） */
.ai-panel-header__icon-btn {
  position: relative;
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  line-height: 1;
  color: var(--td-text-color-secondary);

  &::after {
    position: absolute;
    inset: -2px;
    content: '';
    border-radius: inherit;
  }
}

.ai-panel-header__context {
  display: flex;
  gap: 4px;
  align-items: center;
  min-width: 0;
  margin: 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-panel-header__model,
.ai-panel-header__subject {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-panel-header__model {
  color: var(--td-text-color-secondary);
}

.ai-panel-header__sep {
  flex: 0 0 auto;
}

/* ===== 正文 ===== */
/* 让空态撑满可见正文区，才能真正垂直居中（抽屉 body 只按内容高度撑开） */
.ai-panel-body {
  display: flex;
  flex-direction: column;
  min-height: 100%;
}

.ai-panel-body__alert {
  margin-bottom: 12px;
}

.ai-panel-body__loading {
  display: flex;
  justify-content: center;
  padding: 32px 0;
}

/* 空态：机器人头 56px + 16px 标题 + 13px 灰色副标题，垂直居中 */
.ai-panel-empty {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 320px;
  padding: 32px 0;
  text-align: center;
}

.ai-panel-empty__icon {
  color: var(--td-text-color-placeholder);
}

.ai-panel-empty__title {
  margin: 8px 0 0;
  font-size: 16px;
  font-weight: 500;
  line-height: 1.5;
  color: var(--td-text-color-primary);
}

.ai-panel-empty__desc {
  max-width: 20rem;
  margin: 0;
  font-size: 13px;
  line-height: 1.7;
  color: var(--td-text-color-secondary);
}

.ai-panel-messages {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 16px;
}

/* ===== 消息 ===== */
.ai-msg {
  display: flex;
  gap: 8px;
  align-items: flex-start;

  &--user {
    flex-direction: row-reverse;
  }

  &--system {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }
}

.ai-msg__avatar {
  display: inline-flex;
  flex: 0 0 1.5rem;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  margin-top: 2px;
  color: var(--td-brand-color);
  background: var(--td-brand-color-light);
  border-radius: 50%;
}

.ai-msg__body {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  max-width: min(72ch, 88%);
}

.ai-msg--user .ai-msg__body {
  align-items: flex-end;
}

.ai-msg__role {
  font: var(--td-font-body-small);
  color: var(--td-text-color-placeholder);
}

.ai-msg__bubble {
  max-width: 100%;
  padding: 10px 14px;
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  line-height: 1.7;
  background: var(--td-bg-color-container-hover);
  border-radius: var(--td-radius-medium);
  word-break: break-word;
}

.ai-msg--user .ai-msg__bubble {
  background: var(--td-brand-color-light);
}

.ai-msg--system .ai-msg__bubble {
  background: transparent;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.ai-msg__text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.ai-msg__typing {
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
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
  font: var(--td-font-body-small);
  color: var(--td-text-color-secondary);
  background: var(--td-bg-color-container);
  border-radius: var(--td-radius-default);
}

.ai-msg__tool-name {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: var(--td-brand-color);
}

.ai-msg__tool-summary {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-msg__confirm {
  margin-top: 10px;
  padding: 10px 12px;
  background: var(--td-bg-color-container);
  border: 1px solid var(--td-brand-color-light);
  border-radius: var(--td-radius-medium);
}

.ai-msg__confirm-head {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
}

.ai-msg__confirm-risk {
  flex: none;
  padding: 0 6px;
  color: var(--td-brand-color);
  font: var(--td-font-body-small);
  background: var(--td-brand-color-light);
  border-radius: var(--td-radius-round);
}

.ai-msg__confirm-text {
  margin: 6px 0 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  line-height: 1.6;
  word-break: break-word;
}

.ai-msg__confirm-args {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.ai-msg__confirm-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  margin-top: 10px;
}

/* ===== 输入区 ===== */
.ai-composer {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
  min-width: 0;
  /* 手机端 TDesign 把 .t-drawer__footer 切成 grid，子项不占满就会缩成内容宽度 */
  grid-column: 1 / -1;
  justify-self: stretch;
}

.ai-composer__field {
  display: flex;
  flex-wrap: nowrap;
  gap: 8px;
  align-items: flex-end;
  width: 100%;
  min-width: 0;
}

.ai-composer__field .ai-composer__input {
  flex: 1 1 auto;
  min-width: 0;
  width: auto;

  :deep(.t-textarea__inner) {
    min-height: 40px;
    max-height: 140px;
    overflow-y: auto;
    line-height: 1.7;
  }
}

.ai-composer__field .ai-composer__btn {
  flex: 0 0 auto;
  width: auto;
  min-width: 80px;
  height: 32px;
  min-height: 32px;
  padding: 0 14px;
}

.ai-composer__btn-label {
  white-space: nowrap;
}

.ai-composer__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 2px 8px;
  align-items: center;
  justify-content: space-between;
  min-height: 1rem;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-composer__quota {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 768px) {
  /* 手机上面板满屏，按钮仍然保持 80px 宽的文字按钮，不缩成图标 */
  .ai-composer__field .ai-composer__btn {
    min-width: 80px;
  }
}
</style>
