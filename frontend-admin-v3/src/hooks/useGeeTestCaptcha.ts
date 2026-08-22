import { onScopeDispose, ref } from 'vue';

import { request } from '@/utils/request';

interface GeeTestConfig {
  enabled: boolean;
  captcha_id: string;
  script_url?: string;
  provider?: string;
  cache_key?: string;
}

interface CaptchaValidation {
  isOffline?: boolean;
  [key: string]: unknown;
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

type GeeTestInitializer = NonNullable<Window['initGeetest4']>;

declare global {
  interface Window {
    initGeetest4?: (options: Record<string, unknown>, callback: (instance: CaptchaInstance) => void) => void;
    __TURA_GEETEST_FALLBACK__?: boolean;
  }
}

const defaultConfig: GeeTestConfig = {
  enabled: false,
  captcha_id: '',
  script_url: '',
};

const captchaConfigPromises = new Map<string, Promise<GeeTestConfig>>();
let geetestScriptPromise: Promise<GeeTestInitializer> | null = null;

/** 加载指定业务入口的人机验证公开配置并复用进行中的请求。 */
async function getCaptchaConfig(configUrl: string) {
  if (!captchaConfigPromises.has(configUrl)) {
    const configPromise = request
      .get<GeeTestConfig>({ url: configUrl })
      .then((response: unknown) => {
        const config = { ...defaultConfig, ...(response as GeeTestConfig) };
        // 未启用时返回配置，由调用方跳过验证；只有启用但配置不完整才阻断登录。
        if (config.enabled && !config.captcha_id) {
          throw new Error('人机验证插件配置不完整');
        }

        return config;
      })
      .catch((error: unknown) => {
        captchaConfigPromises.delete(configUrl);
        const message = error instanceof Error ? error.message : '人机验证配置请求失败';
        throw new Error(`人机验证配置加载失败：${message}`);
      });
    captchaConfigPromises.set(configUrl, configPromise);
  }

  return captchaConfigPromises.get(configUrl) as Promise<GeeTestConfig>;
}

/** 将后端脚本地址解析为浏览器可加载的绝对地址。 */
function resolveScriptUrl(src: string) {
  const raw = src.trim();
  if (!raw) return '';

  try {
    const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '').trim();
    if (/^\/(?!\/)/.test(raw) && apiBaseUrl) {
      const apiUrl = new URL(apiBaseUrl);
      return `${apiUrl.origin}${raw}`;
    }

    return new URL(raw, apiBaseUrl || window.location.origin).toString();
  } catch {
    return raw;
  }
}

/** 为脚本地址追加后端下发的配置隔离键。 */
function appendScriptCacheKey(src: string, cacheKey: string) {
  try {
    const url = new URL(src);
    url.searchParams.set('_captcha_key', cacheKey);
    return url.toString();
  } catch {
    return src;
  }
}

/** 只加载当前 provider 的代理脚本，禁止跨供应商回退。 */
function loadGeeTestScript(src: string, cacheKey: string): Promise<GeeTestInitializer> {
  if (typeof window === 'undefined') {
    return Promise.reject(new Error('浏览器环境不可用'));
  }

  const scriptUrl = appendScriptCacheKey(resolveScriptUrl(src), cacheKey);
  if (!scriptUrl) {
    return Promise.reject(new Error('GeeTest 脚本地址为空'));
  }

  const existing = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');
  if (existing && existing.dataset.captchaKey !== cacheKey) {
    existing.remove();
    window.initGeetest4 = undefined;
    geetestScriptPromise = null;
  }

  if (window.initGeetest4 && (!existing || existing.dataset.captchaKey === cacheKey)) {
    return Promise.resolve(window.initGeetest4 as GeeTestInitializer);
  }

  if (!geetestScriptPromise) {
    geetestScriptPromise = new Promise<GeeTestInitializer>((resolve, reject) => {
      const script = document.createElement('script');
      script.src = scriptUrl;
      script.async = true;
      script.defer = true;
      script.dataset.geetestScript = 'gt4';
      script.dataset.captchaKey = cacheKey;
      const timeout = window.setTimeout(() => {
        script.remove();
        reject(new Error(`GeeTest 脚本加载超时：${scriptUrl}`));
      }, 15000);
      script.onload = () => {
        window.clearTimeout(timeout);
        if (window.__TURA_GEETEST_FALLBACK__) {
          reject(new Error(`GeeTest 代理脚本不可用：${scriptUrl}`));
          return;
        }

        if (typeof window.initGeetest4 !== 'function') {
          reject(new Error(`GeeTest 脚本未提供初始化方法：${scriptUrl}`));
          return;
        }

        resolve(window.initGeetest4 as GeeTestInitializer);
      };
      script.onerror = () => {
        window.clearTimeout(timeout);
        reject(new Error(`GeeTest 脚本加载失败：${scriptUrl}`));
      };
      document.head.appendChild(script);
    }).catch((error: unknown) => {
      geetestScriptPromise = null;
      const failed = document.querySelector<HTMLScriptElement>('script[data-geetest-script="gt4"]');
      failed?.remove();
      window.initGeetest4 = undefined;
      window.__TURA_GEETEST_FALLBACK__ = false;
      throw error;
    });
  }

  return geetestScriptPromise;
}

/**
 * 管理端极验弹窗验证。用于插件测试：真人完成一次行为验证后，
 * 把 getValidate() 结果交给后端走完整验证链路。
 */
export function useGeeTestCaptcha(configUrl = '/v2/client/auth/captcha-config') {
  const loading = ref(false);

  let captchaObj: CaptchaInstance | null = null;
  let initPromise: Promise<CaptchaInstance | null> | null = null;
  let initRejecter: ((error: Error) => void) | null = null;
  let pendingResolver: ((value: unknown) => void) | null = null;
  let pendingRejecter: ((error: Error) => void) | null = null;
  let verifyPromise: Promise<unknown> | null = null;
  let destroyed = false;

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
    const result = readCaptchaResult(instance) as CaptchaValidation | null;
    instance.reset?.();
    if (result && result.isOffline === true) {
      pendingRejecter?.(new Error('人机验证服务不可用，请稍后重试'));
      clearPending();
      return;
    }
    pendingResolver?.(result);
    clearPending();
  };

  /** 初始化当前验证码实例并等待其进入可交互状态。 */
  const initCaptcha = async (): Promise<CaptchaInstance | null> => {
    const config = await getCaptchaConfig(configUrl);

    if (destroyed) {
      throw new Error('行为验证组件已卸载');
    }

    if (!config.enabled || !config.captcha_id) {
      throw new Error('行为验证当前不可用，请检查人机验证插件配置');
    }

    if (captchaObj) {
      return captchaObj;
    }

    if (initPromise) {
      return initPromise;
    }

    const scriptUrl = resolveScriptUrl(config.script_url || '');
    const cacheKey = config.cache_key || `${config.provider || 'captcha'}:${config.captcha_id}`;
    if (!scriptUrl) {
      throw new Error('人机验证脚本地址为空，请联系管理员检查配置');
    }

    let initGeetest4: typeof window.initGeetest4;
    try {
      initGeetest4 = await loadGeeTestScript(scriptUrl, cacheKey);
      if (destroyed) {
        throw new Error('行为验证组件已卸载');
      }
    } catch (error) {
      throw error instanceof Error ? error : new Error('人机验证脚本加载失败');
    }
    const currentInitPromise = new Promise<CaptchaInstance | null>((resolve, reject) => {
      initRejecter = reject;
      try {
        initGeetest4?.(
          {
            captchaId: config.captcha_id,
            product: 'bind',
            language: 'zho',
          },
          (instance) => {
            if (destroyed) {
              reject(new Error('行为验证组件已卸载'));
              return;
            }

            captchaObj = instance;
            const markReady = () => {
              initRejecter = null;
              resolve(instance);
            };

            if (typeof instance.onReady === 'function') {
              instance.onReady(markReady);
            } else {
              markReady();
            }

            instance.onSuccess?.(() => resolveSuccess(instance));
            instance.onError?.((error) => {
              rejectPending(new Error(error instanceof Error ? error.message : String(error || '行为验证失败')));
            });
            instance.onClose?.(() => {
              rejectPending(new Error('请先完成行为验证'));
            });
          },
        );
      } catch (error) {
        initRejecter = null;
        reject(error instanceof Error ? error : new Error('行为验证初始化失败'));
      }
    });

    initPromise = currentInitPromise;
    try {
      return await currentInitPromise;
    } catch (error) {
      if (initPromise === currentInitPromise) {
        initPromise = null;
      }
      throw error;
    }
  };

  /** 打开验证弹窗；并发调用共享同一个验证 Promise。 */
  const verify = async () => {
    if (verifyPromise) {
      return verifyPromise;
    }

    const currentVerifyPromise = (async () => {
      // 配置和脚本首次加载期间也要反馈提交状态，避免用户重复点击。
      loading.value = true;

      try {
        const config = await getCaptchaConfig(configUrl);
        if (!config.enabled) {
          return null;
        }

        const instance = await initCaptcha();
        if (!instance) {
          throw new Error('行为验证组件初始化失败，请稍后重试');
        }

        return await new Promise((resolve, reject) => {
          pendingResolver = resolve;
          pendingRejecter = reject;

          try {
            if (typeof instance.showCaptcha === 'function') {
              instance.showCaptcha();
            } else if (typeof instance.validate === 'function') {
              Promise.resolve(instance.validate())
                .then(() => resolveSuccess(instance))
                .catch((error: unknown) => {
                  rejectPending(new Error(error instanceof Error ? error.message : String(error || '行为验证失败')));
                });
            } else {
              rejectPending(new Error('行为验证组件版本不兼容，请刷新页面后重试'));
            }
          } catch (error) {
            rejectPending(new Error(error instanceof Error ? error.message : '行为验证打开失败'));
          }
        });
      } finally {
        loading.value = false;
      }
    })();

    verifyPromise = currentVerifyPromise;
    try {
      return await currentVerifyPromise;
    } finally {
      if (verifyPromise === currentVerifyPromise) {
        verifyPromise = null;
      }
    }
  };

  const cleanup = () => {
    destroyed = true;
    initRejecter?.(new Error('行为验证组件已卸载'));
    initRejecter = null;
    if (captchaObj) {
      captchaObj.destroy?.();
      captchaObj = null;
    }
    verifyPromise = null;
    initPromise = null;
    rejectPending(new Error('行为验证组件已卸载'));
  };

  onScopeDispose(cleanup);

  return {
    loading,
    verify,
  };
}
