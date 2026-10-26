/**
 * SSE 解析：AI 流式响应统一走原生 fetch + ReadableStream。
 *
 * 为什么不复用各端 axios：三端拦截器都强制 `{code:0,data}` 信封、带 10~18s 超时、
 * 失败还会整请求重放，套在 text/event-stream 上必挂。原生 EventSource 又不能自定义
 * Authorization 头（token 存在 cookie 里），所以只能自己读流。
 */

export interface SseEvent {
  event: string
  data: string
}

/** 逐行解析 SSE 流，遇到空行派发一个事件。返回时代表流已正常结束。 */
export async function readSseStream(
  response: Response,
  onEvent: (event: SseEvent) => void,
  signal?: AbortSignal,
): Promise<void> {
  const reader = response.body?.getReader()
  if (!reader) {
    throw new Error('当前浏览器不支持流式响应');
  }

  const decoder = new TextDecoder('utf-8');
  let buffer = '';
  let eventName = 'message';
  let dataLines: string[] = [];

  const dispatch = () => {
    if (dataLines.length > 0) {
      onEvent({ event: eventName, data: dataLines.join('\n') });
    }
    eventName = 'message';
    dataLines = [];
  };

  try {
    for (;;) {
      if (signal?.aborted) {
        break;
      }
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });

      let lineEnd = buffer.indexOf('\n');
      while (lineEnd >= 0) {
        const line = buffer.slice(0, lineEnd).replace(/\r$/, '');
        buffer = buffer.slice(lineEnd + 1);
        lineEnd = buffer.indexOf('\n');

        if (line === '') {
          dispatch();
          continue;
        }
        if (line.startsWith(':')) {
          continue;
        }
        const colon = line.indexOf(':');
        const field = colon >= 0 ? line.slice(0, colon) : line;
        const raw = colon >= 0 ? line.slice(colon + 1) : '';
        const value2 = raw.startsWith(' ') ? raw.slice(1) : raw;
        if (field === 'event') {
          eventName = value2;
        } else if (field === 'data') {
          dataLines.push(value2);
        }
      }
    }
    if (buffer.trim() !== '') {
      const line = buffer.replace(/\r$/, '');
      if (line.startsWith('data:')) {
        const raw = line.slice(5);
        dataLines.push(raw.startsWith(' ') ? raw.slice(1) : raw);
      }
    }
    dispatch();
  } finally {
    try {
      await reader.cancel();
    } catch {
      // 断流后 cancel 失败可忽略
    }
  }
}

/** JSON 安全解析：后端事件 data 都是 JSON，解析失败时不抛给调用方 */
export function parseSseJson<T>(data: string): T | null {
  try {
    return JSON.parse(data) as T;
  } catch {
    return null;
  }
}
