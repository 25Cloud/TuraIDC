import type { AiChatStore, AiPendingAction, AiPermissionMode } from '@turaidc/shared/runtime';
import { useAiChat } from '@turaidc/shared/runtime';
import { defineStore } from 'pinia';

import aiClient from '@/api/ai';
import { getClientToken } from '@/app/runtime/session';

/** 浮窗形态：drawer=页面右下角浮窗，page=独立完整页。两者共用同一份会话状态。 */
export type AiPanelMode = 'drawer' | 'page';

/**
 * 共享状态机单例。
 *
 * 用 options store 而不是把 useAiChat 的返回值摊进 setup store：
 * 那一大坨 ref/computed + 动作直接展开会让 Pinia 的类型推导爆深（TS2589），
 * 也会把消息体写进 store state 被持久化。这里模块级持有实例，
 * store 只按需挂出只读 getter 与动作转发。
 */
let chatStore: AiChatStore | null = null;

const getChatStore = (): AiChatStore => {
  if (!chatStore) {
    chatStore = useAiChat(aiClient, { channel: 'console', autoLoad: false });
  }
  return chatStore;
};

/** bootstrap 失败后允许下次挂载重试，所以不能把重试开关写进 state */
let readyPromise: Promise<void> | null = null;

/** 首次初始化用的 token：退出登录是软跳转（不刷新页面），换账号要靠它识别 */
let activeToken: string | null = null;

export const useAiStore = defineStore('ai', {
  state: () => ({
    /** 浮窗开关 */
    open: false,
    /** 当前展示形态 */
    panelMode: 'drawer' as AiPanelMode,
  }),
  getters: {
    /** 当前会话 id：浮窗与独立页共读，用于列表高亮与判断能否续聊 */
    activeConversationId: (): number | null => getChatStore().conversation.value?.id ?? null,
    messages: () => getChatStore().messages.value,
    conversation: () => getChatStore().conversation.value,
    conversationPage: () => getChatStore().conversationPage.value,
    permissionMode: (): AiPermissionMode => getChatStore().permissionMode.value,
    allowedModes: (): AiPermissionMode[] => getChatStore().allowedModes.value,
    bootstrap: () => getChatStore().bootstrap.value,
    quota: () => getChatStore().quota.value,
    subject: () => getChatStore().subject.value,
    loading: () => getChatStore().loading.value,
    streaming: () => getChatStore().streaming.value,
    sending: () => getChatStore().sending.value,
    error: () => getChatStore().error.value,
    ready: () => getChatStore().ready.value,
    enabled: () => getChatStore().enabled.value,
    canSend: () => getChatStore().canSend.value,
  },
  actions: {
    /**
     * 首次挂载按需初始化。
     *
     * 游客页（登录 / 注册 / 找回密码）没有 client_token，直接跳过：既避免必然失败的 401，
     * 也避免在未登录状态下消耗后端配额。
     */
    async ensureReady(): Promise<void> {
      const token = getClientToken();
      if (!token) {
        // 已登出或游客页：清掉内存里的上一份会话。消息只存在内存中，
        // 不会因为 401 被后端打回，必须在这里主动清，否则登录页还挂着别人的对话。
        if (activeToken) {
          activeToken = null;
          readyPromise = null;
          getChatStore().startNewConversation();
          this.open = false;
        }
        return;
      }
      const chat = getChatStore();
      if (activeToken && activeToken !== token) {
        // 退出登录走软跳转，内存里还留着上一位用户的消息与会话 id；
        // token 变了必须先清空，否则同一标签页换账号会看到别人的对话。
        chat.startNewConversation();
        readyPromise = null;
      }
      activeToken = token;
      if (!readyPromise) {
        readyPromise = chat.initialize().then(() => {
          if (!chat.enabled.value && chat.error.value) {
            readyPromise = null;
          }
        });
      }
      await readyPromise;
    },

    /** 打开浮窗（独立页「收起为浮窗」也走这里，会话保持不变） */
    openDrawer(): void {
      this.panelMode = 'drawer';
      this.open = true;
    },

    closeDrawer(): void {
      this.open = false;
    },

    toggleDrawer(): void {
      this.open = !this.open;
    },

    /** 进入独立页形态：关掉浮窗，避免两个入口同时挂着一份对话 */
    enterPageMode(): void {
      this.panelMode = 'page';
      this.open = false;
    },

    openConversation(id: number): Promise<void> {
      return getChatStore().openConversation(id);
    },

    refreshConversations(page = 1): Promise<void> {
      return getChatStore().refreshConversations(page);
    },

    startNewConversation(): void {
      getChatStore().startNewConversation();
    },

    setPermissionMode(mode: AiPermissionMode): Promise<void> {
      return getChatStore().setPermissionMode(mode);
    },

    send(text: string): Promise<void> {
      return getChatStore().send(text);
    },

    stop(): void {
      getChatStore().stop();
    },

    confirmAction(action: AiPendingAction): Promise<boolean> {
      return getChatStore().confirmAction(action);
    },

    rejectAction(action: AiPendingAction): Promise<boolean> {
      return getChatStore().rejectAction(action);
    },

    removeConversation(id: number): Promise<void> {
      return getChatStore().removeConversation(id);
    },

    renameConversation(id: number, title: string): Promise<void> {
      return getChatStore().renameConversation(id, title);
    },
  },
  persist: {
    key: 'client_ai',
    // 只持久化浮窗偏好。消息与会话内容不进 localStorage：
    // 续聊靠 composer 写进 sessionStorage 的 conversation_id + 后端会话详情，
    // 关掉浏览器就清空，不在本机留对话正文。
    pick: ['open', 'panelMode'],
  },
});
