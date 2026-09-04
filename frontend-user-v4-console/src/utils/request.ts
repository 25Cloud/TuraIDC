import {
  attachSafeRequestDedupe,
  buildSafeRequestKey,
  createRequestId,
  extractValidationErrors,
  getNetworkProfile,
  isSafeRequest,
  isWriteRequest,
  retrySafeRequest,
} from '@turaidc/shared/runtime';
import type { AxiosRequestConfig, InternalAxiosRequestConfig } from 'axios';
import axios from 'axios';
import { MessagePlugin } from 'tdesign-vue-next';

import { getClientToken, removeClientToken } from '@/app/runtime/session';
import router from '@/router';
import { toUserMessage } from '@/utils/userMessage';

const DEFAULT_TIMEOUT = 18000;
const SAFE_TIMEOUT = 16000;
const WEAK_NETWORK_TIMEOUT_BOOST = 9000;

interface ClientConfigExtras {
  safeRetryCount?: number;
  dedupeSafeRequest?: boolean;
  retrySafeRequest?: boolean;
  silentError?: boolean;
  silent?: boolean;
  __safeRequest?: boolean;
  __safeRequestKey?: string;
  __safeRetryCount?: number;
  __skipSafeDedupe?: boolean;
}

type ClientRequestConfig = AxiosRequestConfig & ClientConfigExtras;
type ClientRuntimeRequestConfig = InternalAxiosRequestConfig & ClientConfigExtras;

type SharedSafeRequestConfig = ClientRuntimeRequestConfig & Record<string, unknown>;

interface RuntimeHandledError extends Error {
  __handled?: boolean;
  response?: unknown;
  config?: unknown;
}

let lastErrorMsg = '';
let lastErrorTimer: ReturnType<typeof setTimeout> | null = null;
let redirectingToLogin = false;

function showError(msg: string) {
  if (msg === lastErrorMsg) {
    return;
  }

  lastErrorMsg = msg;
  MessagePlugin.error(msg);
  if (lastErrorTimer) {
    clearTimeout(lastErrorTimer);
  }
  lastErrorTimer = setTimeout(() => {
    lastErrorMsg = '';
  }, 1000);
}

function redirectLogin() {
  if (redirectingToLogin) {
    return;
  }

  redirectingToLogin = true;
  removeClientToken();
  if (typeof window !== 'undefined') {
    window.localStorage.removeItem('client_user');
  }
  router.push('/client/login').finally(() => {
    redirectingToLogin = false;
  });
}

function hasExplicitTimeout(config: Partial<ClientRuntimeRequestConfig>) {
  return Number.isFinite(Number(config.timeout)) && Number(config.timeout) > 0;
}

function resolveTimeout(config: Partial<ClientRuntimeRequestConfig>, safeRequest: boolean) {
  if (hasExplicitTimeout(config)) {
    return Number(config.timeout);
  }

  if (!safeRequest) {
    return DEFAULT_TIMEOUT;
  }

  const profile = getNetworkProfile();
  return SAFE_TIMEOUT + (profile.isWeakConnection ? WEAK_NETWORK_TIMEOUT_BOOST : 0);
}

function canDedupeSafeRequest(config: Partial<ClientRuntimeRequestConfig>, safeRequest: boolean) {
  return Boolean(safeRequest && config.dedupeSafeRequest !== false && !config.signal && !config.cancelToken);
}

const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '')
  .trim()
  .replace(/\/+$/, '');

if (!apiBaseUrl) {
  throw new Error('VITE_API_BASE_URL 必须配置');
}

/** 暴露给 iframe 等无法携带 Authorization 头的场景拼接完整地址 */
export { apiBaseUrl };

const httpClient = axios.create({
  baseURL: apiBaseUrl,
  timeout: DEFAULT_TIMEOUT,
  headers: {
    Accept: 'application/json',
  },
});

httpClient.interceptors.request.use(
  (config) => {
    const runtimeConfig = config as ClientRuntimeRequestConfig;
    const method = String(runtimeConfig.method || 'get').toLowerCase();
    const safeRequest = isSafeRequest(method);
    const writeRequest = isWriteRequest(method);
    const requestUrl = String(runtimeConfig.url || '');
    const isPublicSiteGetRequest = method === 'get' && requestUrl.startsWith('/v2/site/');
    const token = getClientToken();

    runtimeConfig.silentError = Boolean(runtimeConfig.silentError || runtimeConfig.silent);
    runtimeConfig.__safeRequest = safeRequest;
    runtimeConfig.timeout = resolveTimeout(runtimeConfig, safeRequest);

    if (token && !isPublicSiteGetRequest) {
      runtimeConfig.headers.Authorization = `Bearer ${token}`;
    }

    if (writeRequest && !runtimeConfig.headers['Content-Type']) {
      runtimeConfig.headers['Content-Type'] = 'application/json';
    }

    if (writeRequest) {
      runtimeConfig.headers['X-Request-Id'] = createRequestId();
    }

    if (canDedupeSafeRequest(runtimeConfig, safeRequest)) {
      const sharedConfig = runtimeConfig as SharedSafeRequestConfig;
      sharedConfig.__safeRequestKey = buildSafeRequestKey(sharedConfig);
      attachSafeRequestDedupe(sharedConfig, httpClient.defaults.adapter);
    }

    return runtimeConfig;
  },
  (error) => Promise.reject(error),
);

httpClient.interceptors.response.use(
  (response) => {
    const res = response.data;
    const runtimeConfig = response.config as ClientRuntimeRequestConfig;

    if (res.code !== 0) {
      const msg = toUserMessage(res.message, '请求失败');
      const captchaRequired = Boolean(res.data?.captcha_required);

      if (res.code === 40100) {
        redirectLogin();
        return Promise.reject(new Error(msg));
      }

      const shownByInterceptor = !captchaRequired && !runtimeConfig?.silentError;
      if (shownByInterceptor) {
        showError(msg);
      }

      const err = new Error(msg) as RuntimeHandledError;
      // __handled 表示提示已经由拦截器或验证码流程接管，调用方据此避免重复弹窗。
      err.__handled = shownByInterceptor || captchaRequired;
      err.response = { ...response, data: res };
      err.config = response.config;
      return Promise.reject(err);
    }

    return res;
  },
  async (error) => {
    if (axios.isCancel(error) || error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError') {
      return Promise.reject(error);
    }

    try {
      return await retrySafeRequest((config) => httpClient(config), error);
    } catch (retryError) {
      if (retryError !== error) {
        return Promise.reject(retryError);
      }
    }

    let msg = toUserMessage(error.response?.data?.message, '网络异常');

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      msg = '网络连接已断开，请检查后重试';
    } else if (
      error.code === 'ECONNABORTED' ||
      String(error.message || '')
        .toLowerCase()
        .includes('timeout')
    ) {
      msg = '请求超时，请检查网络后重试';
    }

    if (error.response?.status === 422) {
      const errors = extractValidationErrors(error.response?.data);
      const trustedErrors = errors.map((item) => toUserMessage(item, '')).filter(Boolean);
      if (trustedErrors.length > 0) {
        msg = trustedErrors.join(', ');
      } else {
        // 业务异常（BusinessException）也走 422，message 里有真实原因，优先展示
        const serverMsg = toUserMessage(error.response?.data?.message, '');
        msg = serverMsg || '参数填写有误，请检查后重试';
      }
    }

    if (error.response?.status === 429) {
      const retryAfter = Number(error.response?.headers?.['retry-after'] || 0);
      msg = retryAfter > 0 ? `请求过于频繁，请 ${retryAfter} 秒后重试` : '请求过于频繁，请稍后重试';
    }

    if (error.response?.status === 401) {
      redirectLogin();
      return Promise.reject(error);
    }

    const runtimeConfig = (error.config || {}) as ClientRuntimeRequestConfig;
    const shownByInterceptor = !runtimeConfig.silentError;
    if (shownByInterceptor) {
      showError(msg);
    }

    const err = new Error(msg) as RuntimeHandledError;
    // silentError 的请求由调用方自行提示，这里不能谎报已处理，否则错误会被静默吞掉。
    err.__handled = shownByInterceptor;
    err.response = error.response;
    err.config = error.config;
    return Promise.reject(err);
  },
);

export default httpClient;

interface StarterRequestCompat {
  get: <T = unknown>(config: { url: string; params?: unknown }) => Promise<T>;
  post: <T = unknown>(config: { url: string; data?: unknown }) => Promise<T>;
}

export const request: StarterRequestCompat = {
  get<T = unknown>(config: { url: string; params?: unknown }) {
    const requestConfig: ClientRequestConfig = { params: config.params, silentError: true };
    return httpClient.get<T>(config.url, requestConfig) as Promise<T>;
  },
  post<T = unknown>(config: { url: string; data?: unknown }) {
    const requestConfig: ClientRequestConfig = { silentError: true };
    return httpClient.post<T>(config.url, config.data, requestConfig) as Promise<T>;
  },
};
