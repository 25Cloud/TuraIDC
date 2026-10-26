/**
 * AI 导购客户端（官网侧单例）。
 *
 * 只依赖 shared/runtime/ai：普通请求与 SSE 流式都走原生 fetch，
 * 刻意不经过 src/app/http/request.ts 的 axios 实例——那层会强制 `{code,data}` 信封、
 * 默认 18s 超时并在失败时整请求重放，套在 text/event-stream 上会直接断流。
 */
import { createAiClient } from "@turaidc/shared/runtime";

import { getClientToken } from "@/app/runtime/session";
import { i18n } from "@/locales";

// baseURL 与 src/app/http/request.ts 同一取值口径：环境变量决定，去掉尾斜杠
const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || "")
  .trim()
  .replace(/\/+$/, "");

/**
 * 官网 AI 导购客户端。
 *
 * - prefix 用 shared 默认的 `/v2/client/ai`（官网是用户端渠道）；
 * - token 取 `client_token`，与控制台同源会话，未登录时为 null，后端按 401 拒绝；
 * - locale 写入 X-Locale，让后端业务提示与界面语言一致。
 */
export const aiClient = createAiClient({
  baseURL: apiBaseUrl,
  getToken: () => getClientToken(),
  locale: () => i18n.global.locale.value,
});
