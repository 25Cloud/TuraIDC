/**
 * AI 对话状态机：浮窗与独立页共用同一份逻辑，三端只替换外观。
 *
 * 设计约束：
 * - 不依赖任何组件库，只用 Vue 组合式 API，因此 www(Element Plus) 与 console/admin(TDesign) 都能消费。
 * - 流式优先，失败自动回退非流式；用户点「停止」时保留已生成内容。
 * - 写操作永远走 pending_action → confirm/reject，前端不得自己执行任何业务动作。
 */
import { computed, ref, shallowRef } from 'vue';

import { AiApiError, type AiClient } from './client';
import { aiContext } from './context';
import type {
  AiBootstrap,
  AiChannel,
  AiChatMessage,
  AiConversation,
  AiConversationDetail,
  AiPage,
  AiPendingAction,
  AiPermissionMode,
  AiSubject,
  AiToolRun,
} from './types';

export interface UseAiChatOptions {
  channel: AiChannel;
  /** 会话续接用的本地键，默认 turaidc:ai:<channel> */
  storageKey?: string;
  /** 是否在创建后立刻拉 bootstrap，默认 true */
  autoLoad?: boolean;
  /** 每页会话数，默认 20 */
  pageSize?: number;
}

const RISK_LABELS: Record<string, string> = {
  read: '查询',
  write: '操作',
  dangerous: '高危操作',
};

export function useAiChat(client: AiClient, options: UseAiChatOptions) {
  const storageKey = options.storageKey ?? `turaidc:ai:${options.channel}`;
  const pageSize = options.pageSize ?? 20;

  const bootstrap = shallowRef<AiBootstrap | null>(null);
  const conversation = shallowRef<AiConversation | null>(null);
  const conversationPage = shallowRef<AiPage<AiConversation> | null>(null);
  const messages = ref<AiChatMessage[]>([]);
  const permissionMode = ref<AiPermissionMode>('confirm');
  const loading = ref(false);
  const streaming = ref(false);
  const sending = ref(false);
  const error = ref('');
  const ready = ref(false);

  let abortController: AbortController | null = null;
  let localSeed = 0;

  const enabled = computed(() => bootstrap.value?.enabled === true);
  const quota = computed(() => bootstrap.value?.quota ?? null);
  const tools = computed(() => bootstrap.value?.tools ?? []);
  const allowedModes = computed<AiPermissionMode[]>(
    () => bootstrap.value?.allowed_permission_modes ?? ['read_only', 'confirm'],
  );
  const pendingActions = computed<AiPendingAction[]>(() =>
    messages.value.flatMap((message) => message.pending_actions),
  );
  const subject = computed<AiSubject | null>(() => aiContext.subject.value);
  const canSend = computed(() => enabled.value && !sending.value && !streaming.value);

  const nextLocalId = (): string => {
    localSeed += 1;
    return `local-${Date.now()}-${localSeed}`;
  };

  const readStoredConversationId = (): number | null => {
    try {
      const raw = globalThis.sessionStorage?.getItem(storageKey);
      const parsed = raw ? Number.parseInt(raw, 10) : Number.NaN;
      return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    } catch {
      return null;
    }
  };

  const storeConversationId = (id: number | null): void => {
    try {
      if (id && id > 0) {
        globalThis.sessionStorage?.setItem(storageKey, String(id));
      } else {
        globalThis.sessionStorage?.removeItem(storageKey);
      }
    } catch {
      // 隐私模式下 sessionStorage 不可用，忽略即可
    }
  };

  const pushMessage = (message: Omit<AiChatMessage, 'id'> & { id?: string }): AiChatMessage => {
    const row: AiChatMessage = { id: message.id ?? nextLocalId(), ...message } as AiChatMessage;
    messages.value = [...messages.value, row];
    return row;
  };

  const patchMessage = (id: string, patch: Partial<AiChatMessage>): void => {
    messages.value = messages.value.map((item) => (item.id === id ? { ...item, ...patch } : item));
  };

  const toChatMessage = (row: AiConversationDetail['messages'][number]): AiChatMessage => {
    const runs: AiToolRun[] = (row.tool_calls ?? []).map((call) => ({
      name: call.name,
      risk: (call.risk as AiToolRun['risk']) ?? 'read',
      status: (call.status as AiToolRun['status']) ?? 'done',
      summary: call.summary ?? '',
    }));
    return {
      id: `db-${row.id}`,
      role: row.role === 'user' ? 'user' : row.role === 'assistant' ? 'assistant' : 'system',
      content: row.content ?? '',
      tool_runs: runs,
      pending_actions: [],
      created_at: row.created_at,
    };
  };

  const describeError = (err: unknown): string => {
    if (err instanceof AiApiError) {
      return err.message;
    }
    const message = (err as Error)?.message;
    return message && message.length < 120 ? message : 'AI 服务暂不可用，请稍后再试';
  };

  const loadBootstrap = async (): Promise<void> => {
    try {
      const data = await client.bootstrap(options.channel);
      bootstrap.value = data;
      permissionMode.value = data.permission_mode;
      error.value = '';
    } catch (err) {
      error.value = describeError(err);
    } finally {
      ready.value = true;
    }
  };

  const applyDetail = (detail: AiConversationDetail): void => {
    conversation.value = detail.conversation;
    permissionMode.value = detail.conversation.permission_mode;
    messages.value = detail.messages.map(toChatMessage);
    const pending = detail.pending_actions ?? [];
    if (pending.length > 0) {
      pushMessage({
        role: 'system',
        content: '',
        tool_runs: [],
        pending_actions: pending,
        created_at: null,
      });
    }
  };

  const openConversation = async (id: number): Promise<void> => {
    loading.value = true;
    error.value = '';
    try {
      const detail = await client.getConversation(id);
      applyDetail(detail);
      storeConversationId(detail.conversation.id);
    } catch (err) {
      error.value = describeError(err);
      storeConversationId(null);
    } finally {
      loading.value = false;
    }
  };

  const startNewConversation = (): void => {
    conversation.value = null;
    messages.value = [];
    storeConversationId(null);
    error.value = '';
  };

  const refreshConversations = async (page = 1): Promise<void> => {
    try {
      conversationPage.value = await client.listConversations({ page, page_size: pageSize });
    } catch (err) {
      error.value = describeError(err);
    }
  };

  const setPermissionMode = async (mode: AiPermissionMode): Promise<void> => {
    if (!allowedModes.value.includes(mode)) {
      return;
    }
    permissionMode.value = mode;
    const id = conversation.value?.id;
    if (id) {
      try {
        const updated = await client.updateConversation(id, { permission_mode: mode });
        conversation.value = updated;
      } catch (err) {
        error.value = describeError(err);
      }
    }
  };

  const finalizeStream = (
    messageId: string,
    payload: {
      content: string;
      tool_runs: AiToolRun[];
      pending_actions: AiPendingAction[];
      conversation_id: number;
      permission_mode: AiPermissionMode;
    },
  ): void => {
    patchMessage(messageId, {
      content: payload.content,
      tool_runs: payload.tool_runs,
      pending_actions: payload.pending_actions,
      streaming: false,
    });
    if (payload.conversation_id > 0 && conversation.value?.id !== payload.conversation_id) {
      conversation.value = {
        ...(conversation.value ?? {
          id: payload.conversation_id,
          title: '',
          channel: options.channel,
          permission_mode: payload.permission_mode,
          message_count: 0,
          last_message_at: null,
          created_at: null,
        }),
        id: payload.conversation_id,
        permission_mode: payload.permission_mode,
      };
      storeConversationId(payload.conversation_id);
    }
    if (bootstrap.value) {
      permissionMode.value = payload.permission_mode;
    }
  };

  const send = async (rawText: string): Promise<void> => {
    const text = rawText.trim();
    if (text === '' || !canSend.value) {
      return;
    }
    error.value = '';
    sending.value = true;
    pushMessage({ role: 'user', content: text, tool_runs: [], pending_actions: [], created_at: null });

    const assistant = pushMessage({
      role: 'assistant',
      content: '',
      tool_runs: [],
      pending_actions: [],
      created_at: null,
      streaming: true,
    });

    const payload = {
      conversation_id: conversation.value?.id ?? readStoredConversationId(),
      channel: options.channel,
      message: text,
      permission_mode: permissionMode.value,
      subject: aiContext.subject.value,
    };

    abortController = new AbortController();
    streaming.value = true;

    try {
      const result = await client.sendMessageStream(
        payload,
        {
          onMeta: (meta) => {
            if (meta.conversation_id > 0) {
              storeConversationId(meta.conversation_id);
            }
          },
          onDelta: (chunk) => {
            const current = messages.value.find((item) => item.id === assistant.id);
            patchMessage(assistant.id, { content: (current?.content ?? '') + chunk });
          },
          onTool: (run) => {
            const current = messages.value.find((item) => item.id === assistant.id);
            const runs = [...(current?.tool_runs ?? [])];
            const index = runs.findIndex((item) => item.name === run.name);
            if (index >= 0) {
              runs[index] = run;
            } else {
              runs.push(run);
            }
            patchMessage(assistant.id, { tool_runs: runs });
          },
          onPendingAction: (action) => {
            const current = messages.value.find((item) => item.id === assistant.id);
            patchMessage(assistant.id, {
              pending_actions: [...(current?.pending_actions ?? []), action],
            });
          },
          onError: (payloadError) => {
            error.value = payloadError.message;
          },
        },
        abortController.signal,
      );

      if (result) {
        finalizeStream(assistant.id, result);
      } else {
        patchMessage(assistant.id, { streaming: false });
      }
    } catch (err) {
      // 流式不可用（网关缓冲、超时、内容类型不对）时回退一次性响应，避免用户看到空白气泡
      try {
        const result = await client.sendMessage({ ...payload, conversation_id: conversation.value?.id ?? null });
        finalizeStream(assistant.id, result);
      } catch (fallbackError) {
        patchMessage(assistant.id, {
          streaming: false,
          error: true,
          content: describeError(fallbackError),
        });
        error.value = describeError(fallbackError);
      }
    } finally {
      streaming.value = false;
      sending.value = false;
      abortController = null;
    }
  };

  const stop = (): void => {
    abortController?.abort();
    abortController = null;
    streaming.value = false;
    sending.value = false;
    messages.value = messages.value.map((item) =>
      item.streaming ? { ...item, streaming: false } : item,
    );
  };

  const resolveAction = (actionId: number, status: 'done' | 'rejected'): void => {
    messages.value = messages.value.map((message) => {
      const next = message.pending_actions.filter((action) => action.id !== actionId);
      const runs = message.tool_runs.map((run) =>
        run.status === 'pending' ? { ...run, status } : run,
      );
      return { ...message, pending_actions: next, tool_runs: runs };
    });
  };

  const confirmAction = async (action: AiPendingAction): Promise<boolean> => {
    error.value = '';
    try {
      const result = await client.confirmAction(action.id, action.confirm_token);
      resolveAction(action.id, result.status === 'rejected' ? 'rejected' : 'done');
      pushMessage({
        role: 'system',
        content: result.summary,
        tool_runs: [],
        pending_actions: [],
        created_at: null,
      });
      return true;
    } catch (err) {
      error.value = describeError(err);
      return false;
    }
  };

  const rejectAction = async (action: AiPendingAction): Promise<boolean> => {
    error.value = '';
    try {
      const result = await client.rejectAction(action.id);
      resolveAction(action.id, 'rejected');
      pushMessage({
        role: 'system',
        content: result.summary,
        tool_runs: [],
        pending_actions: [],
        created_at: null,
      });
      return true;
    } catch (err) {
      error.value = describeError(err);
      return false;
    }
  };

  const removeConversation = async (id: number): Promise<void> => {
    try {
      await client.deleteConversation(id);
      if (conversation.value?.id === id) {
        startNewConversation();
      }
      await refreshConversations();
    } catch (err) {
      error.value = describeError(err);
    }
  };

  const renameConversation = async (id: number, title: string): Promise<void> => {
    const trimmed = title.trim();
    if (trimmed === '') {
      return;
    }
    try {
      const updated = await client.updateConversation(id, { title: trimmed });
      if (conversation.value?.id === id) {
        conversation.value = updated;
      }
      await refreshConversations();
    } catch (err) {
      error.value = describeError(err);
    }
  };

  const initialize = async (): Promise<void> => {
    await loadBootstrap();
    if (!enabled.value) {
      return;
    }
    const stored = readStoredConversationId();
    if (stored) {
      await openConversation(stored);
    }
  };

  if (options.autoLoad !== false) {
    void initialize();
  }

  /** 退出登录或切换账号时清空内存状态，避免把上一个账号的会话带过去 */
  const reset = (): void => {
    abortController?.abort();
    abortController = null;
    conversation.value = null;
    conversationPage.value = null;
    messages.value = [];
    error.value = '';
    streaming.value = false;
    sending.value = false;
    storeConversationId(null);
  };

  return {
    reset,
    bootstrap,
    conversation,
    conversationPage,
    messages,
    permissionMode,
    allowedModes,
    pendingActions,
    subject,
    tools,
    quota,
    loading,
    streaming,
    sending,
    error,
    ready,
    enabled,
    canSend,
    riskLabel: (risk: string): string => RISK_LABELS[risk] ?? '操作',
    initialize,
    loadBootstrap,
    openConversation,
    refreshConversations,
    startNewConversation,
    setPermissionMode,
    send,
    stop,
    confirmAction,
    rejectAction,
    removeConversation,
    renameConversation,
  };
}

export type AiChatStore = ReturnType<typeof useAiChat>;
