<template>
  <section class="client-ai">
    <t-card class="client-ai__conversations" :bordered="false">
      <template #title>
        <span class="client-ai__panel-title">{{ $t('ai.conversation.list') }}</span>
      </template>
      <template #actions>
        <t-button size="small" theme="primary" variant="outline" @click="handleCreate">
          <template #icon><add-icon /></template>
          {{ $t('ai.newConversation') }}
        </t-button>
      </template>

      <p v-if="!conversations.length" class="client-ai__list-empty">{{ $t('ai.conversation.empty') }}</p>

      <ul v-else class="client-ai__list">
        <li
          v-for="item in conversations"
          :key="item.id"
          class="client-ai__item"
          :class="{ 'client-ai__item--active': item.id === store.activeConversationId }"
        >
          <div v-if="editingId === item.id" class="client-ai__item-editing">
            <t-input
              v-model="editingTitle"
              size="small"
              :placeholder="$t('ai.conversation.renamePlaceholder')"
              @enter="submitRename"
            />
            <div class="client-ai__item-actions">
              <t-button size="small" theme="primary" variant="text" @click="submitRename">
                {{ $t('ai.conversation.renameSave') }}
              </t-button>
              <t-button size="small" variant="text" @click="cancelRename">
                {{ $t('ai.conversation.renameCancel') }}
              </t-button>
            </div>
          </div>

          <template v-else>
            <button type="button" class="client-ai__item-main" @click="selectConversation(item.id)">
              <span class="client-ai__item-title">{{ item.title || $t('ai.conversation.untitled') }}</span>
              <span class="client-ai__item-meta">
                <span>{{ $t('ai.conversation.messageCount', { count: item.message_count }) }}</span>
                <span v-if="item.last_message_at">
                  {{ $t('ai.conversation.updatedAt', { time: formatShortDateTime(item.last_message_at) }) }}
                </span>
              </span>
            </button>
            <div class="client-ai__item-actions">
              <t-button
                shape="square"
                size="small"
                variant="text"
                :aria-label="$t('ai.conversation.rename')"
                :title="$t('ai.conversation.rename')"
                @click="startRename(item)"
              >
                <template #icon><edit-icon /></template>
              </t-button>
              <t-popconfirm
                theme="danger"
                :content="$t('ai.conversation.deleteConfirm')"
                @confirm="handleDelete(item.id)"
              >
                <t-button
                  shape="square"
                  size="small"
                  variant="text"
                  :aria-label="$t('ai.conversation.delete')"
                  :title="$t('ai.conversation.delete')"
                >
                  <template #icon><delete-icon /></template>
                </t-button>
              </t-popconfirm>
            </div>
          </template>
        </li>
      </ul>

      <div v-if="total > PAGE_SIZE" class="client-ai__pagination">
        <t-pagination size="small" :total="total" :current="page" :page-size="PAGE_SIZE" @change="handlePageChange" />
      </div>
    </t-card>

    <t-card class="client-ai__chat" :bordered="false">
      <template #title>
        <div class="client-ai__chat-title">
          <robot-icon class="client-ai__chat-logo" />
          <span class="client-ai__panel-title">{{ conversationTitle || $t('ai.title') }}</span>
        </div>
      </template>
      <template #actions>
        <div class="client-ai__chat-actions">
          <ai-permission-switch
            :model-value="store.permissionMode"
            :allowed-modes="store.allowedModes"
            :disabled="busy"
            @update:model-value="handlePermissionChange"
          />
          <t-button size="small" variant="outline" @click="handleCollapse">
            <template #icon><fullscreen-exit-icon /></template>
            {{ $t('ai.collapse') }}
          </t-button>
        </div>
      </template>

      <div class="client-ai__chat-body">
        <ai-message-list :messages="store.messages" />

        <div class="client-ai__composer">
          <t-textarea
            v-model="draft"
            class="client-ai__composer-input"
            :autosize="{ minRows: 1, maxRows: 5 }"
            :disabled="!store.enabled"
            :placeholder="$t('ai.inputPlaceholder')"
            @keydown="handleKeydown"
          />
          <div class="client-ai__composer-bar">
            <span v-if="quotaInfo" class="client-ai__composer-quota">{{ $t('ai.quota.remaining', quotaInfo) }}</span>
            <div class="client-ai__composer-buttons">
              <t-button v-if="busy" variant="outline" size="small" @click="handleStop">
                <template #icon><stop-circle-icon /></template>
                {{ $t('ai.stop') }}
              </t-button>
              <t-button v-else theme="primary" size="small" :disabled="!canSubmit" @click="handleSend">
                <template #icon><send-icon /></template>
                {{ $t('ai.send') }}
              </t-button>
            </div>
          </div>
          <p v-if="store.error" class="client-ai__composer-error">{{ store.error }}</p>
        </div>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import type { AiConversation, AiPermissionMode } from '@turaidc/shared/runtime';
import {
  AddIcon,
  DeleteIcon,
  EditIcon,
  FullscreenExitIcon,
  RobotIcon,
  SendIcon,
  StopCircleIcon,
} from 'tdesign-icons-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import AiMessageList from '@/components/ai/AiMessageList.vue';
import AiPermissionSwitch from '@/components/ai/AiPermissionSwitch.vue';
import { useAiStore } from '@/store/modules/ai';
import { formatShortDateTime } from '@/utils/format';

/** 会话列表每页条数，与 createAiClient 默认分页保持一致 */
const PAGE_SIZE = 20;
const DASHBOARD_PATH = '/client/dashboard';

const router = useRouter();
const store = useAiStore();

const page = ref(1);
const draft = ref('');
const editingId = ref<number | null>(null);
const editingTitle = ref('');

const busy = computed(() => store.streaming || store.sending);
const canSubmit = computed(() => store.canSend && draft.value.trim() !== '');
const total = computed(() => store.conversationPage?.total ?? 0);
const conversationTitle = computed(() => store.conversation?.title ?? '');

/** 后端已按时间倒序返回，这里再兜一次：没有 last_message_at 的新会话按创建时间排前面 */
const conversations = computed<AiConversation[]>(() => {
  const list = store.conversationPage?.list ?? [];
  const timeOf = (item: AiConversation): number => {
    const raw = item.last_message_at || item.created_at || '';
    const parsed = Date.parse(String(raw).replace(' ', 'T'));
    return Number.isFinite(parsed) ? parsed : 0;
  };
  return [...list].sort((a, b) => timeOf(b) - timeOf(a));
});

const quotaInfo = computed(() => {
  const quota = store.quota;
  if (!quota || quota.daily_messages <= 0) {
    return null;
  }
  return {
    remaining: Math.max(0, quota.daily_messages - quota.used_messages),
    total: quota.daily_messages,
  };
});

const loadConversations = async (): Promise<void> => {
  await store.refreshConversations(page.value);
};

const handlePageChange = (pageInfo: { current: number }): void => {
  page.value = pageInfo.current;
  void loadConversations();
};

const handleCreate = (): void => {
  store.startNewConversation();
  editingId.value = null;
};

const selectConversation = (id: number): void => {
  void store.openConversation(id);
};

const startRename = (item: AiConversation): void => {
  editingId.value = item.id;
  editingTitle.value = item.title || '';
};

const cancelRename = (): void => {
  editingId.value = null;
  editingTitle.value = '';
};

const submitRename = async (): Promise<void> => {
  const id = editingId.value;
  const title = editingTitle.value.trim();
  if (!id || title === '') {
    cancelRename();
    return;
  }
  await store.renameConversation(id, title);
  cancelRename();
  await loadConversations();
};

const handleDelete = async (id: number): Promise<void> => {
  await store.removeConversation(id);
  await loadConversations();
};

/** 收起为浮窗：回到上一页并打开抽屉，会话不中断 */
const handleCollapse = (): void => {
  store.openDrawer();
  if (window.history.state?.back) {
    router.back();
    return;
  }
  void router.push(DASHBOARD_PATH);
};

const handleSend = async (): Promise<void> => {
  const text = draft.value.trim();
  if (!canSubmit.value || text === '') {
    return;
  }
  draft.value = '';
  await store.send(text);
  await loadConversations();
};

const handleStop = (): void => {
  store.stop();
};

const handleKeydown = (_value: string | number, context: { e: KeyboardEvent }): void => {
  const event = context.e;
  // Enter 发送、Shift+Enter 换行；输入法候选中的 Enter 不能当发送
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
    return;
  }
  event.preventDefault();
  void handleSend();
};

const handlePermissionChange = (mode: AiPermissionMode): void => {
  void store.setPermissionMode(mode);
};

onMounted(async () => {
  await store.ensureReady();
  await loadConversations();
});
</script>
<style scoped lang="less">
.client-ai {
  display: grid;
  grid-template-columns: minmax(15rem, 18rem) minmax(0, 1fr);
  gap: var(--td-comp-margin-l);
  align-items: stretch;
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
}

.client-ai__conversations,
.client-ai__chat {
  display: flex;
  flex-direction: column;
  height: min(720px, calc(100vh - 13rem));
  min-height: 26rem;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.client-ai__conversations :deep(.t-card__body),
.client-ai__chat :deep(.t-card__body) {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}

.client-ai__chat :deep(.t-card__body) {
  padding: 0;
}

.client-ai__conversations :deep(.t-card__body) {
  padding: 0 var(--td-comp-paddingLR-s) var(--td-comp-paddingTB-s);
}

.client-ai__panel-title {
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
}

.client-ai__list {
  display: grid;
  flex: 1;
  gap: var(--td-comp-margin-xs);
  align-content: start;
  min-height: 0;
  padding: 0;
  margin: 0;
  overflow-y: auto;
  list-style: none;
}

.client-ai__list-empty {
  margin: 0;
  padding: var(--td-comp-paddingTB-xxl) var(--td-comp-paddingLR-m);
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
  text-align: center;
}

.client-ai__item {
  display: flex;
  gap: var(--td-comp-margin-xs);
  align-items: flex-start;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
  border-radius: var(--td-radius-medium);

  &:hover {
    background: var(--td-bg-color-container-hover);
  }
}

.client-ai__item--active {
  background: var(--td-brand-color-light);
}

.client-ai__item-main {
  display: grid;
  flex: 1;
  gap: 0.125rem;
  min-width: 0;
  padding: 0;
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;
}

.client-ai__item-title {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-ai__item-meta {
  display: flex;
  gap: var(--td-comp-margin-s);
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-ai__item-actions {
  display: flex;
  flex: none;
  gap: 0.125rem;
  align-items: center;
}

.client-ai__item-editing {
  display: grid;
  flex: 1;
  gap: var(--td-comp-margin-xs);
}

.client-ai__pagination {
  display: flex;
  justify-content: center;
  padding-top: var(--td-comp-paddingTB-s);
}

.client-ai__chat-title {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  min-width: 0;
}

.client-ai__chat-logo {
  color: var(--td-brand-color);
  font-size: 1.125rem;
}

.client-ai__chat-actions {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.client-ai__chat-body {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-height: 0;
}

.client-ai__composer {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-l);
  border-top: thin solid var(--td-component-stroke);
}

.client-ai__composer-input {
  width: 100%;
}

.client-ai__composer-bar {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: space-between;
}

.client-ai__composer-quota {
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.client-ai__composer-buttons {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  margin-left: auto;
}

.client-ai__composer-error {
  margin: 0;
  color: var(--td-error-color);
  font: var(--td-font-body-small);
  overflow-wrap: anywhere;
}

@media (max-width: @screen-sm-rem) {
  .client-ai {
    grid-template-columns: minmax(0, 1fr);
    padding: 12px;
  }

  .client-ai__conversations {
    height: auto;
    max-height: 40vh;
  }

  .client-ai__chat {
    height: calc(100vh - 18rem);
    min-height: 24rem;
  }

  .client-ai__chat-actions {
    flex-wrap: wrap;
    justify-content: flex-end;
  }

  .client-ai__item-actions :deep(.t-button) {
    min-width: var(--td-comp-size-s);
  }
}
</style>
