<template>
  <t-drawer
    v-if="store.enabled && hasSession"
    v-model:visible="visible"
    placement="right"
    :size="panelSize"
    :z-index="3400"
    :footer="true"
    :close-btn="false"
    :lazy="true"
    drawer-class-name="ai-chat-drawer"
  >
    <template #header>
      <div class="ai-drawer-header">
        <div class="ai-drawer-header__row">
          <div class="ai-drawer-header__title">
            <ai-robot-avatar :size="20" class="ai-drawer-header__logo" />
            <span class="ai-drawer-header__name">{{ $t('ai.title') }}</span>
          </div>

          <div class="ai-drawer-header__tools">
            <ai-permission-switch
              :model-value="store.permissionMode"
              :allowed-modes="store.allowedModes"
              :disabled="busy"
              @update:model-value="handlePermissionChange"
            />
            <t-button
              class="ai-icon-btn"
              shape="square"
              variant="text"
              :style="iconStyle"
              :aria-label="$t('ai.newConversation')"
              :title="$t('ai.newConversation')"
              @click="handleNewConversation"
            >
              <template #icon><add-icon /></template>
            </t-button>
            <t-button
              class="ai-icon-btn"
              shape="square"
              variant="text"
              :style="iconStyle"
              :aria-label="$t('ai.openPageTitle')"
              :title="$t('ai.openPageTitle')"
              @click="handleOpenPage"
            >
              <template #icon><fullscreen-icon /></template>
            </t-button>
            <t-button
              class="ai-icon-btn"
              shape="square"
              variant="text"
              :style="iconStyle"
              :aria-label="$t('ai.close')"
              :title="$t('ai.close')"
              @click="handleClose"
            >
              <template #icon><close-icon /></template>
            </t-button>
          </div>
        </div>

        <!-- 第二行：模型名 / 当前页面上下文。面板窄于 380px 时模型名整行省略，绝不挤压标题 -->
        <p v-if="modelLabel || subjectLabel" class="ai-drawer-header__context">
          <span v-if="modelLabel" class="ai-drawer-header__model">{{ modelLabel }}</span>
          <span v-if="modelLabel && subjectLabel" class="ai-drawer-header__sep" aria-hidden="true">·</span>
          <span v-if="subjectLabel" class="ai-drawer-header__subject">
            {{ $t('ai.context.viewing', { subject: subjectLabel }) }}
          </span>
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
      <div class="ai-composer">
        <div class="ai-composer__field">
          <t-textarea
            v-model="draft"
            class="ai-composer__input"
            :autosize="{ minRows: 1, maxRows: 6 }"
            :disabled="!store.enabled"
            :placeholder="$t('ai.inputPlaceholder')"
            @keydown="handleKeydown"
          />
          <div class="ai-composer__actions">
            <t-button
              v-if="busy"
              class="ai-composer__btn"
              variant="outline"
              :shape="isMobile ? 'square' : 'rectangle'"
              :style="sendStyle"
              :aria-label="$t('ai.stop')"
              @click="handleStop"
            >
              <template #icon><stop-circle-icon /></template>
              <span v-if="!isMobile" class="ai-composer__btn-label">{{ $t('ai.stop') }}</span>
            </t-button>
            <t-button
              v-else
              class="ai-composer__btn"
              theme="primary"
              :shape="isMobile ? 'square' : 'rectangle'"
              :style="sendStyle"
              :disabled="!canSubmit"
              :aria-label="$t('ai.send')"
              @click="handleSend"
            >
              <template #icon><send-icon /></template>
              <span v-if="!isMobile" class="ai-composer__btn-label">{{ $t('ai.send') }}</span>
            </t-button>
          </div>
        </div>
        <div v-if="quotaInfo || store.error" class="ai-composer__meta">
          <span v-if="quotaInfo" class="ai-composer__quota">{{ $t('ai.quota.remaining', quotaInfo) }}</span>
          <span v-if="store.error" class="ai-composer__error">{{ store.error }}</span>
        </div>
      </div>
    </template>
  </t-drawer>
</template>
<script setup lang="ts">
import type { AiPermissionMode } from '@turaidc/shared/runtime';
import { describeSubject } from '@turaidc/shared/runtime';
import { AddIcon, CloseIcon, FullscreenIcon, SendIcon, StopCircleIcon } from 'tdesign-icons-vue-next';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { getClientToken } from '@/app/runtime/session';
import AiPermissionSwitch from '@/components/ai/AiPermissionSwitch.vue';
import AiRobotAvatar from '@/components/ai/AiRobotAvatar.vue';
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
const { isMobile, isWide, isDesktop } = useDeviceLayout();

// 未登录（或刚退出）时不挂抽屉：bootstrap 的 enabled 是上一个账号留下的，不能作为依据
const hasSession = Boolean(getClientToken());

/**
 * 尺寸基线由项目全局样式决定（src/style/reset.less 里 button 最小 32×32、≤768px 40×40；
 * theme.css 的 #app .t-button--shape-square 给方形按钮定宽）。所以这里不再写 28px——
 * 28px 会被全局 min-height:32px 撑回 32，只剩宽度变窄、视觉不方。只补移动端 40px 宽度：
 * 桌面 32×32、手机 40×40，都满足「可点击控件 ≥32×32」。
 */
const iconStyle = computed(() => (isMobile.value ? { width: '2.5rem', minWidth: '2.5rem' } : {}));

/** 发送/停止：桌面宽度 ≥88px 带文字，移动端收成 40×40 纯图标 */
const sendStyle = computed(() =>
  isMobile.value ? { width: '2.5rem', minWidth: '2.5rem', padding: '0' } : { minWidth: '88px' },
);

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

/**
 * 四档宽度：≥1200 → 420；1024–1199 → 380；768–1023 → min(360, 46vw)；<768 → 满屏。
 * TDesign 的 size 是内联宽度，只能用响应式值而不是纯 CSS。
 */
const panelSize = computed(() => {
  if (isMobile.value) {
    return '100%';
  }
  if (isWide.value) {
    return '420px';
  }
  if (isDesktop.value) {
    return '380px';
  }
  return 'min(360px, 46vw)';
});

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

const handleClose = (): void => {
  store.closeDrawer();
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
  gap: 2px;
  width: 100%;
  text-align: left;
}

.ai-drawer-header__row {
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
  min-width: 0;
}

.ai-drawer-header__title {
  display: flex;
  flex: 0 1 auto;
  gap: 8px;
  align-items: center;
  min-width: 0;
}

.ai-drawer-header__logo {
  color: var(--td-brand-color);
}

.ai-drawer-header__name {
  overflow: hidden;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-drawer-header__tools {
  display: flex;
  flex: 0 0 auto;
  gap: 6px;
  align-items: center;
  margin-left: auto;
}

// 命中区由项目全局基线保证（reset.less: 32×32，≤768px 40×40），这里只管配色与图标字号
.ai-icon-btn {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  color: var(--td-text-color-secondary);

  &:hover {
    color: var(--td-brand-color);
  }

  :deep(.t-icon) {
    font-size: 1rem;
  }
}

.ai-drawer-header__context {
  display: flex;
  gap: 4px;
  align-items: center;
  min-width: 0;
  margin: 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-drawer-header__model {
  overflow: hidden;
  color: var(--td-text-color-secondary);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-drawer-header__sep {
  flex: 0 0 auto;
}

.ai-drawer-header__subject {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

// 手机（面板满屏）：标题独占一行，控件挪到第二行右对齐，任何宽度都不会挤压标题
@media (max-width: @screen-sm-rem) {
  .ai-drawer-header__row {
    flex-wrap: wrap;
  }

  .ai-drawer-header__tools {
    justify-content: flex-end;
    width: 100%;
    margin-top: 4px;
  }
}

// 面板窄于 380px（768–1023 档、以及 <380px 的手机）时，模型名整行省略
@container (max-width: 379px) {
  .ai-drawer-header__model,
  .ai-drawer-header__sep {
    display: none;
  }
}

@media (width <= 379px) {
  .ai-drawer-header__model,
  .ai-drawer-header__sep {
    display: none;
  }
}

// —— 最近对话：一排可横滚的胶囊，点一下进独立页 ——
.ai-drawer-recent {
  display: flex;
  flex: 0 0 auto;
  gap: 8px;
  align-items: center;
  padding: 6px var(--td-comp-paddingLR-l);
  border-bottom: 1px solid var(--td-component-stroke);
}

.ai-drawer-recent__label {
  flex: 0 0 auto;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.ai-drawer-recent__list {
  display: flex;
  flex: 1;
  gap: 6px;
  min-width: 0;
  padding-bottom: 2px;
  overflow-x: auto;
  scrollbar-width: thin;
}

.ai-drawer-recent__item {
  flex: 0 0 auto;
  max-width: 9rem;
  height: 2rem;
  padding: 0 12px;
  overflow: hidden;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: pointer;
  background: var(--td-bg-color-secondarycontainer);
  border: 1px solid transparent;
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

// —— 输入区：输入框与发送按钮同一行 ——
.ai-composer {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
}

.ai-composer__field {
  display: flex;
  flex-wrap: nowrap;
  gap: 8px;
  align-items: flex-end;
  min-width: 0;
}

.ai-composer__input {
  flex: 1 1 auto;
  min-width: 0;
}

.ai-composer__input :deep(textarea) {
  // TDesign 的 autosize 用内联 style 写死 min-height/height（1 行时 34px），
  // 只有 !important 能把输入框托到 40px 起跳；上限 168px 后改为内部滚动
  min-height: 40px !important;
  max-height: 168px !important;
  overflow-y: auto;
  line-height: 1.6;
}

.ai-composer__actions {
  display: flex;
  flex: 0 0 auto;
  gap: 6px;
  align-items: center;
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

.ai-composer__error {
  color: var(--td-error-color);
  overflow-wrap: anywhere;
}
</style>
<!-- eslint-disable-next-line vue-scoped-css/enforce-style-type -->
<style lang="less">
// 面板几何：桌面浮在右上角（最大高 min(680px, 100vh - 96px)），<768px 全屏。
// 选择器带上 .t-drawer 提高权重，避免被 TDesign 默认的 .t-drawer__content-wrapper--right 覆盖。
.t-drawer.ai-chat-drawer .t-drawer__content-wrapper {
  top: 48px;
  height: min(680px, calc(100vh - 96px));
  overflow: hidden;
  border-radius: var(--td-radius-large);
  // 头部按「面板自身宽度」而不是视口宽度断档位
  container-type: inline-size;
}

.t-drawer.ai-chat-drawer .t-drawer__header {
  align-items: flex-start;
  min-height: auto;
  // 主题层（仓库根 theme.css）用 #app .t-drawer__header 的 ID 级选择器在手机上塞了 48px 右内边距，
  // 抽屉又是 teleport 渲染的，只能 !important 盖掉，否则头部标题会被挤成「AI ...」
  padding: 10px var(--td-comp-paddingLR-l) 8px !important;
  border-radius: var(--td-radius-large) var(--td-radius-large) 0 0;
}

.t-drawer.ai-chat-drawer .t-drawer__body {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-height: 0;
  padding: 0 !important;
  overflow: hidden;
}

.t-drawer.ai-chat-drawer .t-drawer__footer {
  // 同理：theme.css 在手机上把 #app .t-drawer__footer 设成两列 grid，
  // 不改回 block 的话输入框只能拿到半列宽（≈175px），发送按钮被挤到旁边。
  display: block !important;
  grid-template-columns: none !important;
  padding: 8px var(--td-comp-paddingLR-l) 10px !important;
  background: var(--td-bg-color-container);
}

// 768 及以下与主题层的 #app .t-drawer__content-wrapper{width:100%!important} 对齐：整屏抽屉
@media (max-width: @screen-sm-rem) {
  .t-drawer.ai-chat-drawer .t-drawer__content-wrapper {
    top: 0;
    height: 100%;
    border-radius: 0;
  }

  .t-drawer.ai-chat-drawer .t-drawer__header {
    padding: calc(10px + env(safe-area-inset-top, 0px)) 12px 8px !important;
    border-radius: 0;
  }

  .t-drawer.ai-chat-drawer .t-drawer__body {
    max-height: none;
  }

  .t-drawer.ai-chat-drawer .t-drawer__footer {
    padding: 8px 12px calc(10px + env(safe-area-inset-bottom, 0px)) !important;
  }
}
</style>
