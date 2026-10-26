<template>
  <t-drawer v-model:visible="visibleModel" :header="$t('ai.panel.title')" :size="drawerSize" :footer="true">
    <div class="ai-admin-panel">
      <div class="ai-admin-panel__meta">
        <span v-if="subjectLabel" class="ai-admin-panel__subject">
          {{ $t('ai.panel.subject', { label: subjectLabel }) }}
        </span>
        <t-select
          v-if="modeOptions.length > 1"
          class="ai-admin-panel__mode"
          size="small"
          :model-value="permissionMode"
          @change="handleModeChange"
        >
          <t-option v-for="mode in modeOptions" :key="mode.value" :label="mode.label" :value="mode.value" />
        </t-select>
      </div>

      <t-alert v-if="ready && !enabled" class="ai-admin-panel__alert" theme="info" :message="$t('ai.panel.disabled')" />
      <t-alert v-else-if="error && !streaming" class="ai-admin-panel__alert" theme="error" :message="error" />

      <div class="ai-admin-panel__messages">
        <t-loading v-if="loading" size="small" />

        <p v-else-if="!messages.length" class="ai-admin-panel__empty">
          {{ $t('ai.panel.empty') }}
        </p>

        <div v-for="message in messages" :key="message.id" class="ai-msg" :class="`ai-msg--${message.role}`">
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
      </div>
    </div>

    <template #footer>
      <div class="ai-admin-panel__composer">
        <t-textarea
          v-model="draft"
          :disabled="!enabled"
          :autosize="{ minRows: 2, maxRows: 4 }"
          :placeholder="$t('ai.panel.placeholder')"
          @keydown.enter.exact.prevent="handleSend"
        />
        <div class="ai-admin-panel__composer-actions">
          <span class="ai-admin-panel__quota">{{ quotaText }}</span>
          <t-button size="small" variant="outline" :disabled="streaming" @click="handleNewConversation">
            {{ $t('ai.panel.newConversation') }}
          </t-button>
          <t-button v-if="streaming" size="small" @click="handleStop">
            {{ $t('ai.panel.stop') }}
          </t-button>
          <t-button
            v-else
            size="small"
            theme="primary"
            :loading="sending"
            :disabled="!canSend || !draft.trim()"
            @click="handleSend"
          >
            {{ $t('ai.panel.send') }}
          </t-button>
        </div>
      </div>
    </template>
  </t-drawer>
</template>
<script setup lang="ts">
import type { AiChatStore, AiPendingAction, AiPermissionMode } from '@turaidc/shared/runtime';
import { describeSubject } from '@turaidc/shared/runtime';
import { computed, ref } from 'vue';

import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';

const props = defineProps<{
  modelValue: boolean;
  /** useAiChat 的返回值：面板只读状态与调用动作，会话本身活在启动器里 */
  chat: AiChatStore;
}>();

const emit = defineEmits<{ 'update:modelValue': [boolean] }>();

const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '460px'));

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
.ai-admin-panel__meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.ai-admin-panel__subject {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  color: var(--td-text-color-secondary, #4e5969);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-admin-panel__mode {
  width: 140px;
  flex: none;
}

.ai-admin-panel__alert {
  margin-bottom: 10px;
}

.ai-admin-panel__messages {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ai-admin-panel__empty {
  margin: 32px 0;
  text-align: center;
  font-size: 12px;
  line-height: 1.7;
  color: var(--td-text-color-placeholder, #a9aeb8);
}

.ai-msg {
  display: flex;
  flex-direction: column;
  gap: 4px;

  &--user {
    align-items: flex-end;
  }
}

.ai-msg__role {
  font-size: 11px;
  color: var(--td-text-color-placeholder, #a9aeb8);
}

.ai-msg__bubble {
  max-width: 92%;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--td-bg-color-container-hover, #f3f3f3);
  font-size: 13px;
  line-height: 1.7;
  color: var(--td-text-color-primary, #1f2937);
}

.ai-msg--user .ai-msg__bubble {
  background: var(--td-brand-color-light, #f2f3ff);
}

.ai-msg__text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.ai-msg__typing {
  font-size: 12px;
  color: var(--td-text-color-placeholder, #a9aeb8);
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
  background: var(--td-bg-color-container, #fff);
  font-size: 11px;
  color: var(--td-text-color-secondary, #4e5969);
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
  border-radius: 8px;
  background: var(--td-bg-color-container, #fff);
  border: 1px solid var(--td-brand-color-light, #d9e1ff);
}

.ai-msg__confirm-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.ai-msg__confirm-risk {
  flex: none;
  padding: 0 6px;
  border-radius: 8px;
  font-size: 11px;
  color: var(--td-brand-color);
  background: var(--td-brand-color-light, #f2f3ff);
}

.ai-msg__confirm-text {
  margin: 6px 0 0;
  font-size: 12px;
  line-height: 1.6;
  color: var(--td-text-color-secondary, #4e5969);
  word-break: break-word;
}

.ai-msg__confirm-args {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.ai-msg__confirm-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}

.ai-admin-panel__composer-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

.ai-admin-panel__quota {
  flex: 1;
  min-width: 0;
  font-size: 11px;
  color: var(--td-text-color-placeholder, #a9aeb8);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
