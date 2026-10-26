/**
 * AI 端点客户端：三端共用，只依赖原生 fetch。
 *
 * 统一响应外层仍是 `{code,message,data,timestamp}`；流式端点走 text/event-stream，
 * 事件类型见 sendMessageStream。认证头由调用方注入（三端 token 存放方式不同）。
 */
import { parseSseJson, readSseStream } from './sse';
import type {
  AiBootstrap,
  AiChannel,
  AiConfirmResult,
  AiConversation,
  AiConversationDetail,
  AiPage,
  AiPendingAction,
  AiPermissionMode,
  AiSendPayload,
  AiSendResult,
  AiToolRun,
  AiUsage,
} from './types';

export class AiApiError extends Error {
  code: number;

  constructor(code: number, message: string) {
    super(message);
    this.name = 'AiApiError';
    this.code = code;
  }
}

export interface AiClientOptions {
  /** API 根地址，例如 https://api.example.com/api；不要带尾斜杠 */
  baseURL: string;
  /** 取当前登录 token，未登录返回 null */
  getToken: () => string | null;
  /** 取当前语言，写入 X-Locale */
  locale?: () => string | null;
  /** 路径前缀，默认用户端；管理端传 '/v2/admin/ai' */
  prefix?: string;
  fetchImpl?: typeof fetch;
}

export interface AiStreamHandlers {
  onMeta?: (payload: { conversation_id: number; message_id: number | null }) => void;
  /** 推理模型的思考增量（DeepSeek reasoning_content / Claude thinking） */
  onReasoning?: (text: string) => void;
  onDelta?: (text: string) => void;
  onTool?: (run: AiToolRun) => void;
  onPendingAction?: (action: AiPendingAction) => void;
  onDone?: (payload: {
    conversation_id: number;
    message_id: number | null;
    content: string;
    reasoning?: string;
    tool_runs: AiToolRun[];
    pending_actions: AiPendingAction[];
    permission_mode: AiPermissionMode;
    usage: AiUsage;
    quota: AiBootstrap['quota'];
  }) => void;
  onError?: (payload: { code: number; message: string }) => void;
}

export interface AiClient {
  bootstrap: (channel?: AiChannel) => Promise<AiBootstrap>;
  listConversations: (params?: { page?: number; page_size?: number }) => Promise<AiPage<AiConversation>>;
  createConversation: (payload?: {
    title?: string;
    channel?: AiChannel;
    permission_mode?: AiPermissionMode;
  }) => Promise<AiConversation>;
  getConversation: (id: number) => Promise<AiConversationDetail>;
  updateConversation: (
    id: number,
    payload: { title?: string; permission_mode?: AiPermissionMode },
  ) => Promise<AiConversation>;
  deleteConversation: (id: number) => Promise<null>;
  sendMessage: (payload: AiSendPayload) => Promise<AiSendResult>;
  sendMessageStream: (
    payload: AiSendPayload,
    handlers: AiStreamHandlers,
    signal?: AbortSignal,
  ) => Promise<AiSendResult | null>;
  confirmAction: (id: number, confirmToken: string) => Promise<AiConfirmResult>;
  rejectAction: (id: number) => Promise<AiConfirmResult>;
}

export function createAiClient(options: AiClientOptions): AiClient {
  const doFetch: typeof fetch = options.fetchImpl ?? ((input, init) => fetch(input, init));
  const base = String(options.baseURL || '').replace(/\/+$/, '');
  const prefix = (options.prefix ?? '/v2/client/ai').replace(/\/+$/, '');
  const url = (path: string) => `${base}${prefix}${path}`;

  const buildHeaders = (withBody: boolean, accept = 'application/json'): Record<string, string> => {
    const headers: Record<string, string> = { Accept: accept };
    if (withBody) {
      headers['Content-Type'] = 'application/json';
    }
    const token = options.getToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
    const locale = options.locale?.();
    if (locale) {
      headers['X-Locale'] = locale;
    }
    return headers;
  };

  const request = async <T>(path: string, init?: RequestInit): Promise<T> => {
    let response: Response;
    try {
      response = await doFetch(url(path), {
        ...init,
        headers: buildHeaders(init?.body !== undefined, 'application/json'),
      });
    } catch (error) {
      throw new AiApiError(0, '网络连接失败，请检查网络后重试');
    }

    const text = await response.text();
    if (!text) {
      throw new AiApiError(response.status, 'AI 服务未返回内容');
    }
    let body: { code?: number; message?: string; data?: unknown };
    try {
      body = JSON.parse(text) as { code?: number; message?: string; data?: unknown };
    } catch {
      throw new AiApiError(response.status, 'AI 服务返回了无法解析的内容');
    }
    if (typeof body.code !== 'number') {
      throw new AiApiError(response.status, 'AI 服务返回结构异常');
    }
    if (body.code !== 0) {
      throw new AiApiError(body.code, body.message || 'AI 服务暂不可用');
    }
    return (body.data ?? null) as T;
  };

  const client: AiClient = {
    bootstrap: (channel) =>
      request<AiBootstrap>(`/bootstrap${channel ? `?channel=${encodeURIComponent(channel)}` : ''}`),

    listConversations: (params) => {
      const query = new URLSearchParams();
      query.set('page', String(params?.page ?? 1));
      query.set('page_size', String(params?.page_size ?? 20));
      return request<AiPage<AiConversation>>(`/conversations?${query.toString()}`);
    },

    createConversation: (payload) =>
      request<AiConversation>('/conversations', {
        method: 'POST',
        body: JSON.stringify(payload ?? {}),
      }),

    getConversation: (id) => request<AiConversationDetail>(`/conversations/${id}`),

    updateConversation: (id, payload) =>
      request<AiConversation>(`/conversations/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      }),

    deleteConversation: (id) =>
      request<null>(`/conversations/${id}`, {
        method: 'DELETE',
      }),

    sendMessage: (payload) =>
      request<AiSendResult>('/messages', {
        method: 'POST',
        body: JSON.stringify(payload),
      }),

    sendMessageStream: async (payload, handlers, signal) => {
      let response: Response;
      try {
        response = await doFetch(url('/messages/stream'), {
          method: 'POST',
          headers: buildHeaders(true, 'text/event-stream'),
          body: JSON.stringify(payload),
          signal,
        });
      } catch (error) {
        if ((error as Error)?.name === 'AbortError') {
          return null;
        }
        throw new AiApiError(0, '网络连接失败，请检查网络后重试');
      }

      const contentType = response.headers.get('content-type') || '';
      if (!response.ok || !contentType.includes('text/event-stream')) {
        // 后端拒绝流式（未启用、鉴权失败、PHP 缓冲配置缺失）时按普通 JSON 错误处理，
        // 由调用方决定是否回退到非流式端点。
        const text = await response.text();
        let message = 'AI 流式响应不可用';
        let code = response.status;
        try {
          const body = JSON.parse(text) as { code?: number; message?: string };
          if (typeof body.code === 'number') {
            code = body.code;
          }
          if (body.message) {
            message = body.message;
          }
        } catch {
          // 非 JSON，保留默认提示
        }
        throw new AiApiError(code, message);
      }

      let final: AiSendResult | null = null;

      await readSseStream(
        response,
        ({ event, data }) => {
          if (event === 'delta') {
            const parsed = parseSseJson<{ text?: string }>(data);
            if (parsed?.text) {
              handlers.onDelta?.(parsed.text);
            }
            return;
          }
          if (event === 'reasoning') {
            const parsed = parseSseJson<{ text?: string }>(data);
            if (parsed?.text) {
              handlers.onReasoning?.(parsed.text);
            }
            return;
          }
          if (event === 'meta') {
            const parsed = parseSseJson<{ conversation_id: number; message_id: number | null }>(data);
            if (parsed) {
              handlers.onMeta?.(parsed);
            }
            return;
          }
          if (event === 'tool') {
            const parsed = parseSseJson<AiToolRun>(data);
            if (parsed) {
              handlers.onTool?.(parsed);
            }
            return;
          }
          if (event === 'pending_action') {
            const parsed = parseSseJson<AiPendingAction>(data);
            if (parsed) {
              handlers.onPendingAction?.(parsed);
            }
            return;
          }
          if (event === 'done') {
            const parsed = parseSseJson<AiSendResult>(data);
            if (parsed) {
              final = parsed;
              handlers.onDone?.(parsed);
            }
            return;
          }
          if (event === 'error') {
            const parsed = parseSseJson<{ code: number; message: string }>(data);
            handlers.onError?.(parsed ?? { code: 50000, message: 'AI 响应中断' });
          }
        },
        signal,
      );

      return final;
    },

    confirmAction: (id, confirmToken) =>
      request<AiConfirmResult>(`/actions/${id}/confirm`, {
        method: 'POST',
        body: JSON.stringify({ confirm_token: confirmToken }),
      }),

    rejectAction: (id) =>
      request<AiConfirmResult>(`/actions/${id}/reject`, {
        method: 'POST',
        body: JSON.stringify({}),
      }),
  };

  return client;
}
