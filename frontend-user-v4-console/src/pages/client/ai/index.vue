<template>
  <section ref="rootRef" class="client-ai" :style="{ '--ai-page-height': pageHeight }">
    <!-- <768：会话列表收成折叠面板，用这一条工具条开合 -->
    <div class="client-ai__bar">
      <t-button
        class="client-ai__bar-toggle"
        variant="outline"
        shape="round"
        :aria-expanded="listOpen"
        @click="listOpen = !listOpen"
      >
        <template #icon><history-icon /></template>
        <span class="client-ai__bar-label">{{ $t('ai.conversation.list') }}</span>
        <span v-if="total" class="client-ai__bar-count">{{ total }}</span>
      </t-button>
    </div>

    <aside class="client-ai__side" :class="{ 'client-ai__side--open': listOpen }">
      <header class="client-ai__side-head">
        <span class="client-ai__side-title">{{ $t('ai.conversation.list') }}</span>
        <t-button
          class="ai-icon-btn"
          shape="square"
          variant="text"
          :style="iconStyle"
          :aria-label="$t('ai.newConversation')"
          :title="$t('ai.newConversation')"
          @click="handleCreate"
        >
          <template #icon><add-icon /></template>
        </t-button>
      </header>

      <p v-if="!conversations.length" class="client-ai__side-empty">{{ $t('ai.conversation.empty') }}</p>

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
            <div class="client-ai__item-actions client-ai__item-actions--static">
              <t-button class="client-ai__text-btn" theme="primary" variant="text" @click="submitRename">
                {{ $t('ai.conversation.renameSave') }}
              </t-button>
              <t-button class="client-ai__text-btn" variant="text" @click="cancelRename">
                {{ $t('ai.conversation.renameCancel') }}
              </t-button>
            </div>
          </div>

          <template v-else>
            <button type="button" class="client-ai__item-main" @click="selectConversation(item.id)">
              <span class="client-ai__item-title">{{ item.title || $t('ai.conversation.untitled') }}</span>
              <span class="client-ai__item-meta">
                {{ $t('ai.conversation.messageCount', { count: item.message_count }) }}
                <template v-if="item.last_message_at"> · {{ formatShortDateTime(item.last_message_at) }}</template>
              </span>
            </button>

            <div class="client-ai__item-actions">
              <t-button
                class="ai-icon-btn"
                shape="square"
                variant="text"
                :style="iconStyle"
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
                  class="ai-icon-btn"
                  shape="square"
                  variant="text"
                  :style="iconStyle"
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
    </aside>

    <section class="client-ai__chat">
      <header class="client-ai__chat-head">
        <ai-robot-avatar :size="22" class="client-ai__chat-logo" />
        <span class="client-ai__chat-title">{{ conversationTitle || $t('ai.title') }}</span>
        <div class="client-ai__chat-actions">
          <ai-permission-switch
            :model-value="store.permissionMode"
            :allowed-modes="store.allowedModes"
            :disabled="busy"
            @update:model-value="handlePermissionChange"
          />
          <t-button
            class="client-ai__collapse"
            variant="outline"
            :shape="isMobile ? 'square' : 'rectangle'"
            :style="iconStyle"
            @click="handleCollapse"
          >
            <template #icon><fullscreen-exit-icon /></template>
            <span v-if="!isMobile" class="client-ai__collapse-label">{{ $t('ai.collapse') }}</span>
          </t-button>
        </div>
      </header>

      <ai-message-list :messages="store.messages" />

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
    </section>
  </section>
</template>
<script setup lang="ts">
import type { AiConversation, AiPermissionMode } from '@turaidc/shared/runtime';
import {
  AddIcon,
  DeleteIcon,
  EditIcon,
  FullscreenExitIcon,
  HistoryIcon,
  SendIcon,
  StopCircleIcon,
} from 'tdesign-icons-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import AiMessageList from '@/components/ai/AiMessageList.vue';
import AiPermissionSwitch from '@/components/ai/AiPermissionSwitch.vue';
import AiRobotAvatar from '@/components/ai/AiRobotAvatar.vue';
import { useDeviceLayout } from '@/composables/useDeviceLayout';
import { useAiStore } from '@/store/modules/ai';
import { formatShortDateTime } from '@/utils/format';

/** 会话列表每页条数，与 createAiClient 默认分页保持一致 */
const PAGE_SIZE = 20;
const DASHBOARD_PATH = '/client/dashboard';

const router = useRouter();
const route = useRoute();
const store = useAiStore();
const { isMobile } = useDeviceLayout();

/**
 * 尺寸基线由项目全局样式决定（src/style/reset.less 里 button 最小 32×32、≤768px 40×40；
 * theme.css 的 #app .t-button--shape-square 给方形按钮定宽）。这里只补移动端 40px 宽度，
 * 保证方形图标按钮在手机上仍是正方形。
 */
const iconStyle = computed(() => (isMobile.value ? { width: '2.5rem', minWidth: '2.5rem' } : {}));

/** 发送/停止：桌面宽度 ≥88px 带文字，移动端收成 40×40 纯图标 */
const sendStyle = computed(() =>
  isMobile.value ? { width: '2.5rem', minWidth: '2.5rem', padding: '0' } : { minWidth: '88px' },
);

const rootRef = ref<HTMLElement | null>(null);
/** 页面可用高度：由「视口高 − 顶部导航/标签栏占位」实测得出，避免写死魔法数字 */
const pageHeight = ref('calc(100vh - 12rem)');

const page = ref(1);
const draft = ref('');
const listOpen = ref(false);
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

// —— 高度自适应：页面在 .tdesign-starter-layout 这个滚动容器里，
//    先按「容器可见下边界 − 页面顶边 − 容器底部内边距」估一次，
//    再用容器的实际溢出量做最多 3 轮收缩：布局层的标签栏/滚动条占位随断点变化，
//    纯算公式会差十几像素，量一次溢出就能贴死，保证外层不出滚动条 ——
let measureRaf = 0;
let adjustRaf = 0;
let adjustPass = 0;

const getScroller = (): HTMLElement | null =>
  (rootRef.value?.closest('.tdesign-starter-layout') as HTMLElement | null) ?? null;

const adjust = (): void => {
  const element = rootRef.value;
  const scroller = getScroller();
  if (!element || !scroller || adjustPass >= 3) {
    return;
  }
  const overflow = scroller.scrollHeight - scroller.clientHeight;
  if (overflow <= 0) {
    return;
  }
  adjustPass += 1;
  const current = Number.parseFloat(pageHeight.value) || 0;
  pageHeight.value = `${Math.max(360, Math.floor(current - overflow - 2))}px`;
  adjustRaf = requestAnimationFrame(adjust);
};

const measure = (): void => {
  const element = rootRef.value;
  if (!element) {
    return;
  }
  const scroller = getScroller();
  const container = element.parentElement;
  const padBottom = container ? Number.parseFloat(getComputedStyle(container).paddingBottom) || 0 : 0;
  // rect.top 会随容器滚动变化，加上 scrollTop 才是与滚动无关的固定偏移
  const top = element.getBoundingClientRect().top + (scroller?.scrollTop ?? 0);
  const bottomLimit = scroller ? scroller.getBoundingClientRect().top + scroller.clientHeight : window.innerHeight;
  pageHeight.value = `${Math.max(360, Math.floor(bottomLimit - top - padBottom - 4))}px`;
  adjustPass = 0;
  cancelAnimationFrame(adjustRaf);
  adjustRaf = requestAnimationFrame(adjust);
};

const scheduleMeasure = (): void => {
  cancelAnimationFrame(measureRaf);
  measureRaf = requestAnimationFrame(measure);
};

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
  listOpen.value = false;
};

const selectConversation = (id: number): void => {
  listOpen.value = false;
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
  scheduleMeasure();
  window.addEventListener('resize', scheduleMeasure);
  // 顶部标签栏/面包屑是异步渲染的，字体也可能晚到位，再补两次测量
  window.setTimeout(scheduleMeasure, 120);
  window.setTimeout(scheduleMeasure, 480);
  void document.fonts?.ready.then(scheduleMeasure);

  await store.ensureReady();
  await loadConversations();

  // 从浮窗「最近对话」跳过来时带 ?conversation=id，直接打开那一条继续聊
  const target = Number.parseInt(String(route.query.conversation ?? ''), 10);
  if (Number.isFinite(target) && target > 0) {
    await store.openConversation(target);
  }
});

onBeforeUnmount(() => {
  window.removeEventListener('resize', scheduleMeasure);
  cancelAnimationFrame(measureRaf);
  cancelAnimationFrame(adjustRaf);
});
</script>
<style scoped lang="less">
.client-ai {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  gap: 16px;
  // 只占视口可见区域：高度由脚本实测（顶部导航 + 标签栏 + 内边距），页面不出外层滚动条
  height: var(--ai-page-height, calc(100vh - 12rem));
  min-height: 0;
  overflow: hidden;
}

.client-ai__bar {
  display: none;
}

// —— 左栏 ——
.client-ai__side,
.client-ai__chat {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  overflow: hidden;
  background: var(--td-bg-color-container);
  border: 1px solid var(--td-border-color);
  border-radius: var(--td-radius-large);
}

.client-ai__side-head {
  display: flex;
  flex: 0 0 auto;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
  min-height: 48px;
  padding: 0 8px 0 16px;
  border-bottom: 1px solid var(--td-component-stroke);
}

.client-ai__side-title {
  overflow: hidden;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-ai__side-empty {
  margin: 0;
  padding: 24px 16px;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
  text-align: center;
}

.client-ai__list {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 2px;
  min-height: 0;
  padding: 8px;
  margin: 0;
  overflow-y: auto;
  list-style: none;
}

.client-ai__item {
  display: flex;
  flex: 0 0 auto;
  gap: 4px;
  align-items: center;
  // 行高一致：两行（标题 20 + 副标题 18 + 上下 9）≈ 56
  min-height: 3.5rem;
  padding: 4px 4px 4px 8px;
  border-radius: var(--td-radius-medium);

  &:hover {
    background: var(--td-bg-color-container-hover);
  }
}

.client-ai__item--active {
  background: var(--td-brand-color-light);
}

.client-ai__item-main {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  padding: 0;
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;
}

.client-ai__item-title {
  overflow: hidden;
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-ai__item-meta {
  overflow: hidden;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-ai__item-actions {
  display: flex;
  flex: 0 0 auto;
  gap: 2px;
  align-items: center;
  // 平时收起，悬停/聚焦/选中时出现；触屏没有 hover，一律显示
  opacity: 0;
}

.client-ai__item:hover .client-ai__item-actions,
.client-ai__item:focus-within .client-ai__item-actions,
.client-ai__item--active .client-ai__item-actions {
  opacity: 1;
}

.client-ai__item-actions--static {
  opacity: 1;
}

.client-ai__item-editing {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.client-ai__text-btn {
  height: 2rem;
  padding: 0 8px;
  font: var(--td-font-body-small);
}

.client-ai__pagination {
  display: flex;
  flex: 0 0 auto;
  justify-content: center;
  padding: 8px;
  border-top: 1px solid var(--td-component-stroke);
}

// —— 右栏 ——
.client-ai__chat-head {
  display: flex;
  flex: 0 0 auto;
  gap: 8px;
  align-items: center;
  min-width: 0;
  min-height: 48px;
  padding: 0 8px 0 16px;
  border-bottom: 1px solid var(--td-component-stroke);
}

.client-ai__chat-logo {
  color: var(--td-brand-color);
}

.client-ai__chat-title {
  flex: 1 1 auto;
  overflow: hidden;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-small);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-ai__chat-actions {
  display: flex;
  flex: 0 0 auto;
  gap: 6px;
  align-items: center;
}

.client-ai__collapse {
  white-space: nowrap;
}

// —— 输入区：输入框与发送按钮同一行 ——
.ai-composer {
  display: flex;
  flex: 0 0 auto;
  flex-direction: column;
  gap: 6px;
  width: 100%;
  padding: 8px 12px 10px;
  border-top: 1px solid var(--td-component-stroke);
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
  // 只有 !important 能把输入框托到 40px 起跳；超过 168px 改为内部滚动
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

// 命中区由项目全局基线保证（reset.less: 32×32，≤768px 40×40），这里只管配色与图标字号
.ai-icon-btn {
  display: inline-flex;

  :deep(.t-icon) {
    font-size: 1rem;
  }
}

@media (width <= 1023px) {
  .client-ai {
    grid-template-columns: 200px minmax(0, 1fr);
  }
}

@media (hover: none) {
  .client-ai__item-actions {
    opacity: 1;
  }
}

@media (max-width: @screen-sm-rem) {
  .client-ai {
    // 单列：工具条 + 可折叠会话面板 + 对话区
    display: flex;
    flex-direction: column;
    gap: 12px;
    height: var(--ai-page-height, calc(100vh - 8rem));
  }

  .client-ai__bar {
    display: flex;
    flex: 0 0 auto;
    gap: 8px;
    align-items: center;
    min-width: 0;
  }

  .client-ai__bar-toggle {
    max-width: 100%;
  }

  .client-ai__bar-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .client-ai__bar-count {
    margin-left: 4px;
    color: var(--td-text-color-placeholder);
  }

  .client-ai__side {
    display: none;
    flex: 0 0 auto;
    max-height: 42%;
  }

  .client-ai__side--open {
    display: flex;
  }

  .client-ai__chat {
    flex: 1 1 auto;
    min-height: 0;
  }

  .client-ai__chat-head {
    padding: 0 8px 0 12px;
  }

  .client-ai__item {
    min-height: 3.25rem;
  }
}
</style>
