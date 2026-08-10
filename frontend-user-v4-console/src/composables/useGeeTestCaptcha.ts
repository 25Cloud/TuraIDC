import { onBeforeUnmount, onMounted, ref } from 'vue';

import { clientAuthApi } from '@/api/auth';
import { resolveApiProxyUrl } from '@/utils/apiOrigin';

interface GeeTestConfig {
  enabled: boolean;
  captcha_id: string;
  script_url?: string;
}

interface CaptchaInstance {
  onReady?: (callback: () => void) => void;
  onSuccess?: (callback: () => void) => void;
  onError?: (callback: (error: unknown) => void) => void;
  onClose?: (callback: () => void) => void;
  showCaptcha?: () => void;
  validate?: () => unknown;
  getValidate?: () => unknown;
  getVerifyResult?: () => unknown;
  reset?: () => void;
  destroy?: () => void;
}

declare global {
  interface Window {
    initGeetest4?: (options: Record<string, unknown>, callback: (instance: CaptchaInstance) => void) => void;
  }
}

let captchaConfigPromise: Promise<GeeTestConfig> | null = null;
let geetestScriptPromise: Promise<typeof window.initGeetest4> | null = null;

const defaultConfig: GeeTestConfig = {
  enabled: false,
  captcha_id: '',
  script_url: 'https://static.geetest.com/v4/gt4.js',
};

const captchaErrorMessages = new Map<string, string>([
  ['verification closed', '请先完成行为验证'],
  ['verification timeout', '行为验证超时，请重试'],
  ['verification_failed', '行为验证失败，请重试'],
  ['verification failed', '行为验证失败，请重试'],
  ['vaptcha is validating', '行为验证正在进行中，请稍后重试'],
  ['vaptcha initialization failed', '行为验证初始化失败，请稍后重试'],
  ['failed to load vaptcha core', '行为验证组件加载失败，请稍后重试'],
]);

function normalizeCaptchaError(error: unknown, fallback = '行为验证失败，请重试') {
  const rawMessage = error instanceof Error ? error.message : String(error || '');
  const message = rawMessage.trim();

  if (!message) {
    return new Error(fallback);
  }

  const normalized = message.toLowerCase();
  const mappedMessage = captchaErrorMessages.get(normalized);
  if (mappedMessage) {
    return new Error(mappedMessage);
  }

  if (/^\p{ASCII}+$/u.test(message) && /verification|vaptcha|geetest/i.test(message)) {
    return new Error(fallback);
  }

  return error instanceof Error ? error : new Error(message);
}

async function getCaptchaConfig() {
  if (!captchaConfigPromise) {
    captchaConfigPromise = clientAuthApi
      .captchaConfig()
      .then((response: any) => response.data || defaultConfig)
      .catch(() => defaultConfig);
  }

  return captchaConfigPromise;
}

function appendScriptCacheKey(src: string, cacheKey: string) {
  if (!cacheKey) {
    return src;
  }

  try {
    const url = new URL(src, window.location.href);
    url.searchParams.set('_captcha_key', cacheKey);
    return url.toString();
  } catch {
    return src;
  }
}

function loadGeeTestScript(src: string, cacheKey = '') {
  if (typeof window === 'undefined') {
    throw new TypeError('浏览器环境不可用');
  }

  const scriptKey = cacheKey || src;
  const existing = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');

  if (existing && existing.dataset.captchaKey !== scriptKey) {
    existing.remove();
    window.initGeetest4 = undefined;
    geetestScriptPromise = null;
  }

  if (window.initGeetest4 && (!existing || existing.dataset.captchaKey === scriptKey)) {
    return Promise.resolve(window.initGeetest4);
  }

  if (!geetestScriptPromise) {
    geetestScriptPromise = new Promise((resolve, reject) => {
      const current = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');
      if (current) {
        current.addEventListener('load', () => resolve(window.initGeetest4), { once: true });
        current.addEventListener('error', () => reject(new Error('GeeTest 脚本加载失败')), { once: true });
        return;
      }

      const script = document.createElement('script');
      script.src = appendScriptCacheKey(src, scriptKey);
      script.async = true;
      script.defer = true;
      script.dataset.geetestScript = 'gt4';
      script.dataset.captchaKey = scriptKey;
      script.onload = () => resolve(window.initGeetest4);
      script.onerror = () => reject(new Error('GeeTest 脚本加载失败'));
      document.head.appendChild(script);
    });
  }

  return geetestScriptPromise;
}

export function useGeeTestCaptcha(options: Record<string, unknown> = {}) {
  const loading = ref(false);
  const ready = ref(false);
  const enabled = ref(false);
  const initialized = ref(false);

  let captchaObj: CaptchaInstance | null = null;
  let initPromise: Promise<CaptchaInstance | null> | null = null;
  let pendingResolver: ((value: unknown) => void) | null = null;
  let pendingRejecter: ((error: Error) => void) | null = null;
  let componentUnmounted = false;

  const clearPending = () => {
    pendingResolver = null;
    pendingRejecter = null;
    loading.value = false;
  };

  const rejectPending = (error: Error) => {
    pendingRejecter?.(error);
    clearPending();
  };

  const readCaptchaResult = (instance: CaptchaInstance) => {
    if (typeof instance.getValidate === 'function') {
      return instance.getValidate();
    }

    if (typeof instance.getVerifyResult === 'function') {
      return instance.getVerifyResult();
    }

    return null;
  };

  const resolveSuccess = (instance: CaptchaInstance) => {
    const result = readCaptchaResult(instance);
    instance.reset?.();
    pendingResolver?.(result);
    clearPending();
  };

  const openCaptcha = (instance: CaptchaInstance) => {
    if (typeof instance.showCaptcha === 'function') {
      instance.showCaptcha();
      return;
    }

    if (typeof instance.validate === 'function') {
      Promise.resolve(instance.validate())
        .then(() => resolveSuccess(instance))
        .catch((error: unknown) => {
          rejectPending(normalizeCaptchaError(error));
        });
      return;
    }

    rejectPending(new Error('行为验证组件版本不兼容，请刷新页面后重试'));
  };

  const initCaptcha = async () => {
    if (componentUnmounted) {
      return null;
    }

    const config = await getCaptchaConfig();
    enabled.value = Boolean(config.enabled && config.captcha_id);
    initialized.value = true;

    if (!enabled.value) {
      return null;
    }

    if (captchaObj) {
      return captchaObj;
    }

    if (initPromise) {
      return initPromise;
    }

    const scriptUrl = resolveApiProxyUrl(
      config.script_url || defaultConfig.script_url || '',
      import.meta.env.VITE_API_BASE_URL,
    );
    const initGeetest4 = await loadGeeTestScript(scriptUrl, config.captcha_id);
    initPromise = new Promise((resolve, reject) => {
      try {
        initGeetest4?.(
          {
            captchaId: config.captcha_id,
            product: 'bind',
            language: 'zho',
            ...options,
          },
          (instance) => {
            captchaObj = instance;
            const markReady = () => {
              ready.value = true;
              resolve(instance);
            };

            if (typeof instance.onReady === 'function') {
              instance.onReady(markReady);
            } else {
              markReady();
            }

            instance.onSuccess?.(() => resolveSuccess(instance));
            instance.onError?.((error) => {
              rejectPending(normalizeCaptchaError(error));
            });
            instance.onClose?.(() => {
              rejectPending(new Error('请先完成行为验证'));
            });
          },
        );
      } catch (error) {
        reject(normalizeCaptchaError(error, '行为验证初始化失败，请稍后重试'));
      }
    });

    return initPromise;
  };

  const verify = async ({ required = false } = {}) => {
    const instance = await initCaptcha();
    if (!enabled.value) {
      if (required) {
        throw new Error('行为验证当前不可用，请稍后重试');
      }
      return null;
    }

    if (!instance || !ready.value) {
      throw new Error('行为验证组件初始化中，请稍后重试');
    }

    loading.value = true;

    return new Promise((resolve, reject) => {
      pendingResolver = resolve;
      pendingRejecter = reject;

      try {
        openCaptcha(instance);
      } catch (error) {
        rejectPending(normalizeCaptchaError(error, '行为验证打开失败'));
      }
    });
  };

  const runWithCaptcha = async <T>(callback: (captcha: unknown) => Promise<T>, options = {}) => {
    const captcha = await verify(options);
    return callback(captcha);
  };

  onMounted(() => {
    initCaptcha().catch(() => {
      initialized.value = true;
    });
  });

  onBeforeUnmount(() => {
    componentUnmounted = true;
    rejectPending(new Error('行为验证已取消'));
    captchaObj?.destroy?.();
  });

  return {
    enabled,
    initialized,
    loading,
    ready,
    verify,
    runWithCaptcha,
  };
}
