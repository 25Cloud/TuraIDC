<template>
  <t-drawer
    v-if="store.enabled && hasSession"
    v-model:visible="visible"
    placement="right"
    :size="panelSize"
    :z-index="3400"
    :footer="true"
    :close-btn="true"
    :lazy="true"
    drawer-class-name="ai-chat-drawer"
  >
    <template #header>
      <div class="ai-drawer-header">
        <div class="ai-drawer-header__title">
          <robot-icon class="ai-drawer-header__logo" />
          <span class="ai-drawer-header__name">{{ $t('ai.title') }}</span>
          <t-tag v-if="modelLabel" size="small" variant="light" theme="primary">{{ modelLabel }}</t-tag>
        </div>

        <div class="ai-drawer-header__tools">
          <ai-permission-switch
            :model-value="store.permissionMode"
            :allowed-modes="store.allowedModes"
            :disabled="busy"
            @update:model-value="handlePermissionChange"
          />
          <t-button
            shape="square"
            size="small"
            variant="text"
            :aria-label="$t('ai.newConversation')"
            :title="$t('ai.newConversation')"
            @click="handleNewConversation"
          >
            <template #icon><add-icon /></template>
          </t-button>
          <t-button
            shape="square"
            size="small"
            variant="text"
            :aria-label="$t('ai.openPageTitle')"
            :title="$t('ai.openPageTitle')"
            @click="handleOpenPage"
          >
            <template #icon><fullscreen-icon /></template>
          </t-button>
        </div>

        <p v-if="subjectLabel" class="ai-drawer-header__context">
          {{ $t('ai.context.viewing', { subject: subjectLabel }) }}
        </p>
      </div>
    </template>

    <div v-if="recentConversations.length" class="ai-drawer-recent">
      <span class="ai-drawer-recent__label">{{ $t('ai.recent.title') }}</span>
      <div class="ai-drawer-recent__list">
        <button
          v-for="item in recentConversations"
          :key="item.id"
          type="button"
          class="ai-drawer-recent__item"
          :class="{ 'ai-drawer-recent__item--active': item.id === store.conversation?.id }"
          :title="item.title || $t('ai.conversation.untitled')"
          @click="handleOpenRecent(item.id)"
        >
          {{ item.title || $t('ai.conversation.untitled') }}
        </button>
      </div>
    </div>

    <ai-message-list :messages="store.messages" />

    <template #footer>
      <div class="ai-drawer-composer">
        <t-textarea
          v-model="draft"
          class="ai-drawer-composer__input"
          :autosize="{ minRows: 1, maxRows: 5 }"
          :disabled="!store.enabled"
          :placeholder="$t('ai.inputPlaceholder')"
          @keydown="handleKeydown"
        />
        <div class="ai-drawer-composer__bar">
          <span v-if="quotaInfo" class="ai-drawer-composer__quota">{{ $t('ai.quota.remaining', quotaInfo) }}</span>
          <div class="ai-drawer-composer__buttons">
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
        <p v-if="store.error" class="ai-drawer-composer__error">{{ store.error }}</p>
      </div>
    </template>
  </t-drawer>
</template>
<script setup lang="ts">
import type { AiPermissionMode } from '@turaidc/shared/runtime';
import { describeSubject } from '@turaidc/shared/runtime';
import { AddIcon, FullscreenIcon, RobotIcon, SendIcon, StopCircleIcon } from 'tdesign-icons-vue-next';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { getClientToken } from '@/app/runtime/session';
import AiPermissionSwitch from '@/components/ai/AiPermissionSwitch.vue';
import { useDeviceLayout } from '@/composables/useDeviceLayout';
import { useAiStore } from '@/store/modules/ai';

// 抽屉由 App.vue / 布局层常驻挂载，消息列表却是「打开才用」。
// 这里异步引入，把 markdown-it 与消息渲染拆成按需加载的独立 chunk，
// 避免每个控制台页面首屏都多下载一份 100 kB+ 的 markdown 依赖。
const AiMessageList = defineAsyncComponent(() => import('@/components/ai/AiMessageList.vue'));

/** 独立页路径：跳过去后浮窗关闭，免得两个入口同时挂着同一份对话 */
const AI_PAGE_PATH = '/client/ai';

const router = useRouter();
const store = useAiStore();
const { isMobile } = useDeviceLayout();

// 未登录（或刚退出）时不挂抽屉：bootstrap 的 enabled 是上一个账号留下的，不能作为依据
const hasSession = Boolean(getClientToken());

const draft = ref('');

const visible = computed({
  get: () => store.open,
  set: (value: boolean) => {
    if (value) {
      store.openDrawer();
      return;
    }
    store.closeDrawer();
  },
});

// 桌面固定 400px；<768px 交给全屏（配合下面的 .t-drawer__content-wrapper 覆盖）
const panelSize = computed(() => (isMobile.value ? '100%' : '400px'));

const busy = computed(() => store.streaming || store.sending);
const canSubmit = computed(() => store.canSend && draft.value.trim() !== '');
const modelLabel = computed(() => store.bootstrap?.model_label ?? '');
const subjectLabel = computed(() => describeSubject(store.subject));

const quotaInfo = computed(() => {
  const quota = store.quota;
  // 0 表示不限量，不显示额度条
  if (!quota || quota.daily_messages <= 0) {
    return null;
  }
  return {
    remaining: Math.max(0, quota.daily_messages - quota.used_messages),
    total: quota.daily_messages,
  };
});

const handleSend = async (): Promise<void> => {
  const text = draft.value.trim();
  if (!canSubmit.value || text === '') {
    return;
  }
  draft.value = '';
  await store.send(text);
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

const handleNewConversation = (): void => {
  store.startNewConversation();
};

const handleOpenPage = (): void => {
  store.enterPageMode();
  void router.push(AI_PAGE_PATH);
};

/** 抽屉打开时刷新一次最近对话，避免列表停在很久以前的快照 */
watch(visible, (open) => {
  if (open) {
    void store.refreshConversations();
  }
});

const recentConversations = computed(() => (store.conversationPage?.list ?? []).slice(0, 8));

/** 点历史对话直接进独立页继续（与 DSH 一致：侧栏点开就是整页会话） */
const handleOpenRecent = (id: number): void => {
  store.enterPageMode();
  store.closeDrawer();
  void router.push({ path: AI_PAGE_PATH, query: { conversation: String(id) } });
};
</script>
<style scoped lang="less">
// 抽屉内容会被 teleport 到 body 下，所以这里只用单类选择器，不依赖组件根节点祖先
.ai-drawer-header {
  display: grid;
  gap: var(--td-comp-margin-s);
  width: 100%;
  padding-right: var(--td-comp-margin-xxl);
  text-align: left;
}

.ai-drawer-header__title {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  min-width: 0;
}

.ai-drawer-header__logo {
  color: var(--td-brand-color);
  font-size: 1.125rem;
}

.ai-drawer-header__name {
  font: var(--td-font-title-small);
  color: var(--td-text-color-primary);
  white-space: nowrap;
}

.ai-drawer-header__tools {
  display: flex;
  flex-wrap: nowrap;
  gap: var(--td-comp-margin-xs);
  align-items: center;
}

// 抽屉头部很窄，按钮必须禁止收缩，否则会被挤成几个小点
.ai-drawer-header__tools > * {
  flex: 0 0 auto;
}

.ai-drawer-header__tools :deep(.t-button) {
  min-width: 1.75rem;
  height: 1.75rem;
}

.ai-drawer-header__context {
  margin: 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
  overflow-wrap: anywhere;
}

.ai-drawer-composer {
  display: grid;
  gap: var(--td-comp-margin-s);
}

// —— 最近对话：一排可横滚的胶囊，点一下进独立页 ——
.ai-drawer-recent {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  padding: var(--td-comp-paddingTB-xs) var(--td-comp-paddingLR-l);
  border-bottom: thin solid var(--td-component-stroke);
}

.ai-drawer-recent__label {
  flex: 0 0 auto;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-drawer-recent__list {
  display: flex;
  flex: 1;
  gap: var(--td-comp-margin-xs);
  min-width: 0;
  padding-bottom: 2px;
  overflow-x: auto;
  scrollbar-width: thin;
}

.ai-drawer-recent__item {
  flex: 0 0 auto;
  max-width: 9rem;
  padding: 2px var(--td-comp-paddingLR-xs);
  overflow: hidden;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: pointer;
  background: var(--td-bg-color-secondarycontainer);
  border: thin solid transparent;
  border-radius: 999px;
}

.ai-drawer-recent__item:hover {
  color: var(--td-brand-color);
  border-color: var(--td-brand-color);
}

.ai-drawer-recent__item--active {
  color: var(--td-brand-color);
  background: var(--td-brand-color-light);
  border-color: var(--td-brand-color);
}

.ai-drawer-composer__input {
  width: 100%;
}

.ai-drawer-composer__bar {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: space-between;
}

.ai-drawer-composer__quota {
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-drawer-composer__buttons {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  margin-left: auto;
}

.ai-drawer-composer__error {
  margin: 0;
  color: var(--td-error-color);
  font: var(--td-font-body-small);
  overflow-wrap: anywhere;
}
</style>
<!-- eslint-disable-next-line vue-scoped-css/enforce-style-type -->
<style lang="less">
// 面板几何：桌面浮在右上角（最大高 min(640px, 100vh - 96px)），<768px 全屏。
// 选择器带上 .t-drawer 提高权重，避免被 TDesign 默认的 .t-drawer__content-wrapper--right 覆盖。
.t-drawer.ai-chat-drawer .t-drawer__content-wrapper {
  top: 48px;
  height: min(640px, calc(100vh - 96px));
  overflow: hidden;
  border-radius: var(--td-radius-large);
}

.t-drawer.ai-chat-drawer .t-drawer__header {
  align-items: flex-start;
  min-height: auto;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-l) var(--td-comp-paddingTB-s);
  border-radius: var(--td-radius-large) var(--td-radius-large) 0 0;
}

.t-drawer.ai-chat-drawer .t-drawer__body {
  display: flex;
  flex-direction: column;
  padding: 0;
  overflow: hidden;
}

.t-drawer.ai-chat-drawer .t-drawer__footer {
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-l);
}

@media (max-width: (@screen-sm - 1px)) {
  .t-drawer.ai-chat-drawer .t-drawer__content-wrapper {
    top: 0;
    height: 100%;
    border-radius: 0;
  }

  .t-drawer.ai-chat-drawer .t-drawer__header {
    padding-top: calc(var(--td-comp-paddingTB-m) + env(safe-area-inset-top, 0px));
    border-radius: 0;
  }

  .t-drawer.ai-chat-drawer .t-drawer__footer {
    padding-bottom: calc(var(--td-comp-paddingTB-m) + env(safe-area-inset-bottom, 0px));
  }
}
</style>
