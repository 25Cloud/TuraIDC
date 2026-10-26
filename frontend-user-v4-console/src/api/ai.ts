import { createAiClient } from '@turaidc/shared/runtime';

import { getClientToken } from '@/app/runtime/session';
import { i18n } from '@/locales';
import { apiBaseUrl } from '@/utils/request';

/**
 * 控制台 AI 客户端单例。
 *
 * - baseURL 复用 request.ts 的 apiBaseUrl，不写死域名；
 * - token 与 axios 拦截器同源（client_token），退出登录后自然不再携带；
 * - X-Locale 跟随当前 vue-i18n 语言，后端据此返回本地化提示；
 * - 前缀走 createAiClient 的默认值 /v2/client/ai，普通请求与 SSE 流式共用同一份鉴权头。
 *
 * 注意：AI 端点全部走原生 fetch（SSE 无法用 axios），所以这里不经过 request.ts 的拦截器。
 */
export const aiClient = createAiClient({
  baseURL: apiBaseUrl,
  getToken: () => getClientToken(),
  locale: () => i18n.global.locale.value,
});

export default aiClient;
