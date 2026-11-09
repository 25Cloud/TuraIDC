import { installDemoTransport } from '@turaidc/shared/demo';
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
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';

import { getClientToken, removeClientToken, setClientToken } from '@/app/runtime/session';
import { i18n } from '@/locales';
import router from '@/router';
import type { ClientAccountPolicyItem } from '@/types/client';
import { firstPolicyItemRoute, isAccountPolicyItem } from '@/utils/accountPolicy';
import { toUserMessage } from '@/utils/userMessage';

const DEFAULT_TIMEOUT = 18000;
const SAFE_TIMEOUT = 16000;
const WEAK_NETWORK_TIMEOUT_BOOST = 9000;

/** 账户策略未达标的业务码（后端 AccountPolicyService::assertSatisfied） */
const ACCOUNT_POLICY_REQUIRED_CODE = 40301;

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

/** 40301 对话框同一时间只开一个：并发的写请求不该叠出多个弹窗 */
let accountPolicyDialogOpen = false;

/**
 * 账户策略拦截的结构化载荷（后端 BusinessException 第四参数）。
 *
 * `scope` 是判别位：只有 `account_policy` 才是本功能的拦截；同样用 40301 的
 * 「服务面板访问凭证缺失/失效」（ServiceConsoleAreaService）没有这个字段，
 * 必须继续走普通错误提示，不能弹补齐对话框。
 */
interface AccountPolicyErrorPayload {
  scope?: unknown;
  scene?: unknown;
  missing_items?: unknown;
}

/** 从错误响应体里取出账户策略缺失项；不是账户策略拦截时返回 null */
function resolveAccountPolicyMissingItems(payload: unknown): ClientAccountPolicyItem[] | null {
  const data = (payload as { data?: AccountPolicyErrorPayload } | null | undefined)?.data;

  if (!data || typeof data !== 'object' || data.scope !== 'account_policy') {
    return null;
  }

  return Array.isArray(data.missing_items) ? data.missing_items.filter(isAccountPolicyItem) : [];
}

/**
 * 账户策略未达标：弹对话框 + 直达按钮，而不是只弹一个裸 toast。
 *
 * 按钮完全按错误响应里的 `data.missing_items` 决定，**不读**内存里的 auth/info 快照：
 * 用户可能刚被策略收紧、info 还没刷新，此时只有错误响应是可信的。
 */
function promptAccountPolicy(message: string, missingItems: ClientAccountPolicyItem[]): void {
  if (accountPolicyDialogOpen) {
    return;
  }

  accountPolicyDialogOpen = true;

  const header = i18n.global.t('accountPolicy.dialogTitle');
  const body = message || i18n.global.t('accountPolicy.fallbackMessage');
  const target = firstPolicyItemRoute(missingItems);
  const release = () => {
    accountPolicyDialogOpen = false;
  };

  if (!target) {
    DialogPlugin.alert({
      header,
      body,
      confirmBtn: i18n.global.t('accountPolicy.gotIt'),
      onClose: release,
      onClosed: release,
    });

    return;
  }

  const dialog = DialogPlugin.confirm({
    header,
    body,
    confirmBtn: i18n.global.t(
      missingItems[0] === 'verification' ? 'accountPolicy.goVerification' : 'accountPolicy.goBind',
    ),
    cancelBtn: i18n.global.t('accountPolicy.gotIt'),
    theme: 'warning',
    onConfirm() {
      dialog.hide();
      void router.push(target);
    },
    onClose: release,
    onClosed: release,
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

// 演示站构建：请求不出网，全部由打包进来的示例数据应答（见 docs/internal/designs/frontend/demo-site-design.md）
if (import.meta.env.VITE_DEMO_MODE === 'true') {
  installDemoTransport(httpClient, {
    app: 'console',
    loadFixtures: () => import('@demo-fixtures/console.json'),
  });
  setClientToken('demo-token');
}

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
    // 后端据此本地化校验与业务消息（zh_CN / zh_TW / zh_HK / en_US）
    runtimeConfig.headers['X-Locale'] = i18n.global.locale.value;
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
      const msg = toUserMessage(res.message, i18n.global.t('common.errors.requestFailed'));
      const captchaRequired = Boolean(res.data?.captcha_required);

      if (res.code === 40100) {
        redirectLogin();
        return Promise.reject(new Error(msg));
      }

      // 账户策略拦截（40301 + data.scope=account_policy）：对话框带直达按钮，不落成裸 toast。
      // 其他 40301（例如服务面板访问凭证失效）没有 scope，继续走下面的普通错误提示。
      const policyMissingItems =
        res.code === ACCOUNT_POLICY_REQUIRED_CODE ? resolveAccountPolicyMissingItems(res) : null;

      if (policyMissingItems !== null) {
        promptAccountPolicy(msg, policyMissingItems);

        const policyErr = new Error(msg) as RuntimeHandledError;
        policyErr.__handled = true;
        policyErr.response = { ...response, data: res };
        policyErr.config = response.config;
        return Promise.reject(policyErr);
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

    // 业务码与结构化载荷优先于 HTTP 状态判断：40301 会以 HTTP 403 回来
    // （见 ApiResponseBuilder::httpStatusForErrorCode），但是否走账户策略对话框取决于
    // `data.scope === 'account_policy'`；没有 scope 的 40301 仍按普通错误提示处理。
    const errorResponseData = (error.response?.data ?? null) as { code?: unknown; message?: unknown } | null;
    const businessCode = Number(errorResponseData?.code ?? 0);
    const policyMissingItems =
      businessCode === ACCOUNT_POLICY_REQUIRED_CODE ? resolveAccountPolicyMissingItems(error.response?.data) : null;

    if (policyMissingItems !== null) {
      const policyMessage = toUserMessage(errorResponseData?.message, i18n.global.t('common.errors.requestFailed'));
      promptAccountPolicy(policyMessage, policyMissingItems);

      const policyErr = new Error(policyMessage) as RuntimeHandledError;
      policyErr.__handled = true;
      policyErr.response = error.response;
      policyErr.config = error.config;
      return Promise.reject(policyErr);
    }

    try {
      return await retrySafeRequest((config) => httpClient(config), error);
    } catch (retryError) {
      if (retryError !== error) {
        return Promise.reject(retryError);
      }
    }

    let msg = toUserMessage(error.response?.data?.message, i18n.global.t('common.errors.networkError'));

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      msg = i18n.global.t('common.errors.networkOffline');
    } else if (
      error.code === 'ECONNABORTED' ||
      String(error.message || '')
        .toLowerCase()
        .includes('timeout')
    ) {
      msg = i18n.global.t('common.errors.timeout');
    }

    if (error.response?.status === 422) {
      const errors = extractValidationErrors(error.response?.data);
      const trustedErrors = errors.map((item) => toUserMessage(item, '')).filter(Boolean);
      if (trustedErrors.length > 0) {
        msg = trustedErrors.join(', ');
      } else {
        // 业务异常（BusinessException）也走 422，message 里有真实原因，优先展示
        const serverMsg = toUserMessage(error.response?.data?.message, '');
        msg = serverMsg || i18n.global.t('common.errors.invalidParams');
      }
    }

    if (error.response?.status === 429) {
      const retryAfter = Number(error.response?.headers?.['retry-after'] || 0);
      msg =
        retryAfter > 0
          ? i18n.global.t('common.errors.rateLimitedRetry', { seconds: retryAfter })
          : i18n.global.t('common.errors.rateLimited');
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
  get: <T = unknown>(_config: { url: string; params?: unknown }) => Promise<T>;
  post: <T = unknown>(_config: { url: string; data?: unknown }) => Promise<T>;
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
